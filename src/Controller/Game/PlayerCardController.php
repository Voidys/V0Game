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

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use Exception;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

/**
 * Player card popup with statistics, battle record and the player's achievements.
 */
#[\App\Controller\RequireModule('MODULE_PLAYERCARD')]
class PlayerCardController extends AbstractGameController
{
    private const MAX_BLOCKS = 200;

    // protected $disableEcoSystem = true;

    /**
     * Player card popup (request: id; a missing or unknown id shows the "player does not exist" message): player data, total points and the stats blocks below, plus achievements and the block status. Buildings need
     * the viewer's spy tech level 2, techs level 4, defense/fleet/battle record/honor level 6 (ProtectionRules::spyTechAllowedFor), otherwise those blocks are
     * zeroed.
     */
    #[GameRoute('game', 'game/player-card', 'playerCard')]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        // dialog request (ajax): bare card; opened directly (middle click, link): inside the game shell
        $this->setWindow($this->state->runtime->ajax ? 'popup' : 'full');

        $PlayerID 	= $this->input()->int('id');

        $exists = $this->db->selectSingle('SELECT id FROM %%USERS%% WHERE id = :playerID AND universe = :universe;', [
            ':playerID' => $PlayerID,
            ':universe' => $this->universe()->current(),
        ], 'id');
        if (!$exists) {
            // missing or unknown id: the game's usual message page instead of an exception
            return $this->messageResponse($LNG['bu_player_not_exist']);
        }

        $stats = $this->get_stats($PlayerID);
        $showPlayer = true;
        $showTotal = true;
        $viewer = $this->models()->user($USER);
        $showBuild = $this->protectionRules()->spyTechAllowedFor(2, $PlayerID, $viewer);
        $showTech = $this->protectionRules()->spyTechAllowedFor(4, $PlayerID, $viewer);
        $showDef = $this->protectionRules()->spyTechAllowedFor(6, $PlayerID, $viewer);
        $showFleet = $this->protectionRules()->spyTechAllowedFor(6, $PlayerID, $viewer);
        $showBattle = $this->protectionRules()->spyTechAllowedFor(6, $PlayerID, $viewer);
        $showHonor = $this->protectionRules()->spyTechAllowedFor(6, $PlayerID, $viewer);

        $player_data = $this->get_player_data($stats, $showPlayer);
        $total_data = $this->get_total_data($stats, $showTotal);
        $build_data = $this->get_build_data($stats, $showBuild);
        $tech_data = $this->get_tech_data($stats, $showTech);
        $def_data = $this->get_def_data($stats, $showDef);
        $fleet_data = $this->get_fleet_data($stats, $showFleet);
        $battle_stats = $this->get_battle_stats($stats, $showBattle);
        $units = $this->get_units($stats, $showBattle);
        $real_units = $this->get_real_units($stats, $showBattle);
        $blocked = $this->playerLookup()->get_block_status($PlayerID, $USER['id']);
        $authlevel = $this->playerLookup()->get_authlevel($PlayerID);
        $honor_data = $this->get_honor_data($PlayerID, $stats, $showHonor);

        $context = array_merge(
            $this->identityVariables($PlayerID, $player_data),
            $this->rankingVariables($total_data, $tech_data, $build_data, $def_data, $fleet_data),
            $this->battleVariables($player_data, $battle_stats, $units, $real_units),
            [
                'achievements'	        => $this->get_achievements($PlayerID),
                'showBuild'             => $showBuild,
                'showTech'              => $showTech,
                'showDef'               => $showDef,
                'showFleet'             => $showFleet,
                'showBattle'            => $showBattle,
                'showHonor'             => $showHonor,
                'blockTrade'            => $blocked['block_trade'],
                'blockDm'               => $blocked['block_dm'],
                'targetRights'          => $authlevel,
            ],
            $this->honorVariables($battle_stats, $honor_data),
        );
        if ($this->hiddenInStatistics($PlayerID)) {
            // like the statistics page: no ranks for players the statistics hide (they are ranked last internally)
            foreach (['total_rank', 'tech_rank', 'build_rank', 'defs_rank', 'fleet_rank'] as $key) {
                $context[$key] = '-';
            }
        }

        $context['cosmCard'] = $this->cosmeticsCard($PlayerID);

