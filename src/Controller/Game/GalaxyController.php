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

use Symfony\Component\HttpFoundation\Response;
use App\Routing\GameRoute;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\GalaxyRows;

/** Galaxy view: planets, debris and players of one solar system. */
#[\App\Controller\RequireModule('MODULE_RESEARCH')]
class GalaxyController extends AbstractGameController
{
    /**
     * Galaxy view of one solar system. Request: galaxy and system (clamped to 1..max_galaxy / 1..max_system; default the current planet's), or the
     * arrow buttons galaxyLeft/galaxyRight/systemLeft/systemRight that move one step from them (wrapping around at the borders). Looking at a system
     * other than the planet's own costs `deuterium_cost_galaxy` deuterium (removed from the planet and counted in the advanced stats); without enough
     * deuterium a message is shown instead. Shows the system's rows (GalaxyRows), which positions are colonizable, the player's own pirate base of
     * the system, hidden-debris threshold, the missile flight duration and the player's fleet slots, probes, recyclers and missiles.
     */
    #[GameRoute('game', 'game/galaxy/{galaxy}/{system}', 'galaxy', 'show', requirements: ['galaxy' => '\d+', 'system' => '\d+'], defaults: ['galaxy' => null, 'system' => null])]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $config = $this->configs->get();

        [$galaxy, $system] = $this->requestedSystem($config);
        if ($refused = $this->chargeForView($galaxy, $system, $config)) {
            return $refused;
        }

