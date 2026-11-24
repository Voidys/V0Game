<?php

declare(strict_types=1);

namespace App\Game\Engagement;

use App\Game\AdvancedStats\AdvancedStatsEnum;

/**
 * Base of one optional engagement feature. EngagementHooks calls every feature on stat increments, finished fights and
 * the engagement cronjob tick; each method is a no-op unless the feature overrides it, and a feature must return early
 * when enabled() is false for the universe (all features are off by default, switched on in the universe settings).
 * Exceptions are logged and swallowed by EngagementHooks (deadlocks excepted), so a feature can never break a fight.
 */
abstract class EngagementFeature
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /** Whether the admin switched the feature on for the universe. */
    abstract public function enabled(int $universe): bool;

    /** An advanced-stats counter of a user grew by $value (value > 0 only). */
    public function onStat(int $universe, int $userId, AdvancedStatsEnum $key, int $value): void {}

    /** A real fight finished (report stored). */
    public function onBattle(BattleOutcome $outcome): void {}

    /**
     * An expedition of $userId ended (not lost). $qualifies is false for token expeditions (fleet value below
     * EngagementHooks::EXPEDITION_MIN_SHARE of the expedition reference value): count only qualifying ones.
     */
    public function onExpedition(int $universe, int $userId, bool $qualifies, int $time): void {}

    /** Engagement cronjob, every 5 minutes per universe. */
    public function tick(int $universe, int $now): void {}

    /** Typed read of a universe setting with a default for keys the config does not have yet. */
    protected function setting(int $universe, string $key, mixed $default): mixed
    {
        return $this->configs->get($universe)->$key ?? $default;
    }
}
