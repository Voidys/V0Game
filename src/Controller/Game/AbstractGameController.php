<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use App\Game\Enum\PlanetType;
use App\Controller\GameControllerInterface;
use App\Controller\GuardsModule;
use App\Controller\RendersPages;
use App\Game\Core\DeviceLinks;
use App\Game\Core\EndOfRequest;
use DateTime;
use DateTimeZone;
use Exception;
use App\Game\Core\NumberAbbreviation;
use App\Game\Core\ResourceUpdate;
use App\Game\Tutorials\TutorialUtil;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base for the in-game controllers (/game/...).
 *
 * initialize() runs after the game is bootstrapped: it locks and updates the current player and
 * planet (resource production since the last request) unless $disableEcoSystem is set, and picks
 * the window (full page, or "ajax" for XHR requests).
 *
 * Pages render with RendersPages; the game layout gets the navigation (planet list, resources,
 * counters) for full pages and the player/clock settings for every non-ajax page.
 */
abstract class AbstractGameController implements GameControllerInterface, \App\Controller\ModuleGuard
{
    use \App\Game\Core\GameUtilities;

    use \App\Controller\GameServices;

    use GuardsModule;

    use RendersPages {
        render as private renderPage;
    }

    protected \App\Game\Core\DeviceLinks $deviceLinks;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setDeviceLinks(\App\Game\Core\DeviceLinks $deviceLinks): void
    {
        $this->deviceLinks = $deviceLinks;
    }

    /** popups requested by the modal loader (X-Game-Fragment, ?ajax=1) render as fragments (layout.ajax) */
    protected function setWindow(string $window): void
    {
        $this->window = $window === 'popup' && $this->state->runtime->ajax ? 'ajax' : $window;
    }

    protected static function templateArea(): string
    {
        return 'game';
    }

    /** Message page for a request over the in-game request budget (RateLimitListener): no page code runs, status 429 is set by the listener. */
    public function showRateLimited(int $retryAfter): never
    {
        $this->state->LNG->includeData(['INGAME']);
        $this->setWindow('full');
        $this->printMessage($this->state->LNG->format('rl_too_many_requests', $retryAfter));
    }

    protected function printPageError(string $message): never
    {
        // e.g. an inactive module: say what happened and offer the way back
        $this->printMessage($message, [
            ['url' => $this->router->generate('game_overview_show'), 'label' => $this->state->LNG['lm_overview']],
        ]);
    }

    /** resource calculation of the current request (null for ajax requests and $disableEcoSystem) */
    protected ?ResourceUpdate $ecoObj = null;

    /** true: skip the resource update of the current planet (pages that do not show resources) */
    protected $disableEcoSystem = false;

    /**
     * Runs before every in-game page. Full page loads (not XHR) lock the player row and then the current planet row (always in that
     * order, like research, empire and fleet code, to avoid deadlocks), recalculate the planet's resources up to now (ResourceUpdate::CalcResource,
     * including finished queues) from the locked rows and commit. Pages that set $disableEcoSystem skip this. XHR requests only select the ajax layout.
     */
    public function initialize(): void
    {
        $this->trackDevice();
        // pages (also in XHR/fragment mode) debit and credit through it; only the recalculation below is skipped for XHR
        $this->ecoObj = new ResourceUpdate($this->db, $this->configs, $this->state);
        if (!$this->state->runtime->ajax) {
            $this->setWindow('full');
            if (!$this->disableEcoSystem) {

                // POST requests and every request that updates the planet keep the locked re-read (see isUntouchedView())
                if (!empty($this->state->USER) && !empty($this->state->PLANET) && !$this->isUntouchedView()) {
                    $db = $this->db;
                    $db->startTransaction();
                    // User row first, then planets: the same order as the
                    // research, Empire, vacation and fleet mission paths.
                    $sql    = "SELECT * FROM %%USERS%% WHERE id = :userId FOR UPDATE;";
                    $u   = $db->selectSingle($sql, [
                        ':userId'   => $this->state->USER['id'],
                    ]);
                    $sql    = "SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;";
                    $p = $db->selectSingle($sql, [
                        ':planetId' => $this->state->PLANET['id'],
                    ]);

                    // Calc from the locked rows. Session copies are stale under
                    // concurrent requests (PREVENT_MULTISESSIONS is false) and
                    // SavePlanetToDB applies resource deltas additively.
                    if (!empty($p) && !empty($u)) {
                        $this->state->USER = array_merge($this->state->USER, $u);
                        $this->state->PLANET = $p;
                    }

                    [$this->state->USER, $this->state->PLANET] = $this->ecoObj->CalcResource($this->state->USER, $this->state->PLANET, true);
                    $db->commit();
                }
            }
        } else {
            $this->setWindow('ajax');
        }
    }

