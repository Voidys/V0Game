<?php

namespace App\Game\Core\Player;

use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\GameUtilities;
use App\Game\Model\User;

/**
 * Protection and balancing rules: catch-up factor for slower players, noob/strong protection between two
 * players (points based, inactivity, rebuild protection, outlaw), status labels and the spy-tech visibility of
 * statistics. Collaborators: ConfigRegistry, Database, AdvancedStatsHandler, ViewHelpers.
 */
class ProtectionRules
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Calculates, if enabled via config, the cost reduction factor based on the
     * percentage points difference to the top player of the universe. Points are
     * weighted: 1 x buildings + .8 x research + .6 x fleet
     *
     * - Calculation: factor = 10 * pow(0.9747, (userPoints/maxPoints * 100)) => (1 - 10)
     *                factor = (1-(1-0,5)/(10-1)*factor-1) => (1 - 0,5)
     * - Max factor: 0,5
     * - Min factor: 1
     * - Min percentage difference for boost: 10%
     * - Boost reduces build cost and therefore also build speed
     *
     * Reads statpoints (DB) for the player and the universe maximum; 1.0 when catch-up is off or the player is inactive.
     *
     * @param User $user the player (id, universe, onlinetime)
     * @return float 1.0 (no reduction) down to the configured catchup_percent / 100
     */
    public function catchUpFactorFor(User $user): float
    {
        $factor = 1;
        $config = $this->configs->get($user->universe);

        if ($config->catch_up && $user->onlineTime > $this->state->runtime->timestamp - INACTIVE) {
            $db = $this->db;

            $sql = 'SELECT (`build_points` * 1 + `tech_points` * 0.8 + `fleet_points` * 0.6) as points
                FROM %%STATPOINTS%%
                WHERE `id_owner` = :userID AND `stat_type` = 1;';
            $userPoints = $db->selectSingle($sql, [
                ':userID' => $user->id,
            ], 'points');
            $userPoints = max($userPoints, 1);

            $sql = 'SELECT max(`build_points` * 1 + `tech_points` * 0.8 + `fleet_points` * 0.6) AS max
                FROM %%STATPOINTS%%
                WHERE `universe` = :universe AND `stat_type` = 1;';
            $maxPoints = $db->selectSingle($sql, [
                ':universe' => $user->universe,
            ], 'max');
            $maxPoints = max($maxPoints, 1);

            $percentageOfMaxPoints = $userPoints / $maxPoints * 100;
            $maxBoost = 10;
            $reductionFactor = 0.9747;
            $factor = $maxBoost * pow($reductionFactor, $percentageOfMaxPoints);
            $factor = max($factor, 1);

            $maxFactor = $config->catchup_percent / 100;
            $factor = (1 - (1 - $maxFactor) / ($maxBoost - 1) * ($factor - 1));
        }

        return $factor;
    }

    /**
     * Catch-up factor of one element: 1 at or above the record level, 2/3 and 1/3 of the way to the base factor when
     * the player is 1 or 2 levels behind the highest level in records, otherwise the base factor.
     *
     * @param float $factor base catch-up factor (1.0 = no catch-up)
     * @param int $elementId
     * @param int $currentLevel
     * @param int|null $universe universe whose records count (default: the current one)
     * @return float
     */
    public function getCatchUpFactorForElement(float $factor, int $elementId, int $currentLevel, ?int $universe = null): float
    {
        if ($factor == 1.0) {
            return 1.0;
        }

        $db = $this->db;

        $sql = 'SELECT MAX(`level`) AS `level` FROM %%RECORDS%% WHERE `elementID` = :elementId AND `universe` = :universe;';
        $maxLevel = (int) $db->selectSingle($sql, [
            ':elementId' => $elementId,
            ':universe' => $universe ?? $this->universe()->current(),
        ], 'level');

        $diff = $maxLevel - $currentLevel;
        return match (true) {
            $diff <= 0 => 1.0,
            $diff === 1 => $factor + (1 - $factor) * 2 / 3.0,
            $diff === 2 => $factor + (1 - $factor) * 1 / 3.0,
            default => $factor,
        };
    }

    /**
     * Whether a viewer may see a statistic that requires spy technology (MODULE_SPYTECH_DEPENDENT_STATS). Own
     * data and staff are always allowed.
     *
     * @param int $necessaryLevel required spy tech level
     * @param int $PlayerID player whose data is requested
     * @param User $viewer the viewing player (id, auth level, spy tech)
     */
    public function spyTechAllowedFor(int $necessaryLevel, int $PlayerID, User $viewer): bool
    {
        $allowed = true;
        if ($this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS) && $PlayerID != $viewer->id
            && $viewer->authLevel == 0 && $viewer->level(SPY_TECH) < $necessaryLevel) {
            $allowed = false;
        }
        return $allowed;
    }

    /**
     * status classes of a player row (banned, vacation, inactive, noob, strong) for lists and the galaxy
     *
     * @param array<string, mixed> $data
     * @param array<mixed> $noob_protection
     * @return array<mixed>
     */
    public function userStatus(array $data, bool|array $noob_protection = false): array
    {
        $Array = [];

        if (isset($data['banaday']) && $data['banaday'] > $this->state->runtime->timestamp) {
            $Array[] = 'banned';
        }

        if (isset($data['urlaubs_modus']) && $data['urlaubs_modus'] == 1) {
            $Array[] = 'vacation';
        }

        if (isset($data['onlinetime']) && $data['onlinetime'] < $this->state->runtime->timestamp - INACTIVE_LONG) {
            $Array[] = 'longinactive';
        }

        if (isset($data['onlinetime']) && $data['onlinetime'] < $this->state->runtime->timestamp - INACTIVE) {
            $Array[] = 'inactive';
        }

        if ($noob_protection && $noob_protection['NoobPlayer']) {
            $Array[] = 'noob';
        }

        if ($noob_protection && $noob_protection['StrongPlayer']) {
            $Array[] = 'strong';
        }

        return $Array;
    }

    /**
     * Protection state between an attacking/viewing player and a target.
     *
     * NoobPlayer: the target is protected from the owner (rebuild protection, or the target is inactive/has few points and
     * the owner has more than noob_protection_multi times his points). StrongPlayer: the owner is protected from
     * the (active) target. With noob_protection_outlaw an outlaw target lifts both. Side effect: expired rebuild
     * protection is cleared in the database.
     *
     * @param array<mixed> $OwnerPlayer user row with total_points, rebuild_protection, ...
     * @param array<mixed> $TargetPlayer user row with total_points, onlinetime, banaday, outlaw_time, ...
     * @return array{NoobPlayer: bool, StrongPlayer: bool}
     * @deprecated use checkNoobProtectionFor() with typed Users
     */
    public function checkNoobProtection($OwnerPlayer, $TargetPlayer)
    {
        return $this->checkNoobProtectionFor(
            User::fromState($OwnerPlayer, $this->elements()->columnMap()),
            User::fromState($TargetPlayer, $this->elements()->columnMap()),
        );
    }

    /**
     * Typed version of checkNoobProtection() (same rules and side effect, see there).
     *
     * @param User $owner the acting player (totalPoints, rebuildProtection, ...)
     * @param User $target the other player (totalPoints, onlineTime, banUntil, outlawTime, ...)
     * @return array{NoobPlayer: bool, StrongPlayer: bool}
     */
    public function checkNoobProtectionFor(User $owner, User $target): array
    {
        $config = $this->configs->get();
        if (
            $config->noob_protection == 0
            || $config->noob_protection_time == 0
            || $config->noob_protection_multi == 0
            || $target->banUntil > $this->state->runtime->timestamp
            || ($target->onlineTime < $this->state->runtime->timestamp - INACTIVE && $config->noob_inactives == 0)
        ) {
            return ['NoobPlayer' => false, 'StrongPlayer' => false];
        }

        $rebuildProtectionTarget = $this->rebuildProtectionActive($target, $owner, $config);
        $rebuildProtectionOwner = $this->rebuildProtectionActive($owner, $target, $config);

        /* WAHR:
            Wenn Zielspieler im Rebuild Schutz ist ODER
            Wenn Zielspieler inaktiv ist UND Inaktivschutz aktiviert ist ODER weniger als X Puntke hat UND
            Wenn Spieler mehr als das Y-fache der Zielspieler-Punkte hat.
        */
        $noob = $rebuildProtectionTarget || (
            (($target->onlineTime < $this->state->runtime->timestamp - INACTIVE && $config->noob_inactives == 1)
                    || ($target->totalPoints <= $config->noob_protection_time)) // Default: 5.000
                && ($owner->totalPoints > $target->totalPoints * $config->noob_protection_multi)
        );
        /* WAHR:
            Wenn Zielspieler aktiv ist UND
            Wenn Spieler im Rebuild Schutz ist ODER
            Wenn Spieler weniger als X Punkte hat UND
            Zielspieler weniger als das Y-fache der Spieler-Punkte hat
        */
        $strong = $target->onlineTime > $this->state->runtime->timestamp - INACTIVE && (
            $rebuildProtectionOwner
                || (($owner->totalPoints < $config->noob_protection_time) // Default: 5.000
                    && ($owner->totalPoints * $config->noob_protection_multi < $target->totalPoints))
        );

        // the outlaw state only matters when the universe lets it lift the protection
        if ($config->noob_protection_outlaw && $this->isOutlaw($target)) {
            $noob = false;
            $strong = false;
        }

        return [
            'NoobPlayer' => $noob,
            'StrongPlayer' => $strong && !$noob,
        ];
    }

    /**
     * Rebuild protection of one player against another: whether it counts as active right now (the name says "lifted",
     * the value is true when the protection applies).
     *
     * Applies only with rebuild_protection enabled, between different players and while the subject has
     * rebuild_protection > 0. After 48 hours the protection is cleared (UPDATE users). Before that it counts as
     * active 6 hours after it started for players in the subject's recorded attackers, and 3 hours after it
     * started for everybody else.
     *
     * @param User $subject the protected player (rebuildProtection, rebuildProtectionAttackers, id)
     * @param User $other the other player
     * @param Config $config universe configuration
     * @return bool
     */
    private function rebuildProtectionActive(User $subject, User $other, Config $config): bool
    {
        $result = false;
        if ($config->rebuild_protection == 1 && $other->id != $subject->id && $subject->rebuildProtection > 0) {
            if (($subject->rebuildProtection + TIME_24_HOURS * 2) < $this->state->runtime->timestamp) {
                $sql = "UPDATE %%USERS%% SET `rebuild_protection` = 0, `rebuild_protection_attackers` = ''
                    WHERE `id` = :userID;";
                $this->db->update($sql, [':userID' => $subject->id]);
            } else {
                $attackers = json_decode($subject->rebuildProtectionAttackers);
                if (is_array($attackers) && in_array($other->id, $attackers)) {
                    $result = ($subject->rebuildProtection + TIME_6_HOURS) <= $this->state->runtime->timestamp;
                } else {
                    $result = ($subject->rebuildProtection + (TIME_6_HOURS / 2)) <= $this->state->runtime->timestamp;
                }
            }
        }

        return $result;
    }

    /**
     * Outlaw state of a player: protection is lifted for 7 days after the second or later outlaw
     * activation, for 2 days after the first one.
     *
     * @param User $player the player (id, outlawTime)
     * @return bool
     */
    private function isOutlaw(User $player): bool
    {
        $statsHandler = new \App\Game\AdvancedStats\AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $outlaw = false;
        $outlawActivations = $statsHandler->getValue($player->id, \App\Game\AdvancedStats\AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
        if ($outlawActivations > 1) {
            $outlaw = $player->outlawTime > $this->state->runtime->timestamp - 7 * TIME_24_HOURS;
        } elseif ($outlawActivations == 1) {
            $outlaw = $player->outlawTime > $this->state->runtime->timestamp - 2 * TIME_24_HOURS;
        }

        return $outlaw;
    }
}
