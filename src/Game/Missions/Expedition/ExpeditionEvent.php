<?php

namespace App\Game\Missions\Expedition;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Language;
use App\Game\Core\Singleton;

/**
 * Base of the expedition event handlers: gives them the fleet as it was when the event started, the owner's language and
 * the shared ExpeditionContext (fleet writes, report state, advanced stats). Handlers are created per event.
 */
abstract class ExpeditionEvent
{
    use GameUtilities;

    /**
     * Factor of the relics of the start planet for the expedition finds, as it was when the fleet was dispatched (1.0 without
     * relics, with the feature off or for fleets launched before relics).
     */
    protected function relicFindFactor(): float
    {
        return $this->relicInventory()->fleetFactor((int) $this->fleet['id'], (int) $this->fleet['universe']);
    }

    /** @var array<string, mixed> the fleet row when the event started */
    protected array $fleet;
    protected Language $LNG;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        protected ExpeditionContext $ctx,
    ) {
        $this->fleet = $ctx->fleet();
        $this->LNG = $ctx->LNG;
    }

    /**
     * Helper that computes expedition points and free capacity of the fleet (see ExpeditionFleetValue).
     */
    protected function fleetValue(): ExpeditionFleetValue
    {
        return new ExpeditionFleetValue($this->db, $this->configs, $this->state);
    }
}