        $this->addScript('galaxy.js');
        return $this->render('game/page.galaxy.default.html.twig', $this->systemContext($galaxy, $system, $config) + [
            'servertime' => $this->viewHelpers()->formatDate("M D d H:i:s", $this->state->runtime->timestamp, $USER['timezone']),
        ]);
    }

    /**
     * One solar system as an HTML fragment (the `#galaxy-view` block of the page) for the client-side navigation of galaxy.js: same
     * request (galaxy, system, arrow buttons), same deuterium charge and rules as show(), but without the layout. Without enough
     * deuterium the answer is an empty 409 and the script opens the full page, which shows the message.
     */
    #[GameRoute('game', 'game/galaxy-system', 'galaxy', 'rows')]
    public function rows(): Response
    {
        $config = $this->configs->get();

        [$galaxy, $system] = $this->requestedSystem($config);
        if ($this->chargeForView($galaxy, $system, $config)) {
            return new Response('', Response::HTTP_CONFLICT, ['X-Galaxy-Fragment' => '1']);
        }

        $response = $this->gameView()->response('game/galaxy.system.html.twig', $this->systemContext($galaxy, $system, $config));
        $response->headers->set('X-Galaxy-Fragment', '1');

        return $response;
    }

    /**
     * Template variables of one system (templates/game/galaxy.system.html.twig): its rows (GalaxyRows), which positions are colonizable,
     * the player's own pirate base of the system, hidden-debris threshold, the missile flight duration and the player's fleet slots,
     * probes, recyclers and missiles.
     *
     * @return array<string, mixed>
     */
    private function systemContext(int $galaxy, int $system, object $config): array
    {
        $USER = $this->state->USER;
        $e = $this->elements();
        $LNG = $this->state->LNG;

        $galaxyRows	= new GalaxyRows($this->db, $this->configs, $this->state);
        $galaxyRows->setGalaxy($galaxy);
        $galaxyRows->setSystem($system);
        $Result	= $galaxyRows->getGalaxyData();

        $recyclerCount = 0;
        foreach ($e->shipTypeIds(SHIP_TYPE_RECYCLER) as $elementID) {
            $recyclerCount += $this->state->PLANET[$e->column($elementID)];
        }

        return [
            'GalaxyRows'				=> $Result,
            'pirateBase'                => $this->pirateBaseIn($galaxy, $system),
            'colonizable'               => $this->colonizablePositions($Result, $config),
            'planetcount'				=> $LNG->format('gl_populed_planets', count($Result)),
            'galaxy'					=> $galaxy,
            'system'					=> $system,
            'planet'					=> '',
            'type'						=> null, // never set in this page (the former variable was undefined)
            'maxfleetcount'				=> $this->fleetFunctions()->getCurrentFleets($USER['id']),
            'fleetmax'					=> $this->fleetFunctions()->getMaxFleetSlots($USER),
            'currentmip'				=> $this->state->PLANET[$e->column(INTERPLANETARY_MISSILE)],
            'recyclers'   				=> $recyclerCount,
            'spyprobes'   				=> $this->state->PLANET[$e->column(SHIP_PROBE)],
            'settings_fleetactions'		=> $USER['settings_fleetactions'],
            'planet_type' 				=> $this->state->PLANET['planet_type'],
            'max_planets'               => $config->max_planets,
            'missileSelector'			=> $this->missileSelector(),
            'hiddenDebris'				=> $e->cost(SHIP_PROBE)->metalCrystal() * ($config->fleet_debris_percentage / 100),
            'ShortStatus'				=> $this->shortStatusLabels(),
            'duration'                  => $this->fleetFunctions()->getMIPDuration($this->state->PLANET['system'], $system),
            'enablePlanetStyles'        => $USER['enable_planet_styles'],
            'planetDataNew'             => $this->planetManager()->getPlanetDataPlanetConfig(),
        ];
    }

    /**
     * Short labels of the player status markers (vacation, banned, inactive, newbie, strong, enemy, friend, member).
     *
     * @return array<string, string>
     */
    private function shortStatusLabels(): array
    {
        $LNG = $this->state->LNG;

        return [
            'vacation'					=> $LNG['gl_short_vacation'],
            'banned'					=> $LNG['gl_short_ban'],
            'inactive'					=> $LNG['gl_short_inactive'],
            'longinactive'				=> $LNG['gl_short_long_inactive'],
            'noob'						=> $LNG['gl_short_newbie'],
            'strong'					=> $LNG['gl_short_strong'],
            'enemy'						=> $LNG['gl_short_enemy'],
            'friend'					=> $LNG['gl_short_friend'],
            'member'					=> $LNG['gl_short_member'],
        ];
    }

    /**
     * The system to show: request galaxy/system (default the current planet's) clamped to the universe size, then moved one step by the arrow
     * buttons galaxyLeft/galaxyRight/systemLeft/systemRight, wrapping around at the borders.
     *
     * @return array{0: int, 1: int} galaxy, system
     */
    private function requestedSystem(object $config): array
    {
        $galaxyLeft		= $this->input()->string('galaxyLeft');
        $galaxyRight	= $this->input()->string('galaxyRight');
        $systemLeft		= $this->input()->string('systemLeft');
        $systemRight	= $this->input()->string('systemRight');
        $galaxy			= min(max($this->input()->int('galaxy', (int) $this->state->PLANET['galaxy']), 1), $config->max_galaxy);
        $system			= min(max($this->input()->int('system', (int) $this->state->PLANET['system']), 1), $config->max_system);

        if (!empty($galaxyLeft)) {
            $galaxy	= $galaxy - 1;
            if ($galaxy < 1) {
                $galaxy = $config->max_galaxy;
            }
        } elseif (!empty($galaxyRight)) {
            $galaxy	= $galaxy + 1;
            if ($galaxy > $config->max_galaxy) {
                $galaxy = 1;
            }
        }

        if (!empty($systemLeft)) {
            $system	= $system - 1;
            if ($system < 1) {
                $system = $config->max_system;
            }
        } elseif (!empty($systemRight)) {
            $system	= $system + 1;
            if ($system > $config->max_system) {
                $system = 1;
            }
        }

        return [$galaxy, $system];
    }

    /**
     * Looking at a system other than the planet's own costs `deuterium_cost_galaxy` deuterium (removed from the planet,
     * counted in the advanced stats). Without enough deuterium the galaxy achievements are checked and the message page
     * is returned (null: viewing is allowed).
     */
    private function chargeForView(int $galaxy, int $system, object $config): ?Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        if ($galaxy != $this->state->PLANET['galaxy'] || $system != $this->state->PLANET['system']) {
            if ($this->state->PLANET['deuterium'] < $config->deuterium_cost_galaxy) {
                $this->miscAchievements()->checkGalaxyAchievements($USER['id']);
                return $this->messageResponse($LNG['gl_no_deuterium_to_view_galaxy'], [[
                    'label'	=> $LNG['sys_back'],
                    'url'	=> $this->router->generate('game_galaxy_show'),
                ]]);
            } else {
                $resources = [RESOURCE_DEUT => $config->deuterium_cost_galaxy];
                $this->ecoObj->removeResources($this->state->PLANET['id'], $resources, $this->state->PLANET);
                $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
                $statsHandler->increment($USER['id'], AdvancedStatsEnum::GALAXY_SCROLL_SPENDING, $config->deuterium_cost_galaxy, (int) $USER['universe']);
            }
        }

        return null;
    }

    /**
     * Options of the missile target selector: all defenses or one specific defense (including interceptor missiles).
     *
     * @return array<int, string> element id (0 = all) => name
     */
    private function missileSelector(): array
    {
        $LNG = $this->state->LNG;
        $targetDefensive    = $this->elements()->ids('defense');
        $targetDefensive[]	= INTERCEPTOR_MISSILE;
        $missileSelector[0]	= $LNG['gl_all_defenses'];
        foreach ($targetDefensive as $Element) {
            $missileSelector[$Element] = $LNG['tech'][$Element];
        }

        return $missileSelector;
    }

    /**
     * CSS class per planet position of the system: colorPositive where a colony may be founded (the player's research allows
     * the position, he is below his planet limit and it is free), colorNegative otherwise.
     *
     * @param array<int, mixed> $Result occupied positions of the system
     * @return array<int, string> position => class
     */
    private function colonizablePositions(array $Result, object $config): array
    {
        $colonizable = [];
        // allowPlanetPosition() depends only on the distance to the nearest edge (1, 2, 3, or further in), so
        // each tier is asked once instead of once per position
        $allowedByTier = [];
        // at the planet limit the colonisation is refused on arrival, so no slot is offered
        $atLimit = $this->planetPositions()->currentPlanetCount($this->state->USER) >= $this->planetPositions()->maxPlanetCount($this->state->USER);
        for ($i = 1; $i <= $config->max_planets; $i++) {
            $tier = min($i - 1, $config->max_planets - $i, 3);
            $allowedByTier[$tier] ??= $this->planetPositions()->allowPlanetPosition($i, $this->state->USER);
            $allowPlanetPosition = $allowedByTier[$tier];
            if (!$atLimit && $allowPlanetPosition && !isset($Result[$i])) {
                $colonizable[$i] = 'colorPositive';
            } else {
                $colonizable[$i] = 'colorNegative';
            }
        }

        return $colonizable;
    }

    /**
     * The player's own pirate base in this system (with its name, the available missions and the image), or null when the
     * pirate base module is off or there is none (null / false).
     *
     * @return array<string, mixed>|false|null
     */
    private function pirateBaseIn(int $galaxy, int $system): array|false|null
    {
        $LNG = $this->state->LNG;
        $pirateBase = null;
        if ($this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES)) {
            $sql = "SELECT `id`, `planet_type` as 'type', `der_metal` as 'metal', `der_crystal` as 'crystal' FROM %%PIRATE_BASES%% 
                WHERE `galaxy` = :galaxy AND `system` = :system AND `id_owner` = :userId";
            $pirateBase = $this->db->selectSingle($sql, [
                ':galaxy'   => $galaxy,
                ':system'   => $system,
                ':userId'   => $this->state->USER['id'],
            ]);

            if (!empty($pirateBase)) {
                $pirateBase['name'] = $LNG['type_planet_4'];
                $pirateBase['missions'] = [
                    20 => $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ATTACK),
                    21 => $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_SPY),
                    22 => $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_RECYCLE),
                ];
                $THEME = $this->state->THEME;
                $pirateBase['image'] = $THEME->asset("planeten/pirate_base.jpg");
            }
        }

        return $pirateBase;
    }
}
