<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Game\Engagement\Relics\RelicCatalog;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * The relic catalog of the universe the admin acts in: slots with their main stats, substat maxima, rarities, sets and caps
 * as tables of number inputs (plus the whole catalog as JSON for bulk edits), saved per universe in eng_relic_config, and
 * a reset to the defaults of the code. The scalar settings (drop chance, inventory size, cooldown, power, extra sources)
 * are in the universe settings, category Relics. Every value is validated and clamped on save (RelicCatalog::sanitize()).
 */
class RelicsController extends AbstractAdminController
{
    private const LOG_ROWS = 30;

    /** The catalog form; `saved` and `reset` show a notice. */
    #[GameRoute('admin', 'admin/relics', 'relics')]
    public function show(): Response
    {
        $uni = (int) $this->universe()->getEmulated();
        $config = $this->catalog()->config($uni);
        $notice = $this->input()->int('saved') !== 0 ? 'rl_adm_saved' : ($this->input()->int('reset') !== 0 ? 'rl_adm_reset_done' : null);

        return $this->renderForm($config, [], $notice);
    }

    /**
     * Saves the catalog from the tables (or from the JSON text when `mode` is json). Errors show the form again with the
     * entered values (clamped) and nothing is stored.
     */
    #[GameRoute('admin', 'admin/relics/save', 'relics', 'save', ['POST'])]
    public function save(): Response
    {
        $uni = (int) $this->universe()->getEmulated();
        $post = $this->state->requestPost ?? [];
        if (($post['mode'] ?? '') === 'json') {
            $raw = json_decode((string) ($post['json'] ?? ''), true);
            if (!is_array($raw)) {
                return $this->renderForm($this->catalog()->config($uni), ['rl_err_json'], null);
            }
        } else {
            $raw = $this->rawFromPost($post);
        }
        $errors = $this->catalog()->save($uni, $raw, $this->state->runtime->timestamp);
        if ($errors !== []) {
            return $this->renderForm(RelicCatalog::sanitize($raw)[0], $errors, null);
        }

        return $this->redirectToRoute('admin_relics_show', ['saved' => 1]);
    }

    /** Deletes the stored catalog: the universe uses the defaults of the code again. */
    #[GameRoute('admin', 'admin/relics/reset', 'relics', 'reset', ['POST'])]
    public function reset(): Response
    {
        $this->catalog()->reset((int) $this->universe()->getEmulated());

        return $this->redirectToRoute('admin_relics_show', ['reset' => 1]);
    }

    private function catalog(): RelicCatalog
    {
        return new RelicCatalog($this->db, $this->configs, $this->state);
    }

    /**
     * The catalog structure of the form post (every section is present, so nothing falls back to a default: an unchecked
     * box or an empty value disables, the sanitising clamps).
     *
     * @param array<mixed> $post
     * @return array<string, mixed>
     */
    private function rawFromPost(array $post): array
    {
        $raw = ['slots' => [], 'subs' => [], 'rarities' => [], 'reforge_base' => [], 'sets' => [], 'caps' => []];
        foreach (array_keys(RelicCatalog::SLOTS) as $slot) {
            $raw['slots'][$slot] = is_array($post['slots'][$slot] ?? null) ? $post['slots'][$slot] : [];
        }
        foreach (RelicCatalog::STATS as $stat) {
            $row = is_array($post['subs'][$stat] ?? null) ? $post['subs'][$stat] : [];
            $raw['subs'][$stat] = ['max' => $row['max'] ?? 0, 'slots' => is_array($row['slots'] ?? null) ? $row['slots'] : []];
        }
        foreach ((array) ($post['rarities'] ?? []) as $id => $row) {
            $raw['rarities'][(int) $id] = is_array($row) ? $row : [];
        }
        $raw['reforge_base'] = is_array($post['reforge_base'] ?? null) ? $post['reforge_base'] : [];
        foreach ((array) ($post['sets'] ?? []) as $code => $row) {
            if (is_array($row)) {
                $raw['sets'][(string) $code] = [
                    'enabled' => isset($row['enabled']),
                    'name' => $row['name'] ?? '',
                    'b2' => array_values(is_array($row['b2'] ?? null) ? $row['b2'] : []),
                    'b4' => array_values(is_array($row['b4'] ?? null) ? $row['b4'] : []),
                ];
            }
        }
        $raw['caps'] = is_array($post['caps'] ?? null) ? $post['caps'] : [];

        return $raw;
    }

