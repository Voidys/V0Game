<?php

/**
 * pr0game powered by steemnova
 * tutorial
 * (c) 2024 Hyman
 */

namespace App\Game\Tutorials;

use App\Game\Core\Database;

/**
 * Tutorial engine of the game: keeps the player's progress rows in sync with the enabled tutorials/goals,
 * builds the data of the tutorial popup for the current step, handles advancing, skipping and reward claiming.
 * The step logic itself is in the AbstractTutorialSupport subclasses.
 */
class TutorialUtil
{
    use \App\Game\Core\GameUtilities;

    /** seconds between two syncs of the player's tutorial rows with the enabled tutorials and goals */
    private const SYNC_INTERVAL = 60;

    /**
     * true: no tutorial step is open for the player (known from the last sync, at most SYNC_INTERVAL seconds old, the
     * same lag a newly enabled tutorial has before the sync adds its rows), so the popup needs no query at all
     */
    private bool $idle = false;

    /**
     * Adds the missing users_tutorial and users_tutorial_goal rows (tutorials and goals that were enabled after the player started, or all for a new player); throttled to once per SYNC_INTERVAL seconds per player in the session.
     */
    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {
        /**
         * @var Database
         */
        $db = $this->db;
        $USER = $this->state->USER;

        // The missing-rows sync below only matters when an admin adds or enables tutorials or goals (or for
        // a new player); once per player and SYNC_INTERVAL seconds per session is enough.
        $syncKey = $USER['id'] . '|' . $USER['universe'];
        $request = $this->state->request ?? null;
        $symfonySession = $request instanceof \Symfony\Component\HttpFoundation\Request && $request->hasSession() ? $request->getSession() : null;
        $lastSync = $symfonySession?->get('tutorialSync');
        if (
            is_array($lastSync)
            && ($lastSync['key'] ?? null) === $syncKey
            && $lastSync['time'] <= $this->state->runtime->timestamp
            && $lastSync['time'] > $this->state->runtime->timestamp - self::SYNC_INTERVAL
        ) {
            $this->idle = !empty($lastSync['idle']);
            return;
        }

        // Fehlende USER_TUTORIAL selektieren
        $sql = "SELECT t.`id` FROM %%TUTORIALS%% t
            LEFT JOIN %%TUTORIAL_UNIVERSE%% tu ON tu.`tutorialID` = t.`id` AND tu.`universe` = :universe AND tu.`enabled` = 0
            WHERE t.`enabled` = 1 AND tu.`id` IS NULL
              AND t.`id` NOT IN ( SELECT `tutorialID` AS id FROM %%USERS_TUTORIAL%% WHERE `userID` = :userID )";
        $missing = $db->select($sql, [
            ':userID' => $USER['id'],
            ':universe' => $USER['universe'],
        ]);

        $sql = "INSERT INTO %%USERS_TUTORIAL%% (`userID`, `tutorialID`, `claimed`) VALUES (:userID, :tutorialID, :claimed);";
        foreach ($missing as $tutorialRow) {
            $db->insert($sql, [
                ':userID' => $USER['id'],
                ':tutorialID' => $tutorialRow['id'],
                ':claimed' => ($this->viewHelpers()->isModuleAvailable(MODULE_TUTORIAL_REWARDS) ? 0 : 1),
            ]);
        }

        // Fehlende USERS_TUTORIAL_GOAL selektieren
        $sql = "SELECT tg.`id` FROM %%TUTORIAL_GOALS%% tg
            JOIN %%TUTORIALS%% t ON t.id = tg.`tutorialID`
            LEFT JOIN %%TUTORIAL_UNIVERSE%% tu ON tu.`tutorialID` = t.`id` AND tu.`universe` = :universe AND tu.`enabled` = 0
            WHERE t.`enabled` = 1 AND tg.`enabled` = 1 AND tu.`id` IS NULL
              AND tg.`id` NOT IN ( SELECT `tutorialGoalID` AS id FROM %%USERS_TUTORIAL_GOAL%% WHERE `userID` = :userID )";
        $missing = $db->select($sql, [
            ':userID' => $USER['id'],
            ':universe' => $USER['universe'],
        ]);

        $sql = "INSERT INTO %%USERS_TUTORIAL_GOAL%% (`userID`, `tutorialGoalID`) VALUES (:userID, :tutorialGoalID);";
        foreach ($missing as $tutorialGoalRow) {
            $db->insert($sql, [
                ':userID' => $USER['id'],
                ':tutorialGoalID' => $tutorialGoalRow['id'],
            ]);
        }

        $this->idle = $symfonySession !== null && $this->getCurrentStep((int) $USER['id'], (int) $USER['universe']) === [];
        $symfonySession?->set('tutorialSync', ['key' => $syncKey, 'time' => $this->state->runtime->timestamp, 'idle' => $this->idle]);
    }

