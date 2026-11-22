<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

use App\Game\Enum\MessageType;
use App\Game\Enum\Mission;

/**
 * Builds the rows of the galaxy view for one system (setGalaxy()/setSystem(), then getGalaxyData()): per planet
 * position the planet, moon, debris field, owner, alliance, available missions and action buttons as seen by the
 * current player ($state->USER/PLANET). Strings are HTML-escaped for the template; the data is display-only.
 */
class GalaxyRows
{
    use \App\Game\Core\GameUtilities;

    private int $Galaxy;
    private int $System;
    /** @var array<mixed> */
    private array $galaxyData = [];
    /** @var array<mixed> */
    private array $galaxyRow = [];
    /** @var array<mixed> */
    private array $spyReportAges = [];
    /** @var array<int|string, bool> isModuleAvailable() per module, asked once per request instead of once per row */
    private array $moduleFlags = [];
    /** phalanx availability of the current row (planet block and action buttons share it), null = not asked yet */
    private ?bool $rowPhalanx = null;

    public const PLANET_DESTROYED = true;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public function setGalaxy(int $Galaxy): self
    {
        $this->Galaxy   = $Galaxy;
        return $this;
    }

    public function setSystem(int $System): self
    {
        $this->System   = $System;
        return $this;
    }

    /**
     * One query joins planets, owners, moons, statistics, alliance rank/diplomacy and buddy state of the system, then each
     * position is expanded: planets marked `destruyed` only show the debris; others get last activity, owner (with
     * noob/strong/vacation/inactive status classes), planet, alliance, debris, moon, action buttons and the spy report indicator.
     * @return array<int, array<string, mixed>> keyed by planet position (1..max_planets).
     */
    public function getGalaxyData(): array
    {
        $USER = $this->state->USER;

        $sql = "SELECT SQL_BIG_RESULT DISTINCT
                p.`galaxy`, p.`system`, p.`planet`, p.`id` as planet_id, p.`id_owner`, p.`name`, p.`image`, p.`planet_style`, p.`last_update`, p.`diameter`, p.`temp_min`,
                p.`destruyed`, p.`der_metal`, p.`der_crystal`, p.`id_luna`, u.`id`, u.`ally_id`, u.`username`, u.`rebuild_protection`, u.`rebuild_protection_attackers`, u.`outlaw_time`,
                u.`onlinetime`, u.`urlaubs_modus`, u.`banaday`, m.`id` as m_id, m.`diameter` as m_diameter, m.`name` as m_name, m.`image` as m_image, m.`planet_style` as m_planet_style,
                m.`temp_min` as m_temp_min, m.`last_update` as m_last_update, s.`total_points`, s.`total_rank`, a.`id` as allyid,
                a.`ally_tag`, a.`ally_web`, a.`ally_members`, a.`ally_name`, allys.`total_rank` as ally_rank,
                (a.`ally_owner`=u.`id`) as is_leader, a.`ally_owner_range`, r.`DIPLOMATIC` as is_diplo,
                COUNT(buddy.`id`) as buddy, d.`level` as diploLevel 
            FROM %%PLANETS%% p
            LEFT JOIN %%USERS%% u ON p.`id_owner` = u.`id`
            LEFT JOIN %%PLANETS%% m ON m.`id` = p.`id_luna`
            LEFT JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id` AND s.`stat_type` = :statTypeUser
            LEFT JOIN %%ALLIANCE%% a ON a.`id` = u.`ally_id`
            LEFT JOIN %%ALLIANCE_RANK%% as r ON r.`allianceID` = u.`ally_id` AND r.`rankID` = u.`ally_rank_id`
            LEFT JOIN %%DIPLO%% as d ON ((d.`owner_1` = :allianceId AND d.`owner_2` = a.`id`) OR (d.`owner_1` = a.`id`
                AND d.`owner_2` = :allianceId)) AND d.`accept` = :accept
            LEFT JOIN %%STATPOINTS%% allys ON allys.`stat_type` = :statTypeAlliance AND allys.`id_owner` = a.`id`
            LEFT JOIN %%BUDDY%% buddy ON ((buddy.`sender` = :userId AND buddy.`owner` = u.`id`) OR (buddy.`sender` = u.`id`
                AND buddy.`owner` = :userId)) AND u.`id` != :userId
            WHERE p.`universe` = :universe AND p.`galaxy` = :galaxy AND p.`system` = :system
              AND p.`planet_type` = :planetTypePlanet
            GROUP BY p.`id`;";

        $galaxyResult = $this->db->select($sql, [
            ':statTypeUser'     => 1,
            ':statTypeAlliance' => 2,
            ':allianceId'       => $USER['ally_id'],
            ':userId'           => $USER['id'],
            ':universe'         => $this->universe()->current(),
            ':galaxy'           => $this->Galaxy,
            ':system'           => $this->System,
            ':planetTypePlanet' => 1,
            ':accept'           => 1,
        ]);

        $this->fetchSpyReportAges();

        foreach ($galaxyResult as $galaxyRow) {
            $this->galaxyRow = $galaxyRow;
            $this->rowPhalanx = null;

            $this->galaxyData[$this->galaxyRow['planet']] = [];

            $this->checkOwnPlanet();
            $this->getAllowedMissions();

            if ($this->galaxyRow['destruyed'] != 0) {
                $this->galaxyData[$this->galaxyRow['planet']]['destroyed'] = self::PLANET_DESTROYED;
                $this->galaxyData[$this->galaxyRow['planet']]['planet'] = [
                    'id'      => $this->galaxyRow['planet_id'],
                ];
                $this->getDebrisData();
                continue;
            }

            $this->galaxyData[$this->galaxyRow['planet']]['destroyed'] = !self::PLANET_DESTROYED;
            $this->setLastActivity();

            $this->getPlayerData();
            $this->getPlanetData();
            $this->getAllianceData();
            $this->getDebrisData();
            $this->getMoonData();
            $this->getActionButtons();
            $this->getSpyReportIndicator();
        }

        return $this->galaxyData;
    }

    /**
     * Activity marker of the current galaxy row from the newer of planet and moon update times: "(*)" under 15 minutes,
     * the minutes under an hour, nothing after that.
     */
    private function setLastActivity(): void
    {
        $LNG = $this->state->LNG;

        $lastActivity = floor(
            ($this->state->runtime->timestamp - max($this->galaxyRow['last_update'], $this->galaxyRow['m_last_update'])) / 60,
        );

        if ($lastActivity < 15) {
            $this->galaxyData[$this->galaxyRow['planet']]['lastActivity'] = $LNG['gl_activity'];
        } elseif ($lastActivity < 60) {
            $this->galaxyData[$this->galaxyRow['planet']]['lastActivity'] = $LNG->format(
                'gl_activity_inactive',
                $lastActivity,
            );
        } else {
            $this->galaxyData[$this->galaxyRow['planet']]['lastActivity']   = '';
        }
    }

    private function checkOwnPlanet(): void
    {
        $USER = $this->state->USER;

        $this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']  = $this->galaxyRow['id_owner'] == $USER['id'];
    }

    /**
     * Mission buttons that apply to the position: attack/hold/spy/destroy/missiles only on foreign planets, station only
     * on own planets, transport and recycle always; each needs its module, destroy a Death Star/Void Titan on the
     * current planet, missiles an interplanetary missile in range (ion tech level, see FleetMath::inMissileRange()).
     */
    private function getAllowedMissions(): void
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $elements = $this->elements();
        $this->galaxyData[$this->galaxyRow['planet']]['missions'] = [
            1 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $this->module(MODULE_MISSION_ATTACK),
            3 => $this->module(MODULE_MISSION_TRANSPORT),
            4 => $this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $this->module(MODULE_MISSION_STATION),
            5 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $this->module(MODULE_MISSION_HOLD),
            6 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $this->module(MODULE_MISSION_SPY),
            8 => $this->module(MODULE_MISSION_RECYCLE),
            9 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && ($PLANET[$elements->find(SHIP_RIP)?->column] > 0 || ($PLANET[$elements->find(VOID_TITAN)?->column ?? ''] ?? 0) > 0)
                && $this->module(MODULE_MISSION_DESTROY),
            10 => !$this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']
                && $PLANET[$elements->find(INTERPLANETARY_MISSILE)?->column] > 0
                && $this->module(MODULE_MISSION_ATTACK)
                && $this->module(MODULE_MISSILEATTACK)
                && $this->fleetFunctions()->inMissileRange($PLANET, $this->galaxyRow, $USER[$elements->find(117)?->column])
                && $this->missileTargetAllowed(),
        ];
    }

    /** Whether the launch rules (FleetMissileController) allow missiles at this owner: not in vacation mode, not newbie-protected, no stronger player unless outlaw attacks are on. */
    private function missileTargetAllowed(): bool
    {
        if (!empty($this->galaxyRow['urlaubs_modus'])) {
            return false;
        }
        $protection = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($this->state->USER), $this->models()->user($this->galaxyRow));

        return !$protection['NoobPlayer'] && (!$protection['StrongPlayer'] || $this->configs->get()->noob_protection_outlaw);
    }

    /**
     * Quick-action buttons (spy, message, buddy request, missile, spy report, phalanx) from the player's settings
     * and modules; none for own planets. Phalanx also needs the target not in vacation and in range.
     */
    private function getActionButtons(): void
    {
        $USER = $this->state->USER;
        if ($this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']) {
            $this->galaxyData[$this->galaxyRow['planet']]['action'] = false;
        } else {
            $this->galaxyData[$this->galaxyRow['planet']]['action'] = [
                'esp'     => $USER['settings_esp'] == 1
                    && $this->galaxyData[$this->galaxyRow['planet']]['missions'][Mission::Spy->value],
                'message' => $USER['settings_wri'] == 1
                    && $this->module(MODULE_MESSAGES),
                'buddy'   => $USER['settings_bud'] == 1
                    && $this->module(MODULE_BUDDYLIST) && $this->galaxyRow['buddy'] == 0,
                'missle'  => $USER['settings_mis'] == 1
                    && $this->galaxyData[$this->galaxyRow['planet']]['missions'][Mission::Missile->value],
                'spyReport' => $USER['settings_rep'] == 1,
                'phalanx' => $this->phalanxAvailable(),
            ];
        }
    }

    /**
     * Owner block: name, points/rank, status classes (noob/strong protection etc. via userStatus()), buddy and
     * alliance leader/diplomat flags.
     */
    private function getPlayerData(): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $IsNoobProtec = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($this->galaxyRow));
        $Class = $this->protectionRules()->userStatus($this->galaxyRow, $IsNoobProtec);

        $this->galaxyData[$this->galaxyRow['planet']]['user'] = [
            'id'            => $this->galaxyRow['id'],
            'username'      => htmlspecialchars($this->galaxyRow['username'], ENT_QUOTES, "UTF-8"),
            'rank'          => $this->galaxyRow['total_rank'],
            'points'        => $this->viewHelpers()->prettyNumber($this->galaxyRow['total_points']),
            'playerrank'    => $this->module(MODULE_STATISTICS) ? $LNG->format(
                'gl_in_the_rank',
                htmlspecialchars($this->galaxyRow['username'], ENT_QUOTES, "UTF-8"),
                $this->galaxyRow['total_rank'],
            ) : htmlspecialchars($this->galaxyRow['username'], ENT_QUOTES, "UTF-8"),
            'class'         => $Class,
            'isBuddy'       => $this->galaxyRow['buddy'] == 1,
            'is_leader'     => $this->galaxyRow['is_leader'],
            'is_diplo'      => $this->galaxyRow['is_diplo'],
            'ally_owner_range' => $this->galaxyRow['ally_owner_range'],
        ];
    }

    /**
     * Alliance block with CSS class by diplomacy level toward the viewer's alliance (1-2 member/pact, 3 trade,
     * 4 friend, 5 enemy, 6 secret); own alliance members always 'member'. False without alliance.
     */
    private function getAllianceData(): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        if (empty($this->galaxyRow['allyid'])) {
            $this->galaxyData[$this->galaxyRow['planet']]['alliance']   = false;
        } else {
            $Class  = [];
            switch ($this->galaxyRow['diploLevel']) {
                case 1:
                case 2:
                    $Class = ['member'];
                    break;
                case 3:
                    $Class = ['trade'];
                    break;
                case 4:
                    $Class = ['friend'];
                    break;
                case 5:
                    $Class = ['enemy'];
                    break;
                case 6:
                    $Class = ['secret'];
                    break;
            }

            if ($USER['ally_id'] == $this->galaxyRow['ally_id']) {
                $Class = ['member'];
            }

            $this->galaxyData[$this->galaxyRow['planet']]['alliance'] = [
                'id'     => $this->galaxyRow['allyid'],
                'name'   => htmlspecialchars($this->galaxyRow['ally_name'], ENT_QUOTES, "UTF-8"),
                'member' => $LNG->format(
                    ($this->galaxyRow['ally_members'] == 1) ? 'gl_member_add' : 'gl_member',
                    $this->galaxyRow['ally_members'],
                ),
                'web'    => \App\Game\Alliance\AllianceText::httpUrlOrEmpty((string) $this->galaxyRow['ally_web']),
                'tag'    => $this->galaxyRow['ally_tag'],
                'rank'   => $this->galaxyRow['ally_rank'],
                'class'  => $Class,
            ];
        }
    }

    private function getDebrisData(): void
    {
        $total = $this->galaxyRow['der_metal'] + $this->galaxyRow['der_crystal'];
        if ($total == 0) {
            $this->galaxyData[$this->galaxyRow['planet']]['debris'] = false;
        } else {
            $this->galaxyData[$this->galaxyRow['planet']]['debris'] = [
                'metal'   => $this->galaxyRow['der_metal'],
                'crystal' => $this->galaxyRow['der_crystal'],
            ];
        }
    }

    /**
     * Moon block (name, diameter, image by the player's planet-style setting) or false without a moon.
     */
    private function getMoonData(): void
    {
        if (!isset($this->galaxyRow['m_id'])) {
            $this->galaxyData[$this->galaxyRow['planet']]['moon'] = false;
        } else {
            $USER = $this->state->USER;
            $THEME = $this->state->THEME;

            if (!$USER['enable_planet_styles']) {
                $image = $THEME->asset("planeten/" . $this->galaxyRow['m_image'] . ".jpg");
            } else {
                switch ($this->galaxyRow['m_planet_style']) {
                    case 1:
                        $image = $THEME->asset("planeten/varianten/v1_magic/" . $this->galaxyRow['m_image'] . "_v1_magic.webp");
                        break;
                    case 2:
                        $image = $THEME->asset("planeten/varianten/v2_storm/" . $this->galaxyRow['m_image'] . "_v2_storm.webp");
                        break;
                    case 3:
                        $image = $THEME->asset("planeten/varianten/v3_voronoi/" . $this->galaxyRow['m_image'] . "_v3_voronoi.webp");
                        break;
                    default:
                        $image = $THEME->asset("planeten/" . $this->galaxyRow['m_image'] . ".jpg");
                        break;
                }
            }

            $this->galaxyData[$this->galaxyRow['planet']]['moon'] = [
                'id'       => $this->galaxyRow['m_id'],
                'name'     => htmlspecialchars($this->galaxyRow['m_name'], ENT_QUOTES, "UTF-8"),
                'diameter' => $this->galaxyRow['m_diameter'],
                'image'    => $image,
                'phalanx'  => false,
                'type'     => 3,
            ];
        }
    }

    /**
     * Planet block: name, image (classic jpg or one of the style variants when the player enabled planet styles) and
     * phalanx availability.
     */
    private function getPlanetData(): void
    {
        $USER = $this->state->USER;
        $THEME = $this->state->THEME;

        if (!$USER['enable_planet_styles']) {
            $image = $THEME->asset("planeten/" . $this->galaxyRow['image'] . ".jpg");
        } else {
            switch ($this->galaxyRow['planet_style']) {
                case 1:
                    $image = $THEME->asset("planeten/varianten/v1_magic/" . $this->galaxyRow['image'] . "_v1_magic.webp");
                    break;
                case 2:
                    $image = $THEME->asset("planeten/varianten/v2_storm/" . $this->galaxyRow['image'] . "_v2_storm.webp");
                    break;
                case 3:
                    $image = $THEME->asset("planeten/varianten/v3_voronoi/" . $this->galaxyRow['image'] . "_v3_voronoi.webp");
                    break;
                default:
                    $image = $THEME->asset("planeten/" . $this->galaxyRow['image'] . ".jpg");
                    break;
            }
        }

        $this->galaxyData[$this->galaxyRow['planet']]['planet'] = [
            'id'            => $this->galaxyRow['planet_id'],
            'name'          => htmlspecialchars($this->galaxyRow['name'], ENT_QUOTES, "UTF-8"),
            'image'         => $image,
            'phalanx'       => $this->phalanxAvailable(),
            'type'     => 1,
        ];
    }

    /** Whether the phalanx can scan the current row (module on, owner not in vacation, in range); asked once per row. */
    private function phalanxAvailable(): bool
    {
        return $this->rowPhalanx ??= $this->module(MODULE_PHALANX) && $this->galaxyRow['urlaubs_modus'] == 0
            && $this->fleetFunctions()->allowPhalanx($this->galaxyRow['galaxy'], $this->galaxyRow['system'], $this->galaxyRow);
    }

    /** isModuleAvailable() of a module, remembered for the rest of the request (the module settings do not change within it). */
    private function module(int|string $module): bool
    {
        return $this->moduleFlags[$module] ??= (bool) $this->viewHelpers()->isModuleAvailable($module);
    }

    /**
     * Loads the time of the player's newest spy report for each planet of the system (messages grouped 'm_<planetId>').
     */
    private function fetchSpyReportAges(): void
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $sql = "SELECT p.id as planet_id, MAX(m.message_time) as latest_spy_time
            FROM %%PLANETS%% p
            INNER JOIN %%MESSAGES%% m ON m.message_group = CONCAT('m_', p.id)
            INNER JOIN %%USERS_TO_MESSAGES%% um ON m.message_id = um.message_id
            WHERE m.message_type = :messageType
              AND um.user_id = :userId
              AND um.deleted_at IS NULL
              AND p.galaxy = :galaxy
              AND p.`system` = :system
              AND p.universe = :universe
            GROUP BY p.id";

        $results = $db->select($sql, [
            ':messageType' => MessageType::SpyReport->value,
            ':userId'      => $USER['id'],
            ':galaxy'      => $this->Galaxy,
            ':system'      => $this->System,
            ':universe'    => $this->universe()->current(),
        ]);

        foreach ($results as $row) {
            $this->spyReportAges[$row['planet_id']] = $row['latest_spy_time'];
        }
    }

    /**
     * Marks foreign planets by the age of the viewer's latest spy report: fresh (< spy_report_fresh_hours, default 24 h),
     * recent (< spy_report_recent_hours, default 168 h), old, or none.
     */
    private function getSpyReportIndicator(): void
    {
        if ($this->galaxyData[$this->galaxyRow['planet']]['ownPlanet']) {
            return;
        }

        $USER = $this->state->USER;
        $LNG  = $this->state->LNG;

        $freshHours  = (int) ($USER['spy_report_fresh_hours']  ?? 24);
        $recentHours = (int) ($USER['spy_report_recent_hours'] ?? 168);

        $planetId = $this->galaxyRow['planet_id'];

        if (!isset($this->spyReportAges[$planetId])) {
            $this->galaxyData[$this->galaxyRow['planet']]['spyReport']      = 'none';
            $this->galaxyData[$this->galaxyRow['planet']]['spyReportTitle'] = $LNG['gl_spy_report_none'];
            return;
        }

        $age = $this->state->runtime->timestamp - $this->spyReportAges[$planetId];

        if ($age < $freshHours * 3600) {
            $this->galaxyData[$this->galaxyRow['planet']]['spyReport']      = 'fresh';
            $this->galaxyData[$this->galaxyRow['planet']]['spyReportTitle'] = $LNG->format('gl_spy_report_fresh', $freshHours);
        } elseif ($age < $recentHours * 3600) {
            $this->galaxyData[$this->galaxyRow['planet']]['spyReport']      = 'recent';
            $this->galaxyData[$this->galaxyRow['planet']]['spyReportTitle'] = $LNG->format('gl_spy_report_recent', $recentHours);
        } else {
            $this->galaxyData[$this->galaxyRow['planet']]['spyReport']      = 'old';
            $this->galaxyData[$this->galaxyRow['planet']]['spyReportTitle'] = $LNG->format('gl_spy_report_old', $recentHours);
        }
    }
}
