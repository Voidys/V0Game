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

namespace App\Game\Missions;

namespace App\Game\Missions;

use App\Game\Enum\MessageType;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Core\ExpeditionDeckHandler;
use App\Game\Core\Language;
use App\Game\Engagement\Relics\RelicDrops;
use App\Game\Engagement\Stories\ExpeditionStories;
use App\Game\Missions\Expedition\ExpeditionCombatEvent;
use App\Game\Missions\Expedition\ExpeditionContext;
use App\Game\Missions\Expedition\ExpeditionResourceEvent;
use App\Game\Missions\Expedition\ExpeditionShipEvent;
use App\Game\Missions\Expedition\ExpeditionSize;

/**
 * Fleet mission "expedition": the fleet flies out, stays at the target position where one event card is drawn
 * (resources, ships, pirates, aliens, black hole, faster/slower return, nothing) and flies home.
 *
 * The events are handled by the classes in App\Game\Missions\Expedition; this class draws the card, applies
 * the simple events (black hole, fast, slow, nothing), builds the report message and sends it, and handles the return.
 */
class MissionCaseExpedition extends MissionFunctions implements MissionInterface
{
    private Language $LNG;
    private ExpeditionContext $ctx;

    /**
     * Loads the owner's language and builds the ExpeditionContext that lets the event classes read the fleet and
     * change its columns (UpdateFleet / UpdateFleetLog / KillFleet) without knowing this mission class.
     *
     * @param array<string, mixed> $fleet
     */
    public function __construct(array $fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet = $fleet;
        $this->LNG = $this->getLanguage(null, $this->_fleet['owner']);
        $this->LNG->includeData(['INGAME']);
        $this->ctx = new ExpeditionContext(
            $this->LNG,
            $this->statsHandler,
            fn(): array => $this->_fleet,
            function (string $column, mixed $value): void {
                $this->UpdateFleet($column, $value);
            },
            function (string $column, mixed $value): void {
                $this->UpdateFleetLog($column, $value);
            },
            function (): void {
                $this->KillFleet();
            },
        );
    }

    /**
     * Arrival at the expedition position: the fleet starts its stay (FLEET_HOLD until stay_time) and cannot be
     * recalled any more (cannotCancel = 1). The event is resolved later in EndStayEvent().
     */
    public function TargetEvent(): void
    {
        $this->setState(FLEET_HOLD);
        $this->UpdateFleet('cannotCancel', 1);
        $this->SaveFleet();
    }

    /**
     * Draws the event card of this expedition. The expeditions that stayed at the same coordinates in the last day
     * deplete the position: up to 10 none, then 25/50/75 percent chance that nothing is found; a depleted draw gives the
     * "Nothing" card. Otherwise the card comes from the player's deck (ExpeditionDeckHandler). Also sets the logbook
     * text and the frequency class of the report.
     *
     * @return array{type: string, size: string} card
     */
    private function drawExpeditionEvent(): array
    {
        $LNG = $this->LNG;

        // Initialize deck handler
        $deckHandler = new ExpeditionDeckHandler($this->db, $this->configs, $this->state, $this->_fleet['owner'], $this->_fleet['universe']);

        // Get Expeditions count in this system for depletion feedback
        $sql = "SELECT COUNT(*) AS total FROM %%LOG_FLEETS%% 
                WHERE `end_galaxy` = :end_galaxy
                  AND `end_system` = :end_system
                  AND `end_planet` = :end_planet
                  AND `stay_time` > UNIX_TIMESTAMP(NOW() - INTERVAL 1 DAY)
                  AND `stay_time` <= :stay_time
                  AND `id` != :fleet_id
                  AND `universe` = :universe
                  AND `hasCanceled` = 0";

        $expeditionsCount = $this->db->selectSingle($sql, [
            'end_galaxy'  => $this->_fleet['end_galaxy'],
            'end_system'  => $this->_fleet['end_system'],
            'end_planet'  => $this->_fleet['end_planet'],
            'universe'    => $this->_fleet['universe'],
            'stay_time'   => $this->_fleet['stay_time'],
            'fleet_id'    => $this->_fleet['id'],
        ], 'total');

        // Depletion check
        $chanceDepleted = 0;
        if ($expeditionsCount <= 10) {
            $this->ctx->eventspy = "<10";
            $this->ctx->logbook = $LNG['sys_expe_depleted_not_' . random_int(1, 2)];
        } elseif ($expeditionsCount <= 25) {
            $chanceDepleted = 25;
            $this->ctx->eventspy = "<25";
            $this->ctx->logbook = $LNG['sys_expe_depleted_min_' . random_int(1, 3)];
        } elseif ($expeditionsCount <= 50) {
            $chanceDepleted = 50;
            $this->ctx->eventspy = "<50";
            $this->ctx->logbook = $LNG['sys_expe_depleted_med_' . random_int(1, 3)];
        } else {
            $chanceDepleted = 75;
            $this->ctx->eventspy = ">50";
            $this->ctx->logbook = $LNG['sys_expe_depleted_max_' . random_int(1, 3)];
        }

        $depleted = random_int(1, 100);
        if ($depleted <= $chanceDepleted) {
            $card = [
                'type' => 'Nothing',
                'size' => 'none',
            ];
        } else {
            // Draw a card from the deck
            $card = $deckHandler->drawCard();
        }

        return $card;
    }

