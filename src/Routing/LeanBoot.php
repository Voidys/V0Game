<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Lean boot of a read-only polling action: the game bootstrap skips parts the action does not use. Every security
 * gate (login/session, ban, universe status, module, rate limit, CSRF) still runs.
 *
 *   #[LeanBoot(LeanBoot::PLANET, LeanBoot::DATA)]
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class LeanBoot
{
    /** no planet row lock and no planet list (the action does not read state->PLANET) */
    public const PLANET = 'planet';
    /** no element catalog (GameData), loaded only when due fleet events have to be processed */
    public const DATA = 'data';
    /** language packs are read on first use (the action prints no texts except on the gate error pages) */
    public const LANGUAGE = 'language';

    /** @var list<string> */
    public readonly array $skip;

    public function __construct(string ...$skip)
    {
        $this->skip = array_values($skip);
    }
}