    /**
     * @param array<string, mixed> $config effective or entered (sanitised) catalog
     * @param list<string> $errors LNG keys
     */
    private function renderForm(array $config, array $errors, ?string $noticeKey): Response
    {
        $LNG = $this->state->LNG;
        $uni = (int) $this->universe()->getEmulated();
        $stats = [];
        foreach (RelicCatalog::STATS as $stat) {
            $stats[$stat] = (string) $LNG['rl_stat_' . $stat];
        }

        return $this->render('admin/RelicsPage.html.twig', [
            'relicsOn' => $this->catalog()->enabled($uni),
            'relicNotice' => $noticeKey === null ? null : $LNG[$noticeKey],
            'relicErrors' => array_map(static fn(string $key): string => $LNG[$key], $errors),
            'relicStats' => $stats,
            'relicSlots' => $this->slotColumns(),
            'relicMatrix' => $this->matrix($config),
            'relicSubs' => $this->subRows($config, $stats),
            'relicRarities' => $this->rarityRows($config),
            'relicReforgeBase' => $config['reforge_base'],
            'relicSets' => $this->setRows($config),
            'relicCaps' => $this->capRows($config, $stats),
            'relicJson' => json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'relicLog' => $this->logRows($uni),
        ]);
    }

    /** @return list<array{slot: int, label: string}> */
    private function slotColumns(): array
    {
        $columns = [];
        foreach (RelicCatalog::SLOTS as $slot => $code) {
            $columns[] = ['slot' => $slot, 'label' => (string) $this->state->LNG['rl_slot_' . $code]];
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array<int, float>> stat => slot => main stat maximum (0 = not possible)
     */
    private function matrix(array $config): array
    {
        $matrix = [];
        foreach (RelicCatalog::STATS as $stat) {
            foreach (array_keys(RelicCatalog::SLOTS) as $slot) {
                $matrix[$stat][$slot] = (float) ($config['slots'][$slot][$stat] ?? 0.0);
            }
        }

        return $matrix;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $stats
     * @return list<array<string, mixed>>
     */
    private function subRows(array $config, array $stats): array
    {
        $rows = [];
        foreach ($stats as $stat => $label) {
            $slots = [];
            foreach (array_keys(RelicCatalog::SLOTS) as $slot) {
                $slots[$slot] = in_array($slot, $config['subs'][$stat]['slots'], true);
            }
            $rows[] = ['stat' => $stat, 'label' => $label, 'max' => $config['subs'][$stat]['max'], 'slots' => $slots];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function rarityRows(array $config): array
    {
        $rows = [];
        foreach ($config['rarities'] as $id => $row) {
            $rows[] = ['id' => $id, 'label' => (string) $this->state->LNG['rl_rarity_' . RelicCatalog::RARITIES[$id]]] + $row;
        }

        return $rows;
    }

    /**
     * Sets with their bonus lines, each list padded with empty lines to at least 4 (6 at most are stored).
     *
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function setRows(array $config): array
    {
        $pad = static fn(array $lines): array => array_merge($lines, array_fill(0, max(0, 4 - count($lines)), ['stat' => '', 'value' => '']));
        $rows = [];
        foreach ($config['sets'] as $code => $set) {
            $rows[] = [
                'code' => $code,
                'label' => RelicCatalog::setLabel($this->state->LNG, $config, $code),
                'enabled' => $set['enabled'],
                'name' => $set['name'],
                'b2' => $pad($set['b2']),
                'b4' => $pad($set['b4']),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $stats
     * @return list<array{stat: string, label: string, value: float}>
     */
    private function capRows(array $config, array $stats): array
    {
        $rows = [];
        foreach ($stats as $stat => $label) {
            $rows[] = ['stat' => $stat, 'label' => $label, 'value' => $config['caps'][$stat]];
        }

        return $rows;
    }

    /**
     * The latest relic log lines of the universe (finds, equips, dismantles, reforges).
     *
     * @return list<array<string, mixed>>
     */
    private function logRows(int $uni): array
    {
        $rows = $this->db->select(
            'SELECT l.`relic_id`, l.`action`, l.`detail`, l.`created_at`, u.`username` FROM %%ENG_RELIC_LOG%% l
            LEFT JOIN %%USERS%% u ON u.`id` = l.`user_id` WHERE l.`universe` = :uni ORDER BY l.`id` DESC LIMIT ' . self::LOG_ROWS . ';',
            [':uni' => $uni],
        );
        foreach ($rows as &$row) {
            $row['time'] = $this->viewHelpers()->formatDate($this->state->LNG['php_tdformat'], (int) $row['created_at'], $this->state->USER['timezone']);
        }

        return $rows;
    }
}