    /**
     * Applies the drawn event to the fleet and returns what the report needs.
     *
     * @param array<int, int|float> $fleetArray ship id => amount of the fleet
     * @return array{message: string, color: string, label: string, factor: int} report text of the event, CSS colour name
     *         and label of the event line, speed factor (0 when not a Fast/Slow event)
     */
    private function applyEvent(string $eventType, string $eventSize, array $fleetArray): array
    {
        $LNG = $this->LNG;

        if ($eventType === 'Res') {
            return $this->sizedEvent('green', 'res', $eventSize, (new ExpeditionResourceEvent($this->db, $this->configs, $this->state, $this->ctx))->run());
        }
        if ($eventType === 'Ships') {
            return $this->sizedEvent('teal', 'ships', $eventSize, (new ExpeditionShipEvent($this->db, $this->configs, $this->state, $this->ctx))->run());
        }
        if ($eventType === 'Pirates') {
            $this->ctx->isEventPirate = true;
            return $this->sizedEvent('red', 'pirates', $eventSize, (new ExpeditionCombatEvent($this->db, $this->configs, $this->state, $this->ctx))->run());
        }
        if ($eventType === 'Aliens') {
            $this->ctx->isEventPirate = false;
            return $this->sizedEvent('red', 'aliens', $eventSize, (new ExpeditionCombatEvent($this->db, $this->configs, $this->state, $this->ctx))->run());
        }
        if ($eventType === 'BlackHole') {
            return $this->applyBlackHole($fleetArray);
        }
        if ($eventType === 'Fast' || $eventType === 'Slow') {
            return $this->applyReturnTimeChange($eventType === 'Fast', $eventSize);
        }

        // Nothing happens
        $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_NOTHING, 1);
        $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_NOTHING, $this->_fleet['stay_time']);
        return [
            'message' => $LNG['sys_expe_nothing_' . random_int(1, 8)],
            'color' => 'white',
            'label' => $LNG['Advanced_Stats']['expo_nothing'],
            'factor' => 0,
        ];
    }

    /**
     * Result array of an event whose label depends on the card size (translation key expo_<kind>_small|medium|large,
     * unknown sizes count as large).
     *
     * @return array{message: string, color: string, label: string, factor: int}
     */
    private function sizedEvent(string $color, string $kind, string $eventSize, string $message): array
    {
        $LNG = $this->LNG;
        $size = $eventSize === 'Small' ? 'small' : ($eventSize === 'Medium' ? 'medium' : 'large');

        return ['message' => $message, 'color' => $color, 'label' => $LNG['Advanced_Stats']['expo_' . $kind . '_' . $size], 'factor' => 0];
    }

    /**
     * Black hole: the fleet is lost (advanced stats, fleet deleted, expedition achievements checked).
     *
     * @param array<int, int|float> $fleetArray
     * @return array{message: string, color: string, label: string, factor: int}
     */
    private function applyBlackHole(array $fleetArray): array
    {
        $LNG = $this->LNG;
        $this->statsHandler->updateExpoLostAdvancedStats($this->_fleet['owner'], $fleetArray);
        $this->KillFleet();
        $message = $LNG['sys_expe_lost_fleet_' . random_int(1, 4)];
        $label = $LNG['Advanced_Stats']['expo_black_hole'];
        $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_BLACK_HOLE, 1);
        $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_BLACK_HOLE, $this->_fleet['stay_time']);
        $this->achievementGuard()->run(fn() => $this->miscAchievements()->checkExpoAchievements($this->_fleet['owner']));

        return ['message' => $message, 'color' => 'red', 'label' => $label, 'factor' => 0];
    }

    /**
     * Fast / slow: changes the return time of the fleet by the size factor of the card (fast: shortens the way back by
     * factor * 10 percent, at least 1 second; slow: adds factor hours).
     *
     * @return array{message: string, color: string, label: string, factor: int}
     */
    private function applyReturnTimeChange(bool $fast, string $eventSize): array
    {
        $LNG = $this->LNG;
        $normalBackTime = $this->_fleet['return_time'] - $this->_fleet['stay_time'];
        $factor = ExpeditionSize::speedFactor($eventSize);
        $this->ctx->eventvalue = '{"factor":' . $factor . "}";

        if ($fast) {
            // Less return time (faster)
            $endTime = $this->_fleet['stay_time'] + max(1, $normalBackTime * (1 - $factor / 10));
        } else {
            // More return time (slower)
            $endTime = $this->_fleet['stay_time'] + $normalBackTime + TIME_1_HOUR * $factor;
        }
        $this->UpdateFleet('return_time', $endTime);
        $this->UpdateFleetLog('return_time', $endTime);
        if ($fast) {
            $message = $LNG['sys_expe_time_fast_' . random_int(1, 3)];
            $label = $LNG['Advanced_Stats']['expo_fast'];
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_FAST, 1);
            $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_FAST, $this->_fleet['stay_time']);
        } else {
            $message = $LNG['sys_expe_time_slow_' . random_int(1, 6)];
            $label = $LNG['Advanced_Stats']['expo_slow'];
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_SLOW, 1);
            $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_SLOW, $this->_fleet['stay_time']);
        }

        return ['message' => $message, 'color' => 'yellow', 'label' => $label, 'factor' => $factor];
    }

    /**
     * End of the stay: draws the event card, applies the event, sends the expedition report to the owner, counts the
     * expedition in the advanced stats and sends the fleet home.
     */
    public function EndStayEvent(): void
    {
        $LNG = $this->LNG;
        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName = $this->db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id'],
        ], 'name');
        $Message = $LNG->format(
            'sys_expe_report_msg',
            $planetName,
            GetStartAddressLink($this->_fleet),
            GetTargetAddressLink($this->_fleet),
        ) . '<br><br>';

        // an active expedition story (map content) that ends here replaces the card draw
        $stories = new ExpeditionStories($this->db, $this->configs, $this->state);
        $story = null;
        // guarded: a story bug must never break the expedition
        $this->achievementGuard()->run(function () use ($stories, &$story): void {
            $story = $stories->advance($this->ctx, $this->_fleet);
        });
        if ($story !== null) {
            $eventType = 'Story';
            $event = $story;
        } else {
            // Draw expedition event from deck
            $card = $this->drawExpeditionEvent();
            $eventType = $card['type'];
            $eventSize = $card['size'];
            $this->ctx->eventsize = $eventSize;

            $event = $this->applyEvent($eventType, $eventSize, $fleetArray);
        }
        $Message .= $event['message'];
        $ExpoColor = $event['color'];
        $ExpoEventAndSize = $event['label'];
        $factor = $event['factor'];

        if (isset($fleetArray[SHIP_PROBE])) {
            $Message .= '<br><br>' . $this->ctx->logbook;
        }

        if ($factor != 0) {
            $Message .= '<span class="expo-event expo-event--' . $ExpoColor . '"><br><br>' . $ExpoEventAndSize . " - " . $this->LNG->format('sys_expe_factor', $factor) . '</span>';
        } else {
            $Message .= '<span class="expo-event expo-event--' . $ExpoColor . '"><br><br>' . $ExpoEventAndSize . '</span>';
        }

        if (isset($fleetArray[SHIP_PROBE]) && $story === null) {
            $Message .= $this->frequencyHint();
        }
        $Message .= $this->optionalReportLines($stories, $story === null && $eventType !== 'BlackHole');

        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::Expedition->value,
            $LNG['sys_expe_report'],
            $Message,
            $this->_fleet['stay_time'],
            1,
            $this->_fleet['universe'],
        );
        $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::EXPO_COUNT, 1);
        $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_EXPEDITION, $this->_fleet['stay_time']);
        if ($this->kill == 0) {
            $this->achievementGuard()->run(fn() => $this->fireExpeditionHook());
        }
        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    /**
     * Report lines of the optional features: a new expedition story (when no story step ran and the fleet survived the
     * black hole) and the relic drop roll (none when the fleet was lost). Each call is guarded.
     */
    private function optionalReportLines(ExpeditionStories $stories, bool $mayStartStory): string
    {
        $lines = '';
        if ($mayStartStory) {
            $this->achievementGuard()->run(function () use ($stories, &$lines): void {
                $lines .= $stories->maybeStart($this->ctx, $this->_fleet);
            });
        }
        if ($this->kill == 0) {
            $this->achievementGuard()->run(function () use (&$lines): void {
                $lines .= (new RelicDrops($this->db, $this->configs, $this->state))->rollForExpedition($this->LNG, $this->_fleet);
            });
        }

        return $lines;
    }

    /**
     * Tells the engagement features (contracts, seasons, alliance goals) that the expedition ended; token expeditions
     * (fleet value under EngagementHooks::EXPEDITION_MIN_SHARE of the reference value) are flagged as not qualifying.
     */
    private function fireExpeditionHook(): void
    {
        $universe = (int) $this->_fleet['universe'];
        $hooks = new \App\Game\Engagement\EngagementHooks($this->db, $this->configs, $this->state);
        if (!$hooks->anyEnabled($universe)) {
            return;
        }
        $points = (float) (new \App\Game\Missions\Expedition\ExpeditionFleetValue($this->db, $this->configs, $this->state))->pointsAndCapacity($this->_fleet)[0];
        $reference = (float) $this->fleetFunctions()->calculateMaxFactor($universe);
        $qualifies = $reference <= 0 || $points >= $reference * \App\Game\Engagement\EngagementHooks::EXPEDITION_MIN_SHARE;
        $hooks->onExpedition($universe, (int) $this->_fleet['owner'], $qualifies, (int) $this->_fleet['stay_time']);
    }

    /**
     * Hint for fleets with a probe how crowded the position is (from the depletion class set by drawExpeditionEvent();
     * nothing when fewer than 10 expeditions were there).
     */
    private function frequencyHint(): string
    {
        $LNG = $this->LNG;
        if ($this->ctx->eventspy == "<10") {
            return '';
        } elseif ($this->ctx->eventspy == "<25") {
            return '<span class="expo-freq expo-freq--yellow"><br>' . $LNG['sys_expe_feqeuence_few'] . '</span>';
        } elseif ($this->ctx->eventspy == "<50") {
            return '<span class="expo-freq expo-freq--orange"><br>' . $LNG['sys_expe_feqeuence_medium'] . '</span>';
        }

        return '<span class="expo-freq expo-freq--red"><br>' . $LNG['sys_expe_feqeuence_many'] . '</span>';
    }

    /**
     * Return of the fleet: reports the carried resources and the ships gained or lost compared to the start (a fleet
     * saved empty by an old bug is restored from its start ships), sends the report and gives fleet and cargo back to
     * the start planet.
     */
    public function ReturnEvent(): void
    {
        $e = $this->elements();
        $LNG = $this->LNG;
        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName = $this->db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id'],
        ], 'name');
        $Message = $LNG->format(
            'sys_expe_back_home',
            $planetName,
            GetStartAddressLink($this->_fleet),
            GetTargetAddressLink($this->_fleet),
        );

        if (
            $this->_fleet['resource_metal'] > 0
            || $this->_fleet['resource_crystal'] > 0
            || $this->_fleet['resource_deuterium'] > 0
        ) {
            $Message .= '<br><br>' . $LNG->format(
                'sys_expe_back_home_ress',
                $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
                $LNG['tech'][RESOURCE_METAL],
                $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
                $LNG['tech'][RESOURCE_CRYSTAL],
                $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
                $LNG['tech'][RESOURCE_DEUT],
            );
        }

        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
        $fleetArrayStart = $this->fleetFunctions()->unserialize($this->_fleet['start_ships']);

        // correct empty fleets resulting from a fixed bug in findShips event
        if (empty($fleetArray) && !empty($fleetArrayStart)) {
            $this->_fleet['ships'] = $this->_fleet['start_ships'];
            $this->UpdateFleet('ships', $this->_fleet['start_ships']);
            //impossible when fleet is killed
            $this->SaveFleet();
        }

        if (isset($fleetArrayStart)) {
            $fleetAmmount = array_sum($fleetArray);
            $fleetAmmountStart = array_sum($fleetArrayStart);
            if ($fleetAmmountStart != $fleetAmmount) {
                if ($fleetAmmountStart < $fleetAmmount) {
                    $Message .= '<br><br>' . $LNG->format('sys_expe_back_home_ships_flound');
                } elseif ($fleetAmmountStart > $fleetAmmount) {
                    $Message .= '<br><br>' . $LNG->format('sys_expe_back_home_ships_lost');
                }
                foreach ($e->ids('fleet') as $shipId) {
                    if (isset($fleetArrayStart[$shipId]) && isset($fleetArray[$shipId])) {
                        if ($fleetArrayStart[$shipId] != $fleetArray[$shipId]) {
                            $Message .= '<br>' . $LNG['tech'][$shipId] . ': '
                                . $this->viewHelpers()->prettyNumber(abs($fleetArrayStart[$shipId] - $fleetArray[$shipId]));
                        }
                    } elseif (isset($fleetArrayStart[$shipId])) {
                        $Message .= '<br>' . $LNG['tech'][$shipId] . ': '
                            . $this->viewHelpers()->prettyNumber(abs($fleetArrayStart[$shipId]));
                    } elseif (isset($fleetArray[$shipId])) {
                        $Message .= '<br>' . $LNG['tech'][$shipId] . ': ' . $this->viewHelpers()->prettyNumber(abs($fleetArray[$shipId]));
                    }
                }
            }
        }

        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::System->value,
            $LNG['sys_mess_fleetback'],
            $Message,
            $this->_fleet['return_time'],
            1,
            $this->_fleet['universe'],
        );

        $this->RestoreFleet();
    }
}
