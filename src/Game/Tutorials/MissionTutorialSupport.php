<?php

/**
 * pr0game powered by steemnova
 * tutorial
 * (c) 2024 Hyman
 */

namespace App\Game\Tutorials;

use App\Game\Enum\Mission;

/**
 * Step whose goals are missions: a goal counts as done when the player has a completed (returned and not recalled) flight of that mission type in the fleet log (colonisation counts on arrival), or owns the required ships.
 */
class MissionTutorialSupport extends AbstractTutorialSupport
{
    /**
     * @param array<string, mixed> $USER
     * @param array<mixed> $tutorialStep
     */
    public function __construct(
        \App\Game\Core\Database $db,
        \App\Game\Core\ConfigRegistry $configs,
        \App\Game\Core\Singleton $state,
        array $tutorialStep,
        array $USER,
    ) {
        parent::__construct($db, $configs, $state, $tutorialStep, $USER);
    }

    /**
     * List all enabeld and unskipped goals
     *
     * Can be disabled by table enty
     *
     * @return array<mixed>
     */
    public function listGoals(): array
    {
        $goals = [];
        foreach ($this->tutorialGoals as $goalRow) {
            $elementID = $goalRow['elementID'];

            if ($goalRow['enabled'] == 0 || $goalRow['skipped'] == 1) {
                continue;
            }

            $owned = 0;
            if ($goalRow['fullfilled'] == 1) {
                $owned = $goalRow['ammount'];
            }

            $goals[$elementID] = [
                'name'          => $this->getGoalName($elementID),
                'ammount'       => $goalRow['ammount'],
                'owned'         => $owned,
                'fullfilled'    => $goalRow['ammount'] <= $owned,
                'skippable'     => $goalRow['skippable'] == 1,
                'target'        => $this->goalTarget((int) $elementID),
            ];
        }
        return $goals;
    }

    /**
     * Page of a mission goal: the galaxy view for missions started from a target, the fleet page for the rest; owned ships are built in the shipyard.
     *
     * @return array{route: string, params: array<string, string>}|null
     */
    protected function goalTarget(int $elementID): ?array
    {
        if ($this->elements()->isFleet($elementID)) {
            return parent::goalTarget($elementID);
        }

        return match (Mission::tryFrom($elementID)) {
            Mission::Attack, Mission::Spy, Mission::Colonisation, Mission::Recycling => ['route' => 'game_galaxy_show', 'params' => []],
            default => ['route' => 'game_fleet_table_show', 'params' => []],
        };
    }

    /**
     * Returns the goal name/description and link funktionality
     *
     * @param integer $elementID
     * @return string
     */
    protected function getGoalName(int $elementID): string
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['TUTORIAL']);
        if ($this->elements()->isFleet($elementID)) {
            return parent::getGoalName($elementID);
        }
        return $LNG['tutorial_goal']['mission'][$elementID];
    }

    /**
     * Indicates whether all goals are fullfilled
     *
     * @return boolean
     */
    public function isFullfilled(): bool
    {
        $goals = $this->listGoals();
        $fullfilled = true;

        foreach ($goals as $elementID => $goalRow) {
            if ($this->elements()->isFleet((int) $elementID)) {
                $fullfilled = $fullfilled && $goalRow['fullfilled'];
            } elseif ($goalRow['fullfilled'] != 1) {
                $sql = "SELECT COUNT(`id`) as amount FROM %%LOG_FLEETS%%
                    WHERE `mission` = :mission 
                      AND (`return_time` <= :end_time OR (`arrival_time` <= :end_time AND `mission` = :colo)) 
                      AND `hasCanceled` = 0 
                      AND `owner` = :userID;";
                $amount = $this->db->selectSingle($sql, [
                    ':mission'  => $elementID,
                    ':end_time' => $this->state->runtime->timestamp,
                    ':colo'     => Mission::Colonisation->value,
                    ':userID'   => $this->user['id'],
                ], 'amount');

                if ($amount > 0) {
                    $this->fullfillGoal($elementID);
                    $fullfilled = $fullfilled && true;
                } else {
                    $fullfilled = false;
                }
            }
        }

        return $fullfilled;
    }

    /**
     * Fullfills the given goal for the current user
     *
     * @param integer $goal
     * @return void
     */
    public function fullfillGoal(int $goal)
    {
        foreach ($this->tutorialGoals as &$goalRow) {
            if ($goalRow['elementID'] == $goal) {
                $sql = "UPDATE %%USERS_TUTORIAL_GOAL%% SET `fullfilled` = 1 
                    WHERE `userID` = :userID AND `tutorialGoalID` = :tutorialGoalID";
                $this->db->update($sql, [
                    ':userID' => $this->user['id'],
                    ':tutorialGoalID' => $goalRow['id'],
                ]);
                $goalRow['fullfilled'] = 1;
                return;
            }
        }
    }
}
