<?php

namespace App\Game\Core;

/**
 * Lazily built game utility objects, sharing the host's injected services.
 * Hosts provide `$db` (Database), `$configs` (ConfigRegistry) and `$state` (Singleton).
 */
trait GameUtilities
{
    /** @var array<class-string, object> */
    private array $gameUtilityInstances = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function gameUtility(string $class): object
    {
        return $this->gameUtilityInstances[$class] ??= new $class($this->db, $this->configs, $this->state);
    }

    protected function http(): HTTP
    {
        return $this->gameUtilityInstances[HTTP::class] ??= new HTTP($this->state);
    }

    /** typed request input (`$this->input()->int('id')`) */
    protected function input(): RequestInput
    {
        return $this->gameUtilityInstances[RequestInput::class] ??= new RequestInput($this->state);
    }

    /** factory of the typed User/Planet snapshots built from the state arrays */
    protected function models(): \App\Game\Model\ModelFactory
    {
        return $this->gameUtilityInstances[\App\Game\Model\ModelFactory::class] ??= new \App\Game\Model\ModelFactory($this->state);
    }

    /** typed read-only catalog of the game elements (cost, groups, columns, ...), built once by GameData::load() */
    protected function elements(): \App\Game\Model\ElementCatalog
    {
        if ($this->state->elementCatalog === null && $this->state->completeLean !== null) {
            // a lean-booted request that needs the catalog after all: load it now
            ($this->state->completeLean)(false);
        }

        return $this->state->elementCatalog ?? throw new \LogicException('game data not loaded');
    }

    /** typed snapshot of the current player (`$USER`) for reading; never cached, writes keep using the state array */
    protected function player(): \App\Game\Model\User
    {
        return $this->models()->user($this->state->USER);
    }

    /** typed snapshot of the current planet (`$PLANET`) for reading; never cached, writes keep using the state array */
    protected function planet(): \App\Game\Model\Planet
    {
        return $this->models()->planet($this->state->PLANET);
    }

    protected function planetPositions(): \App\Game\Core\Player\PlanetPositions
    {
        return $this->gameUtility(\App\Game\Core\Player\PlanetPositions::class);
    }

    protected function planetManager(): \App\Game\Core\Player\PlanetManager
    {
        return $this->gameUtility(\App\Game\Core\Player\PlanetManager::class);
    }

    protected function planetReshuffle(): \App\Game\Core\Player\PlanetReshuffle
    {
        return $this->gameUtility(\App\Game\Core\Player\PlanetReshuffle::class);
    }

    protected function queueOverview(): \App\Game\Core\Player\QueueOverview
    {
        return $this->gameUtility(\App\Game\Core\Player\QueueOverview::class);
    }

    protected function planetQueries(): \App\Game\Core\Player\PlanetQueries
    {
        return $this->gameUtility(\App\Game\Core\Player\PlanetQueries::class);
    }

    protected function playerRegistration(): \App\Game\Core\Player\PlayerRegistration
    {
        return $this->gameUtility(\App\Game\Core\Player\PlayerRegistration::class);
    }

    protected function playerDeletion(): \App\Game\Core\Player\PlayerDeletion
    {
        return $this->gameUtility(\App\Game\Core\Player\PlayerDeletion::class);
    }

    protected function playerMessenger(): \App\Game\Core\Player\PlayerMessenger
    {
        return $this->gameUtility(\App\Game\Core\Player\PlayerMessenger::class);
    }

    protected function vacationMode(): \App\Game\Core\Player\VacationMode
    {
        return $this->gameUtility(\App\Game\Core\Player\VacationMode::class);
    }

    protected function playerLookup(): \App\Game\Core\Player\PlayerLookup
    {
        return $this->gameUtility(\App\Game\Core\Player\PlayerLookup::class);
    }

    protected function protectionRules(): \App\Game\Core\Player\ProtectionRules
    {
        return $this->gameUtility(\App\Game\Core\Player\ProtectionRules::class);
    }

    protected function honorPoints(): \App\Game\Core\Player\HonorPoints
    {
        return $this->gameUtility(\App\Game\Core\Player\HonorPoints::class);
    }

    protected function playerCharts(): \App\Game\Core\Player\PlayerCharts
    {
        return $this->gameUtility(\App\Game\Core\Player\PlayerCharts::class);
    }

    protected function fleetFunctions(): FleetFunctions
    {
        return $this->gameUtility(FleetFunctions::class);
    }

    protected function buildFunctions(): BuildFunctions
    {
        return $this->gameUtility(BuildFunctions::class);
    }

    protected function accountUtil(): AccountUtil
    {
        return $this->gameUtility(AccountUtil::class);
    }

    protected function expeditionShips(): ExpeditionFindableShipsHandler
    {
        return $this->gameUtility(ExpeditionFindableShipsHandler::class);
    }