    /**
     * True for a plain page view (GET/HEAD) whose resource update would not change the planet: the boot's own reads are
     * as good as locked ones, so initialize() opens no transaction and takes no row locks.
     */
    private function isUntouchedView(): bool
    {
        return $this->state->request?->isMethodSafe() === true
            && !$this->ecoObj->wouldUpdate($this->state->USER, $this->state->PLANET);
    }

    /**
     * Device cookie (v0_device, created when missing) and, at most once per hour and player, the hashed device id in
     * USER_DEVICES (account links). Skipped for XHR and when the service is not injected.
     */
    private function trackDevice(): void
    {
        if (!isset($this->deviceLinks) || empty($this->state->USER['id']) || $this->state->runtime->ajax) {
            return;
        }
        $deviceId = $this->state->requestCookies[DeviceLinks::COOKIE] ?? null;
        if (!$this->deviceLinks->isValidDeviceId($deviceId)) {
            $deviceId = $this->deviceLinks->newDeviceId();
            $this->http()->sendCookie(DeviceLinks::COOKIE, $deviceId, $this->state->runtime->timestamp + DeviceLinks::COOKIE_LIFETIME, '/', null, $this->state->runtime->https, true, 'lax');
        }

        $session = $this->sessions()->symfonySession();
        $key = 'device_seen_' . (int) $this->state->USER['id'] . '_' . substr($this->deviceLinks->hash($deviceId), 0, 8);
        $now = $this->state->runtime->timestamp;
        if ($now - (int) $session->get($key, 0) < DeviceLinks::RECORD_INTERVAL) {
            return;
        }
        $session->set($key, $now);
        $this->deviceLinks->record((int) $this->state->USER['id'], $deviceId, $now);
    }

    /**
     * "You have x new messages" once per login and player (first full page; the Symfony session is
     * invalidated on logout). Afterwards the badge on the messages icon in the top bar shows the count.
     *
     * @return array{text: string, url: string}|null
     */
    private function newMessagesNotice(int $count): ?array
    {
        $USER = $this->state->USER;
        $session = $this->sessions()->symfonySession();
        $key = 'messages_notice_' . (int) $USER['id'];
        if ($session->get($key)) {
            return null;
        }
        $session->set($key, true);
        if ($count < 1) {
            return null;
        }

        $LNG = $this->state->LNG;
        $category = (int) ($USER['showMessageCategory'] ?? 0) === 1 && is_numeric($USER['message_type'] ?? null)
            ? ['category' => (int) $USER['message_type']] : [];

        return [
            'text' => $count === 1 ? $LNG['ov_have_new_message'] : $LNG->format('ov_have_new_messages', $count),
            'url'  => $this->router->generate('game_messages_show', $category),
        ];
    }

    /** @var array<int, int> account id per user id, looked up once per request (navigation and page data) */
    private array $accountIds = [];

    private function accountIdOfUser(int $userId): int
    {
        return $this->accountIds[$userId] ??= $this->playerLookup()->get_account_id($userId);
    }

