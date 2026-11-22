<?php

/**
 * Strips private data from stored Rust combat reports. Until now the start state of a report (`rounds[0]`) carried the
 * complete users row (last IP, registration IP, Discord hook, queues, ...) and the complete fleet row of every
 * participant, readable in the page source by every participant of the fight. The report pages only need the owner's
 * id, name and combat techs and the start coordinates of the fleet; everything else is removed (same whitelist as
 * BattleHandler::scrubStartState()). Reports of the PHP engine never held user rows and are not touched.
 *
 * Idempotent (a scrubbed report is rewritten to itself, i.e. not written at all), processed in batches of 200 rows by the
 * primary key. Self-contained CLI script, like the other PHP migrations (the upgrader copies it to the project root;
 * `php install/migrations/migration_1795351700.php` works too).
 */

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env');

$database = (new App\Game\Core\DatabaseConfig($root, $_SERVER['DATABASE_URL'] ?? null, $_SERVER['TABLE_PREFIX'] ?? null))->get();

/** whitelist of the start state: kept keys of the owner and of the fleet row */
$scrubStartState = static function (array $round0): array {
    $playerKeys = array_flip(['id', 'username', 'military_tech', 'shield_tech', 'defence_tech']);
    $fleetKeys = array_flip(['start_galaxy', 'start_system', 'start_planet', 'start_type']);
    foreach (['attackers', 'defenders'] as $side) {
        foreach ($round0[$side] ?? [] as $key => $fleet) {
            if (!is_array($fleet)) {
                continue;
            }
            if (isset($fleet['player']) && is_array($fleet['player'])) {
                $fleet['player'] = array_intersect_key($fleet['player'], $playerKeys);
            }
            if (isset($fleet['fleetDetail']) && is_array($fleet['fleetDetail'])) {
                $fleet['fleetDetail'] = array_intersect_key($fleet['fleetDetail'], $fleetKeys);
            }
            $round0[$side][$key] = $fleet;
        }
    }

    return $round0;
};

/** scrubs one stored report (gzip + base64 JSON); null when nothing changes or the row is unreadable */
$scrubReport = static function (string $stored) use ($scrubStartState): ?string {
    $compressed = base64_decode($stored, true);
    $json = $compressed === false ? false : @gzdecode($compressed);
    if ($json === false) {
        return null;
    }
    $report = json_decode($json, true);
    if (!is_array($report) || !isset($report['rounds'][0]) || !is_array($report['rounds'][0])) {
        return null;
    }
    $scrubbed = $scrubStartState($report['rounds'][0]);
    if ($scrubbed === $report['rounds'][0]) {
        return null;
    }
    $report['rounds'][0] = $scrubbed;
    $encoded = gzencode(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 9);

    return $encoded === false ? null : base64_encode($encoded);
};

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $database['host'], $database['port'] ?? '3306', $database['databasename']),
        $database['user'],
        $database['userpw'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $table = '`' . str_replace('`', '', $database['tableprefix']) . 'raports`';

    $select = $pdo->prepare("SELECT `rid`, `raport` FROM $table WHERE `battleengine` = 'rust' AND `rid` > :last ORDER BY `rid` LIMIT 200");
    $update = $pdo->prepare("UPDATE $table SET `raport` = :raport WHERE `rid` = :rid");
    $last = '';
    $seen = 0;
    $changed = 0;
    do {
        $select->execute([':last' => $last]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $last = $row['rid'];
            $seen++;
            $scrubbed = $scrubReport($row['raport']);
            if ($scrubbed !== null) {
                $update->execute([':raport' => $scrubbed, ':rid' => $row['rid']]);
                $changed++;
            }
        }
    } while (count($rows) === 200);
    echo sprintf('%d of %d rust combat reports were stripped of user and fleet rows', $changed, $seen), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