    /**
     * Returns details for the tutorial popup
     *
     * Includes translated title and text, goals, rewards and states
     *
     * @return array<mixed>
     */
    public function getTutorialStepDetails(): array
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_TUTORIAL)) {
            return [];
        }

        $LNG = $this->state->LNG;
        $LNG->includeData(['TUTORIAL']);
        $USER = $this->state->USER;

        if ($this->idle) {
            return [];
        }

        $tutorialStep = $this->getCurrentStep($USER['id'], $USER['universe']);
        if (empty($tutorialStep)) {
            return [];
        }

        $supportClass = $this->getSupportClass($tutorialStep, $USER);
        $translationKey = $tutorialStep['translation_key'];

        $tutorial = [
            'title' => new \Twig\Markup((string) $LNG['tutorial_title'][$translationKey], 'UTF-8'),
            'text' => new \Twig\Markup((string) $LNG['tutorial_text'][$translationKey], 'UTF-8'),
            'fullfilled' => $supportClass->isFullfilled(),
            'goals' => $supportClass->listGoals(),
            'rewards' => $supportClass->listRewards(),
            'last' => !$supportClass->hasNext(),
            'skippable' => $tutorialStep['skippable'],
            'order' => $tutorialStep['order'],
            'id' => $tutorialStep['id'],
        ];

        return $tutorial + $this->stepPosition((int) $tutorialStep['order'], (int) $USER['universe']);
    }

    /**
     * Position of a step among the steps enabled in the universe (for "step 3 of 12" and the progress bar).
     *
     * @return array{position: int, total: int}
     */
    private function stepPosition(int $order, int $universe): array
    {
        $row = $this->db->selectSingle("SELECT COUNT(*) AS total, COALESCE(SUM(t.`order` <= :order), 0) AS position
            FROM %%TUTORIALS%% t
            LEFT JOIN %%TUTORIAL_UNIVERSE%% tu ON tu.`tutorialID` = t.`id` AND tu.`universe` = :universe AND tu.`enabled` = 0
            WHERE t.`enabled` = 1 AND tu.`id` IS NULL;", [
            ':order' => $order,
            ':universe' => $universe,
        ]);

        return ['position' => (int) ($row['position'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
    }

    /**
     * Attempts to skip the current tutorial step, if able
     *
     * Called by button/link in templates/game/shared.tutorial.html.twig via App\Controller\Game\TutorialController
     *
     * @return void
     */
    public function skip()
    {
        /**
         * @var Database
         */
        $db = $this->db;
        $USER = $this->state->USER;

        $tutorialStep = $this->getCurrentStep($USER['id'], $USER['universe']);
        if (empty($tutorialStep) || $tutorialStep['skippable'] == 0) {
            return;
        }

        $sql = "UPDATE %%USERS_TUTORIAL%% SET `skipped` = 1 WHERE `userID` = :userID AND `tutorialID` = :tutorialID";
        $db->update($sql, [
            ':userID' => $USER['id'],
            ':tutorialID' => $tutorialStep['id'],
        ]);
    }

    /**
     * Attempts to skip the current tutorial step goal, if able
     *
     * Called by button/link in templates/game/shared.tutorial.html.twig via App\Controller\Game\TutorialController
     *
     * @param integer $goal
     * @return void
     */
    public function skipGoal(int $goal)
    {
        /**
         * @var Database
         */
        $db = $this->db;
        $USER = $this->state->USER;

        $tutorialStep = $this->getCurrentStep($USER['id'], $USER['universe']);
        if (empty($tutorialStep)) {
            return;
        }

        $supportClass = $this->getSupportClass($tutorialStep, $USER);
        if ($supportClass->isGoalSkippable($goal)) {
            $supportClass->skipGoal($goal);
        }
    }

    /**
     * Skips the entire tutorial
     *
     * Called by button/link in templates/game/shared.tutorial.html.twig via App\Controller\Game\TutorialController
     *
     * @return void
     */
    public function skipAll()
    {
        /**
         * @var Database
         */
        $db = $this->db;
        $USER = $this->state->USER;

        $sql = "UPDATE %%USERS_TUTORIAL%% SET `skipped` = 1 WHERE `userID` = :userID";
        $db->update($sql, [
            ':userID' => $USER['id'],
        ]);
    }


    /**
     * Claims all rewards for the tutorial step, if any, and tags it accordingly
     *
     * Called by button/link in templates/game/shared.tutorial.html.twig via App\Controller\Game\TutorialController
     *
     * @return void
     */
    public function claim()
    {
        $USER = $this->state->USER;
        $tutorialStep = $this->getCurrentStep($USER['id'], $USER['universe']);
        if (empty($tutorialStep)) {
            return;
        }

        $supportClass = $this->getSupportClass($tutorialStep, $USER);
        if ($supportClass->isFullfilled()) {
            $supportClass->rewardUser();
        }
    }

    /**
     * Attempts to tag the given goal as fullfilled
     *
     * Called by button/link in templates/game/shared.tutorial.html.twig via App\Controller\Game\TutorialController
     *
     * @param integer $goal
     * @return void
     */
    public function fullfillGoal(int $goal)
    {
        /**
         * @var Database
         */
        $db = $this->db;
        $USER = $this->state->USER;

        $tutorialStep = $this->getCurrentStep($USER['id'], $USER['universe']);
        if (empty($tutorialStep)) {
            return;
        }

        $supportClass = $this->getSupportClass($tutorialStep, $USER);
        $supportClass->fullfillGoalByRequest($goal);
    }

    /**
     * Creates and includes the AbstractTutorialSupport class for the current tutorial step
     *
     * @param array<mixed> $tutorialStep
     * @param array<string, mixed> $USER
     * @return AbstractTutorialSupport
     */
    private function getSupportClass(array $tutorialStep, array $USER): AbstractTutorialSupport
    {

        $tutorialClass = 'App\\Game\\Tutorials\\' . $tutorialStep['support_class'];
        /**
         * @var AbstractTutorialSupport
         */
        return new $tutorialClass($this->db, $this->configs, $this->state, $tutorialStep, $USER);
    }

    /**
     * Returns the current tutorial step for the given user
     *
     * @param integer $userID
     * @param integer $universe
     * @return array<mixed>
     */
    private function getCurrentStep(int $userID, int $universe): array
    {
        /**
         * @var Database
         */
        $db = $this->db;

        $sql = "SELECT t.* FROM %%USERS_TUTORIAL%% ut
            JOIN %%TUTORIALS%% t ON t.`id` = ut.`tutorialID`
            LEFT JOIN %%TUTORIAL_UNIVERSE%% tu ON tu.`tutorialID` = t.`id` AND tu.`universe` = :universe AND tu.`enabled` = 0
            WHERE ut.`userID` = :userID AND ut.`completed` = 0 AND ut.`skipped` = 0 
              AND t.`enabled` = 1 AND tu.`id` IS NULL
            ORDER BY t.`order`;";
        $tutorialStep = $db->selectSingle($sql, [
            ':userID' => $userID,
            ':universe' => $universe,
        ]);

        return is_array($tutorialStep) ? $tutorialStep : [];
    }
}
