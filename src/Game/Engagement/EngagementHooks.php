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

    public function onStat(int $universe, int $userId, AdvancedStatsEnum $key, int $value): void
    {
        if ($value <= 0 || $userId <= 0) {
            return;
        }
        $this->each($universe, fn(EngagementFeature $f) => $f->onStat($universe, $userId, $key, $value));
    }

    public function onBattle(BattleOutcome $outcome): void
    {
        $this->each($outcome->universe, fn(EngagementFeature $f) => $f->onBattle($outcome));
    }

    public function tick(int $universe, int $now): void
    {
        $this->each($universe, fn(EngagementFeature $f) => $f->tick($universe, $now));
    }

    /** @param callable(EngagementFeature): void $call */
    private function each(int $universe, callable $call): void
    {
        foreach (self::FEATURES as $class) {
            $feature = $this->features[$class] ??= new $class($this->db, $this->configs, $this->state);
            $this->achievementGuard()->run(function () use ($feature, $universe, $call): void {
                if ($feature->enabled($universe)) {
                    $call($feature);
                }
            });
        }
    }
}
