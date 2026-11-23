<?php

namespace App\Game\Alliance;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Lazily built set of the alliance services, sharing the game's database, configuration and state. The alliance
 * controller (and the tests) reach every alliance service through this one object.
 */
class AllianceServices
{
    /** @var array<class-string, object> */
    private array $instances = [];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    public function repository(): AllianceRepository
    {
        return $this->instances[AllianceRepository::class] ??= new AllianceRepository($this->db, $this->configs, $this->state);
    }

    public function messenger(): AllianceMessenger
    {
        return $this->instances[AllianceMessenger::class] ??= new AllianceMessenger($this->db, $this->configs, $this->state, $this->repository());
    }

    public function membership(): AllianceMembershipService
    {
        return $this->instances[AllianceMembershipService::class] ??= new AllianceMembershipService($this->db, $this->configs, $this->state, $this->repository(), $this->messenger());
    }

    public function applications(): AllianceApplicationService
    {
        return $this->instances[AllianceApplicationService::class] ??= new AllianceApplicationService($this->db, $this->configs, $this->state, $this->repository(), $this->messenger());
    }

    public function ranks(): AllianceRankService
    {
        return $this->instances[AllianceRankService::class] ??= new AllianceRankService($this->db, $this->configs, $this->state, $this->repository());
    }

    public function diplomacy(): AllianceDiplomacyService
    {
        return $this->instances[AllianceDiplomacyService::class] ??= new AllianceDiplomacyService($this->db, $this->configs, $this->state, $this->repository(), $this->messenger());
    }

    public function settings(): AllianceSettingsService
    {
        return $this->instances[AllianceSettingsService::class] ??= new AllianceSettingsService($this->db, $this->configs, $this->state);
    }

    public function memberLists(): AllianceMemberLists
    {
        return $this->instances[AllianceMemberLists::class] ??= new AllianceMemberLists($this->db, $this->configs, $this->state, $this->repository());
    }

    public function statistics(): AllianceStatistics
    {
        return $this->instances[AllianceStatistics::class] ??= new AllianceStatistics($this->db, $this->configs, $this->state, $this->repository());
    }

    public function targets(): AllianceTargetService
    {
        return $this->instances[AllianceTargetService::class] ??= new AllianceTargetService($this->db, $this->configs, $this->state);
    }

    public function activity(): AllianceActivityService
    {
        return $this->instances[AllianceActivityService::class] ??= new AllianceActivityService($this->db, $this->configs, $this->state);
    }

    public function wiki(): AllianceWikiService
    {
        return $this->instances[AllianceWikiService::class] ??= new AllianceWikiService($this->db, $this->configs, $this->state);
    }

    public function pages(): AlliancePages
    {
        return $this->instances[AlliancePages::class] ??= new AlliancePages($this->db, $this->configs, $this->state, $this->repository(), $this->statistics(), $this->diplomacy(), $this->memberLists());
    }
}
