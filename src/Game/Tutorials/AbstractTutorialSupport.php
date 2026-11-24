<?php

/**
 * pr0game powered by steemnova
 * tutorial
 * (c) 2024 Hyman
 */

namespace App\Game\Tutorials;

use App\Game\Core\Database;

/**
 * Rules of one tutorial step for one player: loads the step's universe settings, the player's goals (with
 * fulfilled/skipped state) and the unclaimed rewards, and answers whether all goals are done, which goals to show
 * and which rewards to give. Subclasses (Default, Mission, Rename, Link; named in tutorials.support_class) change
 * how goals are named and verified. Created by TutorialUtil.
 */
abstract class AbstractTutorialSupport
{
    use \App\Game\Core\GameUtilities;

    /**
     * @var Database
     */
    protected array $tutorialStep;
    /** @var array<mixed> */
    protected array $tutorialUniverse;
    /** @var array<mixed> */
    protected array $tutorialGoals;
    /** @var array<mixed> */
    protected array $tutorialRewards;
    /** @var array<mixed> */
    protected array $user;
    /** @var array<mixed> */
    protected array $planet;

    /**
     * Loads tutorial_universe, the player's tutorial goals and the still unclaimed rewards of the step; the goals are checked against the player's main planet.
     *
     * @param array<string, mixed> $USER
     * @param array<mixed> $tutorialStep
     */
    protected function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
        array $tutorialStep,
        array $USER,
    ) {
        $this->user = $USER;
        $this->planet = $this->planetQueries()->getMainPlanet($USER);

        $this->tutorialStep = $tutorialStep;
        $tutorialID = $tutorialStep['id'];

        $sql = "SELECT * FROM %%TUTORIAL_UNIVERSE%% WHERE `tutorialID` = :tutorialID AND `universe` = :universe;";
        $result = $this->db->selectSingle($sql, [
            ':tutorialID' => $tutorialID,
            ':universe' => $this->user['universe'],
        ]);
        $this->tutorialUniverse = is_array($result) ? $result : [];

        $sql = "SELECT tg.*, ug.`fullfilled`, ug.`skipped` FROM %%TUTORIAL_GOALS%% tg
            JOIN %%USERS_TUTORIAL_GOAL%% ug ON ug.`tutorialGoalID` = tg.`id`
            WHERE tg.`tutorialID` = :tutorialID AND ug.`userID` = :userID
            ORDER BY tg.`elementID`;";
        $result = $this->db->select($sql, [
            ':tutorialID' => $tutorialID,
            ':userID' => $this->user['id'],
        ]);
        $this->tutorialGoals = is_array($result) ? $result : [];

        $sql = "SELECT tr.* FROM %%TUTORIAL_REWARDS%% tr
            JOIN %%USERS_TUTORIAL%% ut ON ut.`tutorialID` = tr.`tutorialID`
            WHERE tr.`tutorialID` = :tutorialID AND ut.`claimed` = 0 AND ut.`userID` = :userID
            ORDER BY tr.`elementID`;";
        $result = $this->db->select($sql, [
            ':tutorialID' => $tutorialID,
            ':userID' => $this->user['id'],
        ]);
        $this->tutorialRewards = is_array($result) ? $result : [];
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

        foreach ($goals as $goalRow) {
            $fullfilled = $fullfilled && $goalRow['fullfilled'];
        }

        return $fullfilled;
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
        $elements = $this->elements();

        $goals = [];
        foreach ($this->tutorialGoals as $goalRow) {
            $elementID = $goalRow['elementID'];
            $column = $elements->find((int) $elementID)?->column;
            if ((array_key_exists($column, $this->user) || array_key_exists($column, $this->planet))
                && !$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }

            if ($goalRow['enabled'] == 0 || $goalRow['skipped'] == 1) {
                continue;
            }

            $owned = 0;
            if (array_key_exists($column, $this->user)) {
                $owned = $this->user[$column];
            } elseif (array_key_exists($column, $this->planet)) {
                $owned = $this->planet[$column];
            } elseif ($goalRow['fullfilled'] == 1) {
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
     * Page where the goal is done, as a route for the "go there" link of the tutorial box (null: no link).
     *
     * @return array{route: string, params: array<string, string>}|null
     */
    protected function goalTarget(int $elementID): ?array
    {
        $elements = $this->elements();
        if ($elements->isBuilding($elementID)) {
            return ['route' => 'game_buildings_show', 'params' => []];
        }
        if ($elements->isResearch($elementID)) {
            return ['route' => 'game_research_show', 'params' => []];
        }
        if ($elements->isFleet($elementID)) {
            return ['route' => 'game_shipyard_show', 'params' => ['kind' => 'shipyard']];
        }
        if ($elements->isDefense($elementID) || $elements->isMissile($elementID)) {
            return ['route' => 'game_shipyard_show', 'params' => ['kind' => 'defense']];
        }

        return null;
    }

    /**
     * Returns the goal name/description
     *
     * @param integer $elementID
     * @return string
     */
    protected function getGoalName(int $elementID): string
    {
        $LNG = $this->state->LNG;
        return $LNG['tech'][$elementID];
    }

    /**
     * Indicates whether a goal can be skipped
     *
     * @param integer $goal
     * @return boolean
     */
    public function isGoalSkippable(int $goal): bool
    {
        foreach ($this->listGoals() as $elementID => $goalRow) {
            if ($elementID == $goal && $goalRow['skippable'] == true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Skips the given goal for the current user, if able
     *
     * @param integer $goal
     * @return void
     */
    public function skipGoal(int $goal)
    {
        foreach ($this->tutorialGoals as $goalRow) {
            if ($goalRow['elementID'] == $goal && $goalRow['skippable'] == true) {
                $sql = "UPDATE %%USERS_TUTORIAL_GOAL%% SET `skipped` = 1
                    WHERE `userID` = :userID AND `tutorialGoalID` = :tutorialGoalID";
                $this->db->update($sql, [
                    ':userID' => $this->user['id'],
                    ':tutorialGoalID' => $goalRow['id'],
                ]);
                return;
            }
        }
    }

    /**
     * Fullfills the given goal for the current user
     *
     * Not implemented for default var goals
     *
     * @param integer $goal
     * @return void
     */
    public function fullfillGoal(int $goal)
    {
        // not implemented for default var goals
    }

    /**
     * Fullfills the given goal on behalf of a player request (button/link click)
     *
     * Goals backed by server facts must never be fulfilled by a request, so the default is a no-op.
     * Only steps whose completion genuinely is a click (link steps) override this.
     */
    public function fullfillGoalByRequest(int $goal): void {}

    /**
     * Lists all enabled rewards
     *
     * Can be disabled by module MODULE_TUTORIAL_REWARDS
     * or by table entry
     *
     * @return array<mixed>
     */
    public function listRewards(): array
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_TUTORIAL_REWARDS)) {
            return [];
        }

        $elements = $this->elements();
        $LNG = $this->state->LNG;

        $rewards = [];
        foreach ($this->tutorialRewards as $rewardRow) {
            $elementID = $rewardRow['elementID'];
            $column = $elements->find((int) $elementID)?->column;
            if ((array_key_exists($column, $this->user) || array_key_exists($column, $this->planet))
                && !$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }

            if ($rewardRow['enabled'] == 0) {
                continue;
            }

            $rewards[$elementID] = [
                'name'      => $LNG['tech'][$elementID],
                'ammount'   => $rewardRow['ammount'],
            ];
        }
        return $rewards;
    }

    /**
     * Adds rewards to the user or the home planet based on vars elementID
     *
     * @return void
     */
    public function rewardUser()
    {
        if (!$this->isFullfilled()) {
            return;
        }
        $elements = $this->elements();

        $sql = "UPDATE %%USERS_TUTORIAL%% SET `completed` = 1, `claimed` = 1 WHERE `userID` = :userID"
            . " AND `tutorialID` = :tutorialID AND `completed` = 0";
        $this->db->update($sql, [
            ':userID' => $this->user['id'],
            ':tutorialID' => $this->tutorialStep['id'],
        ]);

        // Another request already completed this step
        if ($this->db->rowCount() === 0) {
            return;
        }

        foreach ($this->listRewards() as $elementID => $rewardRow) {
            $column = $elements->find((int) $elementID)?->column;
            if (array_key_exists($column, $this->user)) {
                $this->db->updateRows('USERS', [], ['id' => $this->user['id']], [], [$column => $rewardRow['ammount']]);
            } elseif (array_key_exists($column, $this->planet)) {
                $this->db->updateRows('PLANETS', [], ['id' => $this->planet['id']], [], [$column => $rewardRow['ammount']]);
            }
        }

        if (!$this->hasNext()) {
            $this->achievementGuard()->run(fn() => $this->miscAchievements()->checkTutorialAchievement((int) $this->user['id']));
        }
    }

    /**
     * Returns the next tutorial step
     *
     * @return array<mixed>
     */
    public function getNextStep(): array
    {
        $sql = "SELECT t.* FROM %%TUTORIALS%% t
            LEFT JOIN %%TUTORIAL_UNIVERSE%% tu ON tu.`tutorialID` = t.`id` AND tu.`universe` = :universe AND tu.`enabled` = 0
            WHERE t.`order` > :order AND t.`enabled` = 1 AND tu.`id` IS NULL
            ORDER BY t.`order`";
        $next = $this->db->selectSingle($sql, [
            ':order' => $this->tutorialStep['order'],
            ':universe' => $this->user['universe'],
        ]);
        return is_array($next) ? $next : [];
    }

    /**
     * Indicates whether there is a next tutorial step
     *
     * @return boolean
     */
    public function hasNext(): bool
    {
        return !empty($this->getNextStep());
    }

    /**
     * Indicates whether the tutorial step is enabled
     *
     * @return boolean
     */
    public function isEnabled(): bool
    {
        return $this->tutorialStep['enabled'] == 1
            && (!isset($this->tutorialUniverse) || $this->tutorialUniverse['enabled'] == 1);
    }

    /**
     * Indicates whether rewards are enabeld and are defined for this step
     *
     * @return boolean
     */
    public function hasRewards(): bool
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_TUTORIAL_REWARDS) || empty($this->tutorialRewards)) {
            return false;
        }

        foreach ($this->tutorialRewards as $rewardRow) {
            if ($rewardRow['enabled'] == 1) {
                return true;
            }
        }
        return false;
    }
}