    /**
     * Navigation of full pages: planet list, resource bar, counters, account notices.
     *
     * @return array<string, mixed>
     */
    protected function navigationData(): array
    {
        $PLANET = $this->state->PLANET;
        $THEME = $this->state->THEME;
        $config = $this->configs->get();
        $db = $this->db;

        $PlanetSelect = $this->planetSelectOptions();
        $resourceTable = $this->resourceBar($config);

        $themeSettings = $THEME->getStyleSettings();

        $commit = '';
        $commitShort = '';
        if (file_exists('.git/FETCH_HEAD')) {
            $commit = explode('	', file_get_contents('.git/FETCH_HEAD'))[0];
            $commitShort = substr($commit, 0, 7);
        }

        $avatar = 'styles/resource/images/user.webp';
        if ($this->sessions()->load()->data !== null) {
            try {
                $avatar = json_decode($this->sessions()->load()->data->account->json_metadata)->profile->profile_image;
            } catch (Exception $e) {
            }
        }

        [$allyrequests, $buddyRequests] = $this->navigationCounters();
        $vacation = $this->vacationNotice();

        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;";
        $wreckfieldData = $db->selectSingle($sql, [':planetID' => $PLANET['id']]);
        $wreckfield = $wreckfieldData && empty($wreckfieldData['repair_order']) && !empty($wreckfieldData['ships']);

        $deletion = $this->deletionNotice($config);

        $tutorialDetails = [];
        if ($this->viewHelpers()->isModuleAvailable(MODULE_TUTORIAL)) {
            $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);
            $tutorialDetails = $tutorialUtil->getTutorialStepDetails();
        }