        return $this->render('game/page.playerCard.default.html.twig', $context);
    }

    /**
     * Badges, active title and name colour of the viewed player (optional feature cosmetics_enabled), null while it is off.
     *
     * @return array{badges: list<array<string, mixed>>, title: ?string, colorClass: ?string}|null
     */
    private function cosmeticsCard(int $playerId): ?array
    {
        if (!(bool) ($this->configs->get((int) $this->state->USER['universe'])->cosmetics_enabled ?? false)) {
            return null;
        }

        return (new \App\Game\Engagement\Cosmetics\CosmeticCatalog($this->state->LNG))
            ->card((new \App\Game\Engagement\Cosmetics\Cosmetics($this->db))->owned($playerId));
    }

    /**
     * Same rule as Statbuilder::isHidden(): staff at or above stat_level while the staff-hiding stat mode (1 or 2) is
     * on, or a banned player.
     */
    private function hiddenInStatistics(int $playerId): bool
    {
        $row = $this->db->selectSingle('SELECT authlevel, bana FROM %%USERS%% WHERE id = :id;', [':id' => $playerId]);
        $config = $this->configs->get();

        return !empty($row['bana']) || (in_array($config->stat, [1, 2]) && $row['authlevel'] >= $config->stat_level);
    }

    /**
     * Template variables that identify the card: viewed and own player id, name, home planet with coordinates and the alliance.
     *
     * @param array<string, mixed> $player_data from get_player_data()
     * @return array<string, mixed>
     */
    private function identityVariables(int $PlayerID, array $player_data): array
    {
        return [
            'id'			        => $PlayerID,
            'yourid'		        => $this->state->USER['id'],
            'name'			        => $player_data['username'],
            'homeplanet'	        => $player_data['planetname'],
            'galaxy'		        => $player_data['galaxy'],
            'system'		        => $player_data['system'],
            'planet'		        => $player_data['planet'],
            'allyid'		        => $player_data['ally_id'],
            'allyname'		        => $player_data['ally_name'],
        ];
    }

    /**
     * Rank, points and percentage per ranking (research, buildings, defense, fleet, total), formatted for the template; a percentage of 0
     * stays the string "0,00".
     *
     * @param array<string, mixed> $total_data
     * @param array<string, mixed> $tech_data
     * @param array<string, mixed> $build_data
     * @param array<string, mixed> $def_data
     * @param array<string, mixed> $fleet_data
     * @return array<string, mixed>
     */
    private function rankingVariables(array $total_data, array $tech_data, array $build_data, array $def_data, array $fleet_data): array
    {
        return [
            'tech_rank'             => $this->viewHelpers()->prettyNumber($tech_data['tech_rank']),
            'tech_points'           => $this->viewHelpers()->prettyNumber($tech_data['tech_points']),
            'build_rank'            => $this->viewHelpers()->prettyNumber($build_data['build_rank']),
            'build_points'          => $this->viewHelpers()->prettyNumber($build_data['build_points']),
            'defs_rank'             => $this->viewHelpers()->prettyNumber($def_data['defs_rank']),
            'defs_points'           => $this->viewHelpers()->prettyNumber($def_data['defs_points']),
            'fleet_rank'            => $this->viewHelpers()->prettyNumber($fleet_data['fleet_rank']),
            'fleet_points'          => $this->viewHelpers()->prettyNumber($fleet_data['fleet_points']),
            'total_rank'            => $this->viewHelpers()->prettyNumber($total_data['total_rank']),
            'total_points'          => $this->viewHelpers()->prettyNumber($total_data['total_points']),
            'tech_percent'          => $tech_data['tech_percent'] == 0 ? '0,00' : round($tech_data['tech_percent'], 2),
            'build_percent'         => $build_data['build_percent'] == 0 ? '0,00' : round($build_data['build_percent'], 2),
            'defs_percent'          => $def_data['defs_percent'] == 0 ? '0,00' : round($def_data['defs_percent'], 2),
            'fleet_percent'         => $fleet_data['fleet_percent'] == 0 ? '0,00' : round($fleet_data['fleet_percent'], 2),
        ];
    }

    /**
     * Battle record: won/lost/drawn fights with percentages, debris and unit losses (both the counted and the real values) and the
     * "destroyed" texts with the player name.
     *
     * @param array<string, mixed> $player_data
     * @param array<string, mixed> $battle_stats
     * @param array<string, mixed> $units
     * @param array<string, mixed> $real_units
     * @return array<string, mixed>
     */
    private function battleVariables(array $player_data, array $battle_stats, array $units, array $real_units): array
    {
        $LNG = $this->state->LNG;

        return [
            'playerdestory'         => $LNG->format('pl_destroy', $player_data['username']),
            'realdestory'           => $LNG->format('pl_destroy_real', $player_data['username']),
            'wons'                  => $this->viewHelpers()->prettyNumber($battle_stats['wons']),
            'loos'                  => $this->viewHelpers()->prettyNumber($battle_stats['loos']),
            'draws'                 => $this->viewHelpers()->prettyNumber($battle_stats['draws']),
            'kbmetal'               => $this->viewHelpers()->prettyNumber($units['kbmetal']),
            'kbcrystal'             => $this->viewHelpers()->prettyNumber($units['kbcrystal']),
            'lostunits'             => $this->viewHelpers()->prettyNumber($units['lostunits']),
            'desunits'              => $this->viewHelpers()->prettyNumber($units['desunits']),
            'realdesunits'          => $this->viewHelpers()->prettyNumber($real_units["destroyed"]),
            'reallostunits'         => $this->viewHelpers()->prettyNumber($real_units["lost"]),
            'realmetal'             => $this->viewHelpers()->prettyNumber($real_units["debris_metal"]),
            'realcrystal'           => $this->viewHelpers()->prettyNumber($real_units["debris_crystal"]),
            'totalfights'           => $this->viewHelpers()->prettyNumber($battle_stats['totalfights']),
            'siegprozent'           => round($battle_stats["siegprozent"], 2),
            'loosprozent'           => round($battle_stats["loosprozent"], 2),
            'drawsprozent'          => round($battle_stats["drawsprozent"], 2),
        ];
    }

    /**
     * Honor block: rank, points and honorable/dishonorable/neutral fights with their percentages (neutral = all fights minus the other two).
     *
     * @param array<string, mixed> $battle_stats
     * @param array<string, mixed> $honor_data
     * @return array<string, mixed>
     */
    private function honorVariables(array $battle_stats, array $honor_data): array
    {
        return [
            'honor_rank'            => $this->viewHelpers()->prettyNumber($honor_data['honor_rank']),
            'honorpoints'           => $this->viewHelpers()->prettyNumber($honor_data['honorpoints']),
            'honorable_fights'      => $this->viewHelpers()->prettyNumber($honor_data['honorable_fights']),
            'dishonorable_fights'   => $this->viewHelpers()->prettyNumber($honor_data['dishonorable_fights']),
            'neutral_fights'        => $this->viewHelpers()->prettyNumber($battle_stats['totalfights'] - $honor_data['honorable_fights'] - $honor_data['dishonorable_fights']),
            'honorable_percent'     => round($honor_data['honorable_percent'], 2),
            'dishonorable_percent'  => round($honor_data['dishonorable_percent'], 2),
            'neutral_percent'       => round($honor_data['neutral_percent'], 2),
        ];
    }

    /**
     * Achievements the player $userid has unlocked.
     */
    public function get_achievements($userid)
    {
        $db = $this->db;
        $sql = "SELECT a.id, a.name, a.image FROM %%ACHIEVEMENTS%% a 
				INNER JOIN %%USERS_TO_ACHIEVEMENTS%% ua ON ua.achievementID = a.id
				WHERE ua.userID = :userID;";
        $achievementResult = $db->select($sql, [
            ':userID'	=> $userid,
        ]);
        return $achievementResult;
    }

    /**
     * Destroyed and lost units (real counts) and the debris produced, from the stats row; zeros when $show is false.
     */
    public function get_real_units($stats, $show = true)
    {
        $units_real_destroyed = 0;
        $units_real_lost = 0;
        $debris_real_metal = 0;
        $debris_real_crystal = 0;

        if ($show) {
            $units_real_destroyed = $stats['desunits_real'];
            $units_real_lost = $stats['lostunits_real'];
            $debris_real_metal = $stats['kbmetal_real'];
            $debris_real_crystal = $stats['kbcrystal_real'];
        }

        return [
            "destroyed"         => $units_real_destroyed,
            "lost"              => $units_real_lost,
            "debris_metal"      => $debris_real_metal,
            "debris_crystal"    => $debris_real_crystal];
    }

    /**
     * Destroyed and lost units (battle points) and debris metal/crystal from the stats row; zeros when $show is false.
     */
    public function get_units($stats, $show = true)
    {
        $kbmetal = 0;
        $kbcrystal = 0;
        $lostunits = 0;
        $desunits = 0;

        if ($show) {
            $kbmetal = $stats['kbmetal'];
            $kbcrystal = $stats['kbcrystal'];
            $lostunits = $stats['lostunits'];
            $desunits = $stats['desunits'];
        }

        return [
            "kbmetal"       => $kbmetal,
            "kbcrystal"     => $kbcrystal,
            "lostunits"     => $lostunits,
            "desunits"      => $desunits,
        ];
    }

    /**
     * Battles won, lost and drawn with their total and percentages; zeros when $show is false.
     */
    public function get_battle_stats($stats, $show = true)
    {
        if ($show) {
            $wons = $stats['wons'];
            $loos = $stats['loos'];
            $draws = $stats['draws'];
        } else {
            $wons = 0;
            $loos = 0;
            $draws = 0;
        }
        $totalfights = $wons + $loos + $draws;

        if ($totalfights == 0) {
            $siegprozent                = 0;
            $loosprozent                = 0;
            $drawsprozent               = 0;
        } else {
            $siegprozent                = 100 / $totalfights * $stats['wons'];
            $loosprozent                = 100 / $totalfights * $stats['loos'];
            $drawsprozent               = 100 / $totalfights * $stats['draws'];
        }
        return [
            "totalfights"   => $totalfights,
            "siegprozent"   => $siegprozent,
            "loosprozent"   => $loosprozent,
            "drawsprozent"  => $drawsprozent,
            "wons"          => $wons,
            "loos"          => $loos,
            "draws"         => $draws,
        ];
    }

    /**
     * Statistics row of player $playerId: name, home coordinates, alliance, ranks and points per category and their share of the total points.
     */
    public function get_stats($playerId)
    {
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $sql = "SELECT
				u.username, u.galaxy, u.system, u.planet, u.ally_id,
				p.name,
				s.tech_rank, s.tech_points, s.build_rank, s.build_points, s.defs_rank, s.defs_points, s.fleet_rank, s.fleet_points, s.total_rank, s.total_points,
				s.honor_rank,
                a.ally_name, (s.tech_points / s.total_points * 100) as tech_percent, (s.build_points / s.total_points * 100) as build_percent,
				(s.defs_points / s.total_points * 100) as defs_percent, (s.fleet_points / s.total_points * 100) as fleet_percent
                FROM %%USERS%% u
				INNER JOIN %%PLANETS%% p ON p.id = u.id_planet
				LEFT JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = 1
				LEFT JOIN %%ALLIANCE%% a ON a.id = u.ally_id
				WHERE u.id = :playerID AND u.universe = :universe;";
        $query = $db->selectSingle($sql, [
            ':universe'	=> $this->universe()->current(),
            ':playerID'	=> $playerId,
        ]);

        if (!$query) {
            throw new Exception('the requested player does not exist');
        }

        $query += $statsHandler->getMultipleStats($playerId, [
            AdvancedStatsEnum::WONS,
            AdvancedStatsEnum::LOOS,
            AdvancedStatsEnum::DRAWS,
            AdvancedStatsEnum::KBMETAL,
            AdvancedStatsEnum::KBCRYSTAL,
            AdvancedStatsEnum::LOSTUNITS,
            AdvancedStatsEnum::DESUNITS,
            AdvancedStatsEnum::KBMETAL_REAL,
            AdvancedStatsEnum::KBCRYSTAL_REAL,
            AdvancedStatsEnum::LOSTUNITS_REAL,
            AdvancedStatsEnum::DESUNITS_REAL,
        ], true);

        return $query;
    }

    /**
     * Basic data block of the card: name, home planet name and coordinates, alliance id and name.
     */
    public function get_player_data($data)
    {
        return [
            "username"      => $data['username'],
            "planetname"    => $data['name'],
            "galaxy"        => $data['galaxy'],
            "system"        => $data['system'],
            "planet"        => $data['planet'],
            "ally_id"       => $data['ally_id'],
            "ally_name"     => $data['ally_name'],
        ];
    }

    /**
     * Research rank, points and share of the total points; zeros when $show is false.
     */
    public function get_tech_data($data, $show = true)
    {
        if ($show) {
            $tech_rank = $data['tech_rank'];
            $tech_points = $data['tech_points'];
            $tech_percent = $data['tech_percent'];
        } else {
            $tech_rank = 0;
            $tech_points = 0;
            $tech_percent = 0;
        }
        return [
            "tech_rank"     => $tech_rank,
            "tech_points"   => $tech_points,
            "tech_percent"  => $tech_percent,
        ];
    }

    /**
     * Building rank, points and share of the total points; zeros when $show is false.
     */
    public function get_build_data($data, $show = true)
    {
        if ($show) {
            $build_rank = $data['build_rank'];
            $build_points = $data['build_points'];
            $build_percent = $data['build_percent'];
        } else {
            $build_rank = 0;
            $build_points = 0;
            $build_percent = 0;
        }
        return [
            "build_rank"    => $build_rank,
            "build_points"  => $build_points,
            "build_percent" => $build_percent,
        ];
    }

    /**
     * Defense rank, points and share of the total points; zeros when $show is false.
     */
    public function get_def_data($data, $show = true)
    {
        if ($show) {
            $defs_rank = $data['defs_rank'];
            $defs_points = $data['defs_points'];
            $defs_percent = $data['defs_percent'];
        } else {
            $defs_rank = 0;
            $defs_points = 0;
            $defs_percent = 0;
        }
        return [
            "defs_rank"     => $defs_rank,
            "defs_points"   => $defs_points,
            "defs_percent"  => $defs_percent,
        ];
    }

    /**
     * Fleet rank, points and share of the total points; zeros when $show is false.
     */
    public function get_fleet_data($data, $show = true)
    {
        if ($show) {
            $fleet_rank = $data['fleet_rank'];
            $fleet_points = $data['fleet_points'];
            $fleet_percent = $data['fleet_percent'];
        } else {
            $fleet_rank = 0;
            $fleet_points = 0;
            $fleet_percent = 0;
        }
        return [
            "fleet_rank"    => $fleet_rank,
            "fleet_points"  => $fleet_points,
            "fleet_percent" => $fleet_percent,
        ];
    }
    /**
     * Honor block: honor rank, honor points, honorable/dishonorable fight counts and their share of all fights (advanced stats); zeros when $show is false.
     */
    public function get_honor_data($playerId, $statRow, $show = true)
    {
        /*
            Top 1   der Ehrenpunktetabelle (mindestens 50.000 EP):  Gott-Imperator
            Top 10% der Ehrenpunktetabelle (mindestens 15.000 EP):  Groß-Imperator
            Top 20% der Ehrenpunktetabelle (mindestens 2.500 EP):        Imperator
            Top 30% der Ehrenpunktetabelle (mindestens 500 EP):           Starlord
            Low 30% der Ehrenpunktetabelle (mindestens -500 EP):    Bandit
            Low 20% der Ehrenpunktetabelle (mindestens -2.500 EP):  Banditenlord
            Low 10% der Ehrenpunktetabelle (mindestens -15.000 EP): Banditenkönig
            Low 1   der Ehrenpunktetabelle (mindestens -50.000 EP): Banditen-Imperator
        */
        if (!$show) {
            return [
                "honor_rank"            => 0,
                "honorpoints"           => 0,
                "honorable_fights"      => 0,
                "dishonorable_fights"   => 0,
                'honorable_percent'     => 0,
                'dishonorable_percent'  => 0,
                'neutral_percent'       => 0,
            ];
        }

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $data = $statsHandler->getMultipleStats($playerId, [
            AdvancedStatsEnum::HONORPOINTS,
            AdvancedStatsEnum::HONORABLE_FIGHTS,
            AdvancedStatsEnum::DISHONORABLE_FIGHTS,
            AdvancedStatsEnum::WONS,
            AdvancedStatsEnum::LOOS,
            AdvancedStatsEnum::DRAWS,
        ], true);
        $data['honor_rank'] = (int) ($statRow['honor_rank'] ?? 0);

        $totalfights = $data['wons'] + $data['loos'] + $data['draws'];
        if ($totalfights == 0) {
            $honorable_percent      = 0;
            $dishonorable_percent   = 0;
            $neutral_percent        = 0;
        } else {
            $honorable_percent      = 100 / $totalfights * $data['honorable_fights'];
            $dishonorable_percent   = 100 / $totalfights * $data['dishonorable_fights'];
            $neutral_percent        = 100 / $totalfights * ($totalfights - $data['honorable_fights'] - $data['dishonorable_fights']);
        }
        return [
            "honor_rank"            => $data['honor_rank'],
            "honorpoints"           => $data['honorpoints'],
            "honorable_fights"      => $data['honorable_fights'],
            "dishonorable_fights"   => $data['dishonorable_fights'],
            'honorable_percent'     => $honorable_percent,
            'dishonorable_percent'  => $dishonorable_percent,
            'neutral_percent'       => $neutral_percent,
        ];
    }

    /**
     * Total points block of the card (points, rank).
     */
    public function get_total_data($data)
    {
        return [
            "total_rank"    => $data['total_rank'],
            "total_points"  => $data['total_points'],
        ];
    }

    /**
     * Toggle player block status
     *
     * returns staus code 200 on success
     */
    #[GameRoute('game', 'game/player-card/block', 'playerCard', 'toggleBlock', ['POST'])]
    public function toggleBlock(): Response
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $this->setWindow('ajax');

        $PlayerID 	    = $this->input()->int('id');
        $blockTrade     = min(1, max(0, $this->input()->int('blockTrade')));
        $blockDm        = min(1, max(0, $this->input()->int('blockDm')));

        $sql = "SELECT id FROM %%USERS_BLOCKLIST%% 
                WHERE blocking_user = :userID AND blocked_user = :targetID;";
        $blockid = $db->selectSingle($sql, [
            ':userID'       => $USER['id'],
            ':targetID'     => $PlayerID,
        ], 'id');
        if ($blockid && $blockTrade == 0 && $blockDm == 0) {
            $sql =  "DELETE FROM %%USERS_BLOCKLIST%% 
		        WHERE blocking_user = :userID AND blocked_user = :targetID;";
            $db->delete($sql, [
                ':userID'             => $USER['id'],
                ':targetID'           => $PlayerID,
            ]);
        } elseif ($blockid) {
            $sql =  "UPDATE %%USERS_BLOCKLIST%% SET
		        block_dm		= :block_dm,
		        block_trade	    = :block_trade
		        WHERE blocking_user = :userID AND blocked_user = :targetID;";
            $db->update($sql, [
                ':block_dm'           => $blockDm,
                ':block_trade'        => $blockTrade,
                ':userID'             => $USER['id'],
                ':targetID'           => $PlayerID,
            ]);
        } elseif (!$this->canBlock((int) $PlayerID)) {
            return $this->sendData(400, 'Invalid player');
        } else {
            $sql = "INSERT INTO %%USERS_BLOCKLIST%% SET
                blocking_user	= :userID,
                blocked_user	= :targetID,
                block_dm		= :block_dm,
                block_trade	    = :block_trade;";
            $db->insert($sql, [
                ':userID'       => $USER['id'],
                ':targetID'     => $PlayerID,
                ':block_dm'     => $blockDm,
                ':block_trade'  => $blockTrade,
            ]);
        }
        return $this->sendData(200, 'Successfully changed block settings');
    }

    /**
     * A new block-list entry needs another player of this universe and stays below MAX_BLOCKS entries per player.
     */
    private function canBlock(int $targetId): bool
    {
        $userId = $this->state->USER['id'];
        if ($targetId <= 0 || $targetId == $userId) {
            return false;
        }

        $sql = 'SELECT (SELECT COUNT(*) FROM %%USERS%% WHERE id = :targetID AND universe = :universe) AS target,
            (SELECT COUNT(*) FROM %%USERS_BLOCKLIST%% WHERE blocking_user = :userID) AS blocks;';
        $row = $this->db->selectSingle($sql, [
            ':targetID' => $targetId,
            ':universe' => $this->universe()->current(),
            ':userID' => $userId,
        ]);

        return $row['target'] > 0 && $row['blocks'] < self::MAX_BLOCKS;
    }

    /**
     * json answer
     */
    private function sendData($Code, $Message): Response
    {
        return $this->jsonResponse([
            'code' => $Code,
            'mess' => $Message,
        ]);
    }
}
