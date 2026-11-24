<?php

declare(strict_types=1);

namespace App\Game\Engagement;

use App\Game\AdvancedStats\AdvancedStatsEnum;

/**
 * Fans game events out to the optional engagement features (see EngagementFeature). Every call is guarded like an
 * achievement check: a failing feature is logged and skipped, only deadlocks / lock wait timeouts propagate.
 */
final class EngagementHooks
{
    use \App\Game\Core\GameUtilities;

    /** @var list<class-string<EngagementFeature>> */
    public const FEATURES = [
        Events\UniverseEvents::class,
        Contracts\Contracts::class,
        AllianceGoals\AllianceGoals::class,
        Seasons\Seasons::class,
        Bounties\Bounties::class,
        Wars\AllianceWars::class,
        Feed\BattleFeed::class,
        Derelicts\Derelicts::class,
        Mothership\Mothership::class,
    ];

    /** @var array<class-string, EngagementFeature> */
    private array $features = [];

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /** Universe setting keys of the features in FEATURES (the cheap "is anything on" check before any work). */
    private const ENABLE_KEYS = [
        'events_enabled', 'contracts_enabled', 'alliance_goals_enabled', 'seasons_enabled', 'bounties_enabled',
        'alliance_wars_enabled', 'battle_feed_enabled', 'derelicts_enabled', 'mothership_enabled',
    ];

    /** True when at least one hooked feature is switched on for the universe (config lookups only, no query). */
    public function anyEnabled(int $universe): bool
    {
        $config = $this->configs->get($universe);
        foreach (self::ENABLE_KEYS as $key) {
            if (!empty($config->$key)) {
                return true;
            }
        }

        return false;
    }

    public function onStat(int $universe, int $userId, AdvancedStatsEnum $key, int $value): void
    {
        if ($value <= 0 || $userId <= 0 || !$this->anyEnabled($universe)) {
            return;
        }
        $this->each($universe, fn(EngagementFeature $f) => $f->onStat($universe, $userId, $key, $value));
    }

    public function onBattle(BattleOutcome $outcome): void
    {
        if (!$this->anyEnabled($outcome->universe)) {
            return;
        }
        $this->each($outcome->universe, fn(EngagementFeature $f) => $f->onBattle($outcome), true);
    }

    /** Share of the expedition reference value (the max-find points) a fleet needs so its expedition counts for contracts, seasons and goals. */
    public const EXPEDITION_MIN_SHARE = 0.25;

    /** An expedition ended without losing the fleet (see EngagementFeature::onExpedition()). */
    public function onExpedition(int $universe, int $userId, bool $qualifies, int $time): void
    {
        if ($userId <= 0 || !$this->anyEnabled($universe)) {
            return;
        }
        $this->each($universe, fn(EngagementFeature $f) => $f->onExpedition($universe, $userId, $qualifies, $time));
    }

    public function tick(int $universe, int $now): void
    {
        $this->each($universe, fn(EngagementFeature $f) => $f->tick($universe, $now));
    }

    /**
     * Runs one feature call; inside a transaction (fight, fleet event) it gets its own savepoint, so a feature that throws
     * halfway leaves no partial writes behind (the guard swallows the error, the rest of the event commits).
     *
     * @param callable(): void $call
     */
    private function isolated(callable $call): void
    {
        if (!$this->db->inTransaction()) {
            $call();

            return;
        }
        $this->db->query('SAVEPOINT eng_feature');
        try {
            $call();
        } catch (\Throwable $e) {
            if (!\App\Game\Core\Database::isRetryable($e)) {
                $this->db->query('ROLLBACK TO SAVEPOINT eng_feature');
            }
            throw $e;
        }
        $this->db->query('RELEASE SAVEPOINT eng_feature');
    }

    /**
     * @param callable(EngagementFeature): void $call
     * @param bool $isolate give every feature its own savepoint (fight hooks: several writes per feature)
     */
    private function each(int $universe, callable $call, bool $isolate = false): void
    {
        foreach (self::FEATURES as $class) {
            $feature = $this->features[$class] ??= new $class($this->db, $this->configs, $this->state);
            $this->achievementGuard()->run(function () use ($feature, $universe, $call): void {
                if ($feature->enabled($universe)) {
                    $isolate ? $this->isolated(fn() => $call($feature)) : $call($feature);
                }
            });
        }
    }
}