        return [
            'PlanetSelect' => $PlanetSelect,
            'planetList' => $this->planetList(),
            'planetSlots' => $this->planetSlots(),
            'hostileAlert' => $this->hostileAlert(),
            'wreckfield' => $wreckfield,
            'new_message' => $this->state->USER['messages'],
            'loginNotice' => $this->newMessagesNotice((int) $this->state->USER['messages']),
            'userRank' => (int) ($this->state->USER['total_rank'] ?? 0),
            'userPoints' => (float) ($this->state->USER['total_points'] ?? 0),
            'new_allyrequests' => $allyrequests,
            'new_buddyrequests' => $buddyRequests,
            'commit' => $commit,
            'commitShort' => $commitShort,
            'vacation' => $vacation === false ? false : new \Twig\Markup((string) $vacation, 'UTF-8'),
            'delete' => $deletion === false ? false : new \Twig\Markup((string) $deletion, 'UTF-8'),
            'current_pid' => $PLANET['id'],
            'image' => $PLANET['image'],
            'planet_style' => $PLANET['planet_style'],
            'username' => $this->state->USER['username'],
            'avatar' => $avatar,
            'resourceTable' => $resourceTable,
            'shortlyNumber' => $themeSettings['TOPNAV_SHORTLY_NUMBER'],
            'uni_status' => $config->uni_status,
            'hasBoard' => true,
            'hasAdminAccess' => !empty($this->sessions()->load()->adminAccess),
            'hasRebuildProtection' => $config->rebuild_protection == 1 && $this->state->USER['rebuild_protection'] > 0,
            'rebuildProtectionUntil' => $this->state->USER['rebuild_protection'] + TIME_24_HOURS * 2 - $this->state->runtime->timestamp,
            'hasGate' => $PLANET[$this->elements()->column(43)] > 0,
            'communityNav' => $this->communityNav(!empty($tutorialDetails['id'] ?? null)),
            'tutorial' => !$tutorialDetails || empty($tutorialDetails) ? false : $tutorialDetails,
            'noobInterface' => $this->state->USER['noob_interface'],
            'voteNav' => $this->viewHelpers()->isModuleAvailable(MODULE_VOTING) && (new \App\Community\VoteSites($this->db))->hasActive((int) $this->state->USER['universe']),
        ];
    }


    /**
     * Options of the planet selector ("name (Moon) [g:s:p]" per planet id). Ends the request with the banned message for
     * banned players and loads the player's planet list into the USER state on first use.
     *
     * @return array<int, string> planet id => label
     */
    private function planetSelectOptions(): array
    {
        $LNG = $this->state->LNG;
        $PlanetSelect = [];

        if ($this->state->USER['bana'] == 1) {
            throw new EndOfRequest($LNG['banned_message']);
        }

        if (!isset($this->state->USER['PLANETS'])) {
            $this->state->USER['PLANETS'] = $this->planetQueries()->getPlanets($this->state->USER);
        }

        foreach ($this->state->USER['PLANETS'] as $PlanetQuery) {
            $PlanetSelect[$PlanetQuery['id']] = $PlanetQuery['name'] . (($PlanetQuery['planet_type'] == PlanetType::Moon->value) ? " (" . $LNG['fcm_moon'] . ")" : "") . " [" . $PlanetQuery['galaxy'] . ":" . $PlanetQuery['system'] . ":" . $PlanetQuery['planet'] . "]";
        }

        return $PlanetSelect;
    }

    /**
     * The player's planets for the Empire rail, in the user's sort order, moons grouped under their planet.
     * One extra query for field counters and the terraformer / moon base levels.
     *
     * @return list<array<string, mixed>> per planet: id, name, galaxy, system, planet, image, style, current, fields, fieldsMax,
     *                                     build (null | {id, level, mode}), moon (null | same shape without moon)
     */
    private function planetList(): array
    {
        $rows = $this->state->USER['PLANETS'] ?? [];
        if (!$rows) {
            return [];
        }
        $elements = $this->elements();
        $terraformer = $elements->column(TERRAFORMER);
        $moonBase = $elements->column(MOONBASE);
        $extra = [];
        $sql = "SELECT id, id_luna, field_current, field_max, `" . $terraformer . "` AS terraformer, `" . $moonBase . "` AS moonbase
            FROM %%PLANETS%% WHERE id_owner = :userId AND destruyed = 0;";
        foreach ($this->db->select($sql, [':userId' => $this->state->USER['id']]) as $row) {
            $extra[$row['id']] = $row;
        }

        $currentId = (int) $this->state->PLANET['id'];
        $now = $this->state->runtime->timestamp;
        $entries = [];
        foreach ($rows as $row) {
            $more = $extra[$row['id']] ?? [];
            $build = null;
            if (!empty($row['b_building']) && !empty($row['b_building_id']) && $row['b_building'] > $now) {
                $queue = \App\Game\Core\StoredData::decode($row['b_building_id']);
                if (!empty($queue[0])) {
                    $build = ['id' => (int) $queue[0][0], 'level' => (int) $queue[0][1], 'mode' => (string) ($queue[0][4] ?? 'build')];
                }
            }
            $entries[$row['id']] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'galaxy' => (int) $row['galaxy'],
                'system' => (int) $row['system'],
                'planet' => (int) $row['planet'],
                'image' => $row['image'],
                'style' => (int) $row['planet_style'],
                'isMoon' => $row['planet_type'] == PlanetType::Moon->value,
                'current' => (int) $row['id'] === $currentId,
                'fields' => (int) ($more['field_current'] ?? 0),
                'fieldsMax' => (int) (($more['field_max'] ?? 0) + floor(($more['terraformer'] ?? 0) * FIELDS_BY_TERRAFORMER) + ($more['moonbase'] ?? 0) * FIELDS_BY_MOONBASIS_LEVEL),
                'build' => $build,
                'moon' => null,
                'lunaId' => (int) ($more['id_luna'] ?? 0),
            ];
        }

        $list = [];
        foreach ($entries as $id => $entry) {
            if ($entry['isMoon']) {
                continue;
            }
            if ($entry['lunaId'] && isset($entries[$entry['lunaId']])) {
                $entry['moon'] = $entries[$entry['lunaId']];
            }
            $list[] = $entry;
        }
        foreach ($entries as $entry) {
            // a moon without its planet in the list (should not happen) is shown on its own
            if ($entry['isMoon'] && !array_filter($list, static fn(array $p): bool => ($p['moon']['id'] ?? 0) === $entry['id'])) {
                $list[] = $entry;
            }
        }

        return $list;
    }

    /**
     * Planet slots of the player.
     *
     * @return array{used: int, max: int}
     */
    private function planetSlots(): array
    {
        $positions = $this->planetPositions();

        return [
            'used' => (int) $positions->currentPlanetCount($this->state->USER),
            'max' => (int) $positions->maxPlanetCount($this->state->USER),
        ];
    }

    /**
     * The next hostile fleet (attack, ACS attack, destroy, missile of another player) still flying towards one of the player's
     * planets or moons, one query; null when none.
     *
     * @return array{name: string, galaxy: int, system: int, planet: int, type: int, remaining: int}|null
     */
    private function hostileAlert(): ?array
    {
        $now = $this->state->runtime->timestamp;
        $sql = "SELECT f.arrival_time, f.end_galaxy, f.end_system, f.end_planet, f.end_type, p.name
            FROM %%FLEETS%% f LEFT JOIN %%PLANETS%% p ON p.id = f.end_id
            WHERE f.target_owner = :userId AND f.owner != :userId AND f.status = :outward
                AND f.mission IN (1, 2, 9, 10) AND f.arrival_time > :now
            ORDER BY f.arrival_time ASC LIMIT 1;";
        $row = $this->db->selectSingle($sql, [
            ':userId' => $this->state->USER['id'],
            ':outward' => FLEET_OUTWARD,
            ':now' => $now,
        ]);
        if (empty($row)) {
            return null;
        }

        return [
            'name' => (string) $row['name'],
            'galaxy' => (int) $row['end_galaxy'],
            'system' => (int) $row['end_system'],
            'planet' => (int) $row['end_planet'],
            'type' => (int) $row['end_type'],
            'remaining' => (int) $row['arrival_time'] - $now,
        ];
    }

    /**
     * The resource bar of the current planet: metal/crystal/deuterium with stock, capacity and hourly production (incl. the basic
     * income, except in vacation mode and on moons), energy with use and capacity, and the third resource group (dark matter) with its amount.
     *
     * @return array<int, array<string, mixed>> resource id => name, current, max, production / used
     */
    private function resourceBar(object $config): array
    {
        $PLANET = $this->state->PLANET;
        $e = $this->elements();

        $resourceTable = [];
        $resourceSpeed = $config->resource_multiplier / 2500;
        foreach ($e->resourceIds(1) as $resourceID) {
            $resourceTable[$resourceID]['name'] = $e->column($resourceID);
            $resourceTable[$resourceID]['current'] = floor($PLANET[$e->column($resourceID)]);
            $resourceTable[$resourceID]['max'] = $PLANET[$e->column($resourceID) . '_max'];
            if ($this->state->USER['urlaubs_modus'] == 1 || $PLANET['planet_type'] != PlanetType::Planet->value) {
                $resourceTable[$resourceID]['production'] = $PLANET[$e->column($resourceID) . '_perhour'];
            } else {
                $resourceTable[$resourceID]['production'] = $PLANET[$e->column($resourceID) . '_perhour'] + $config->{$e->column($resourceID) . '_basic_income'} * $resourceSpeed;
            }
        }

        foreach ($e->resourceIds(2) as $resourceID) {
            $resourceTable[$resourceID]['name'] = $e->column($resourceID);
            $resourceTable[$resourceID]['used'] = $PLANET[$e->column($resourceID) . '_used'];
            $resourceTable[$resourceID]['max'] = $PLANET[$e->column($resourceID)];
        }

        foreach ($e->resourceIds(3) as $resourceID) {
            $resourceTable[$resourceID]['name'] = $e->column($resourceID);
            $resourceTable[$resourceID]['current'] = $this->state->USER[$e->column($resourceID)];
        }

        return $resourceTable;
    }

    /**
     * The counters of the navigation in one query: open alliance matters the player may handle (applications as owner
     * or with MANAGEAPPLY right, plus pending diplomacy requests as owner or with DIPLOMATIC right; players without an
     * alliance have none) and the player's buddy requests.
     *
     * @return array{0: int|string, 1: int|string} [alliance requests, buddy requests]
     */
    private function navigationCounters(): array
    {
        $sql = "SELECT
            (SELECT COUNT(r.applyID)
                FROM %%ALLIANCE_REQUEST%% r
                JOIN %%USERS%% u ON u.ally_id = r.allianceID
                JOIN %%ALLIANCE%% a ON a.id = u.ally_id
                LEFT JOIN %%ALLIANCE_RANK%% ra ON ra.allianceID = u.ally_id AND ra.rankID = u.ally_rank_id
                WHERE r.allianceID = :allianceID AND u.id = :userID AND (u.id = a.ally_owner OR ra.MANAGEAPPLY = 1)) AS applies,
            (SELECT COUNT(d.id)
                FROM %%DIPLO%% d
                JOIN %%USERS%% u ON u.ally_id = d.owner_2
                JOIN %%ALLIANCE%% a ON a.id = u.ally_id
                LEFT JOIN %%ALLIANCE_RANK%% ra ON ra.allianceID = u.ally_id AND ra.rankID = u.ally_rank_id
                WHERE d.owner_2 = :allianceID AND accept = 0 AND u.id = :userID AND (u.id = a.ally_owner OR ra.DIPLOMATIC = 1)) AS diplos,
            (SELECT COUNT(b.`id`) FROM %%BUDDY%% b
                JOIN %%BUDDY_REQUEST%% br ON br.`id` = b.`id`
                WHERE b.`sender` = :userID OR b.`owner` = :userID) AS buddies;";
        $row = $this->db->selectSingle($sql, [
            // no alliance has id 0: players without one count nothing
            ':allianceID' => $this->state->USER['ally_id'] ?: 0,
            ':userID' => $this->state->USER['id'],
        ]);

        return [$row['applies'] + $row['diplos'], $row['buddies']];
    }

    /**
     * Vacation mode banner text (until when, or "may end now", plus the kick hint when the module is on), false outside vacation mode.
     */
    private function vacationNotice(): string|false
    {
        $LNG = $this->state->LNG;
        $vacation = false;
        if ($this->state->USER['urlaubs_modus']) {
            if ($this->state->USER['urlaubs_until'] > $this->state->runtime->timestamp) {
                $vacation = $LNG->format('tn_vacation_mode_until', $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->state->USER['urlaubs_until'], $this->state->USER['timezone']));
            } else {
                $vacation = $LNG['tn_vacation_mode'];
            }
            if ($this->viewHelpers()->isModuleAvailable(MODULE_VMODE_KICK, $this->state->USER['universe'])) {
                $vacation .= $LNG['tn_vacation_mode_kick'];
            }
        }

        return $vacation;
    }

    /**
     * Account deletion banner text with the planned deletion date (of the game account or of the whole account), false when none is pending.
     */
    private function deletionNotice(object $config): string|false
    {
        $LNG = $this->state->LNG;
        $deletion = false;
        $accountId = $this->accountIdOfUser((int) $this->state->USER['id']);
        if ($accountId === 0) {
            return false; // guests (public battle report)
        }
        $ACCOUNT = $this->accountUtil()->get_account($accountId);
        if (!empty($this->state->USER['db_deaktjava']) || !empty($ACCOUNT['db_deaktjava'])) {
            $timestamp = $this->state->USER['db_deaktjava'] ? $this->state->USER['db_deaktjava'] : $ACCOUNT['db_deaktjava'];
            $deletion = $LNG->format('tn_delete_mode', $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->accountUtil()->getDeletionDate($timestamp, $config), $ACCOUNT['timezone']));
        }

        return $deletion;
    }

    /**
     * Community entries of the navigation (Discord, wiki, donate), see CommunityLinks::navLinks(). The wiki entry
     * keeps going through the wiki SSO route while `$wikiSsoUrl` is set in config/game.php (as WikiLoginController reads it).
     *
     * @return array<string, string>
     */
    private function communityNav(bool $tutorialActive): array
    {
        $wikiSsoUrl = (static function (string $rootPath): string {
            require $rootPath . 'config/game.php';

            return (string) ($wikiSsoUrl ?? '');
        })($this->state->runtime->rootPath);
        $config = $this->configs->get();
        $urls = [];
        foreach (\App\Game\Core\CommunityLinks::KEYS as $key) {
            $urls[$key] = (string) ($config->$key ?? '');
        }

        return \App\Game\Core\CommunityLinks::navLinks($urls, !empty($wikiSsoUrl), $tutorialActive);
    }

    /** the clock text in the format of scripts/app/core.js getFormatedDate() (language key js_tdformat) */
    private function clockText(\DateTimeInterface $time): string
    {
        $LNG = $this->state->LNG;
        $months = $LNG['months'] ?? [];
        $days = $LNG['week_day'] ?? [];

        return strtr((string) ($LNG['js_tdformat'] ?? '[H]:[i]:[s]'), [
            '[d]' => $time->format('d'),
            '[D]' => (string) ($days[(int) $time->format('w')] ?? ''),
            '[m]' => $time->format('m'),
            '[M]' => (string) ($months[(int) $time->format('n') - 1] ?? ''),
            '[j]' => $time->format('j'),
            '[Y]' => $time->format('Y'),
            '[y]' => $time->format('y'),
            '[G]' => $time->format('G'),
            '[H]' => $time->format('H'),
            '[i]' => $time->format('i'),
            '[s]' => $time->format('s'),
        ]);
    }

    /**
     * Player, clock and theme settings of every non-ajax page.
     *
     * @return array<string, mixed>
     */
    protected function pageData(): array
    {
        $USER = $this->state->USER;
        $THEME = $this->state->THEME;

        // render time with milliseconds: the JS clock starts from it (whole request seconds made it jump on reload)
        $now = microtime(true);
        $dateTimeServer = new DateTime("now");
        if (isset($USER['timezone'])) {
            try {
                $dateTimeUser = new DateTime("now", new DateTimeZone($USER['timezone']));
            } catch (Exception $e) {
                $dateTimeUser = $dateTimeServer;
            }
        } else {
            $dateTimeUser = $dateTimeServer;
        }
        $accountId = $this->accountIdOfUser((int) $USER['id']);
        $config = $this->configs->get();

        return [
            'vmode' => $USER['urlaubs_modus'],
            'authlevel' => $USER['authlevel'],
            'account_id' => $accountId,
            'userID' => $USER['id'],
            'bodyclass' => $this->getWindow(),
            'game_name' => $config->game_name,
            'uni_name' => $config->uni_name,
            'debug' => $config->debug,
            'version' => $config->version,
            'date' => explode("|", date('Y\|n\|j\|G\|i\|s\|Z', (int) $now)),
            'dateMs' => (int) (($now - floor($now)) * 1000),
            // first clock text, so the top bar does not change width when scripts/app/timers.js takes over
            'serverClock' => $this->clockText((clone $dateTimeUser)->setTimestamp((int) $now)),
            'isPlayerCardActive' => $this->viewHelpers()->isModuleAvailable(MODULE_PLAYERCARD),
            'REV' => substr($config->version, -4),
            'Offset' => $dateTimeUser->getOffset() - $dateTimeServer->getOffset(),
            'themeSettings' => $THEME->getStyleSettings(),
        ];
    }

    /**
     * The message page of the area.
     *
     * @param string|\Twig\Markup $message plain text is escaped, pass Twig\Markup for HTML
     * @param list<array{url: string, label: string}>|null $redirectButtons links under the message
     * @param array{0: string, 1: int}|null $redirect [url, seconds] automatic redirect
     * @param bool $fullSide false: popup window
     */
    protected function messageResponse($message, $redirectButtons = null, $redirect = null, $fullSide = true): Response
    {
        $context = [
            'message' => $message,
            'redirectButtons' => $redirectButtons,
        ];

        if (isset($redirect)) {
            $context += ['goto' => $redirect[0], 'gotoinsec' => $redirect[1]];
        }

        if (!$fullSide) {
            $this->setWindow('popup');
        }

        return $this->render('game/error.default.html.twig', $context);
    }

    /**
     * Shows a message page and ends the request (initialize() and helpers that cannot return the Response; an action
     * returns messageResponse()).
     *
     * @param string|\Twig\Markup $message plain text is escaped, pass Twig\Markup for HTML
     * @param list<array{url: string, label: string}>|null $redirectButtons links under the message
     * @param array{0: string, 1: int}|null $redirect [url, seconds] automatic redirect
     * @param bool $fullSide false: popup window
     */
    protected function printMessage($message, $redirectButtons = null, $redirect = null, $fullSide = true): never
    {
        throw EndOfRequest::withResponse($this->messageResponse($message, $redirectButtons, $redirect, $fullSide));
    }

    /**
     * For an action that is posted by its form: a plain GET of its URL (the planet selector reloads the page with `?cp=`) goes to
     * the page route instead of ending in "method not allowed"; null for the POST itself.
     */
    protected function redirectUnlessPost(string $pageRoute): ?Response
    {
        if (($this->state->requestServer['REQUEST_METHOD'] ?? '') === 'POST') {
            return null;
        }

        return $this->redirectToRoute($pageRoute);
    }

    /**
     * Sets `last_update` of the current planet to the request time (used by pages that change the planet without a resource update).
     */
    protected function updatePlanetTime()
    {
        $PLANET = $this->state->PLANET;

        if (!isset($PLANET['id'])) {
            return;
        }
        $db = $this->db;
        $sql = "UPDATE %%PLANETS%% SET last_update = :lastUpdateTime WHERE id = :planetId";
        $db->update($sql, [
            ':lastUpdateTime' => $this->state->runtime->timestamp,
            ':planetId' => $PLANET['id'],
        ]);
    }

    /**
     * Variables of the game shell for full pages (top bar, planet list, resources, new-message badge, theme and clock). Popup and ajax
     * windows get no shell data.
     */
    protected function layoutData(string $template): array
    {
        $LNG = $this->state->LNG;
        $THEME = $this->state->THEME;

        $data = [];
        if ($this->getWindow() === 'full') {
            $data = $this->navigationData();
        }
        if ($this->getWindow() !== 'ajax') {
            $data = array_replace($data, $this->pageData());
        }

        return array_replace($data, [
            'lang'              => $LNG->getLanguage(),
            'dpath'             => $THEME->getTheme(),
            'themeCssPath'      => $THEME->getThemeCssPath(),
            // also in fragments (ajax window): page templates read the row counts of the theme
            'themeSettings'     => $THEME->getStyleSettings(),
            'basepath'          => $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpBase,
            'TIMEZONESTRING'    => $this->state->USER['timezone'],
            'signalColors'      => $this->state->USER['signalColors'],
            'decimalSeparator'  => $this->state->USER['decimal_separator'],
            'numberSuffixes'    => (new NumberAbbreviation())->suffixesForLang($LNG->getLanguage()),
        ]);
    }

    /**
     * RendersPages::render() plus the Server-Timing header (bootstrap, page, rendering, database)
     *
     * @param array<string, mixed> $context
     */
    protected function render(string $template, array $context = []): Response
    {
        $this->state->time['end_page'] = microtime(true);
        $response = $this->renderPage($template, $context);
        $this->state->time['end_template'] = microtime(true);

        $ms = static fn(float $from, float $to): float => ($to - $from) * 1000;
        $response->headers->set('Server-Timing', sprintf(
            'total;dur=%s, common;dur=%s, page;dur=%s, rendering;dur=%s, db;desc=query;dur=%s',
            $ms($this->state->time['start'], $this->state->time['end_template']),
            $ms($this->state->time['start'], $this->state->time['end_common']),
            $ms($this->state->time['end_common'], $this->state->time['end_page']),
            $ms($this->state->time['end_page'], $this->state->time['end_template']),
            ($this->state->time['db'] ?? 0) * 1000,
        ));

        return $response;
    }

    /**
     * Sends $data as a JSON response and ends the request (EndOfRequest).
     */
    protected function sendJSON($data): never
    {
        // $this->updatePlanetTime();
        echo json_encode($data);
        throw new EndOfRequest();
    }

    /**
     * Redirects to $url and ends the request.
     */
    protected function redirectTo($url): never
    {
        // $this->updatePlanetTime();
        $this->http()->redirectTo($url);
    }
}