    protected function achievement(): Achievement
    {
        return $this->gameUtility(Achievement::class);
    }

    /** Universe needs no database: the installer asks for it before a config exists. */
    protected function universe(): Universe
    {
        return $this->gameUtilityInstances[Universe::class] ??= new Universe($this->state, $this->sessions());
    }

    protected function cronjobs(): \App\Game\Cronjob\Cronjob
    {
        return $this->gameUtility(\App\Game\Cronjob\Cronjob::class);
    }

    /** renderer of the game templates (App\View\GameView) */
    protected function gameView(): \App\View\GameView
    {
        return $this->gameUtility(\App\View\GameView::class);
    }

    protected function serverInfo(): ServerInfo
    {
        return $this->gameUtility(ServerInfo::class);
    }

    protected function sessions(): SessionManager
    {
        return $this->gameUtility(SessionManager::class);
    }

    protected function mailer(): Mail
    {
        return $this->gameUtility(Mail::class);
    }

    /** Discord needs no database: it also reports errors when the database is down. */
    protected function discord(): Discord
    {
        return $this->gameUtilityInstances[Discord::class] ??= new Discord($this->configs, $this->state);
    }

    /** player notifications (Web Push, Discord): `notify($userId, $type, $title, $body, $url)` */
    protected function notifications(): \App\Game\Notifications\NotificationDispatcher
    {
        return $this->gameUtility(\App\Game\Notifications\NotificationDispatcher::class);
    }

    protected function viewHelpers(): ViewHelpers
    {
        return $this->gameUtility(ViewHelpers::class);
    }

    protected function maintenance(): Maintenance
    {
        return $this->gameUtility(Maintenance::class);
    }

    protected function migrations(): MigrationRunner
    {
        return $this->gameUtility(MigrationRunner::class);
    }

    protected function hangarFilters(): HangarFilters
    {
        return $this->gameUtility(HangarFilters::class);
    }

    protected function allianceDepot(): AllianceDepot
    {
        return $this->gameUtility(AllianceDepot::class);
    }

    protected function resourceUpdate(): ResourceUpdate
    {
        return $this->gameUtility(ResourceUpdate::class);
    }

    protected function spyReport(): SpyReport
    {
        return $this->gameUtility(SpyReport::class);
    }

    protected function achievementGuard(): \App\Game\Achievements\AchievementGuard
    {
        return $this->gameUtility(\App\Game\Achievements\AchievementGuard::class);
    }

    protected function returningPlayers(): \App\Game\Engagement\Returning\ReturningPlayers
    {
        return $this->gameUtility(\App\Game\Engagement\Returning\ReturningPlayers::class);
    }

    protected function battleAchievements(): \App\Game\Achievements\BattleAchievement
    {
        return $this->gameUtility(\App\Game\Achievements\BattleAchievement::class);
    }

    protected function miscAchievements(): \App\Game\Achievements\MiscAchievement
    {
        return $this->gameUtility(\App\Game\Achievements\MiscAchievement::class);
    }

    protected function planetRessAchievements(): \App\Game\Achievements\PlanetRessAchievement
    {
        return $this->gameUtility(\App\Game\Achievements\PlanetRessAchievement::class);
    }

    protected function fleetAchievements(): \App\Game\Achievements\FleetAchievement
    {
        return $this->gameUtility(\App\Game\Achievements\FleetAchievement::class);
    }

    protected function playerUtilAchievements(): \App\Game\Achievements\PlayerUtilAchievement
    {
        return $this->gameUtility(\App\Game\Achievements\PlayerUtilAchievement::class);
    }

    /** admin-planned universe event factors (`factor($universe, EventModifiers::FLEET_SPEED)`, 1.0 when off) */
    protected function eventModifiers(): \App\Game\Engagement\Events\EventModifiers
    {
        return $this->gameUtility(\App\Game\Engagement\Events\EventModifiers::class);
    }

    /** late-joiner production boost */
    protected function catchUpBoost(): \App\Game\Engagement\Events\CatchUpBoost
    {
        return $this->gameUtility(\App\Game\Engagement\Events\CatchUpBoost::class);
    }

    /** admin-planned universe events: presets, storage, banner data and the start/end announcements */
    protected function universeEvents(): \App\Game\Engagement\Events\UniverseEvents
    {
        return $this->gameUtility(\App\Game\Engagement\Events\UniverseEvents::class);
    }

    /** relic bonuses of a planet (factors are 1.0 and no query runs while the relics are off) */
    protected function relicBonus(): \App\Game\Engagement\Relics\RelicBonus
    {
        return $this->gameUtility(\App\Game\Engagement\Relics\RelicBonus::class);
    }

    /** relic inventory actions (equip, dismantle, reforge, drops) */
    protected function relicInventory(): \App\Game\Engagement\Relics\RelicInventory
    {
        return $this->gameUtility(\App\Game\Engagement\Relics\RelicInventory::class);
    }
}
