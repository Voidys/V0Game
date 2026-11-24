<?php

declare(strict_types=1);

namespace App\Game\Engagement\Relics;

use App\Game\Core\Language;
use App\Game\Engagement\EngagementFeature;
use App\Game\Missions\Expedition\ExpeditionFleetValue;

/**
 * Relic drops. An expedition that is not lost (black hole, destroyed in the fight) rolls once: the chance is
 * relics_drop_permille times min(1, the fleet's expedition points / the expedition's maximum find points), so small
 * fleets on many expeditions find almost nothing. The optional extra sources (mothership kill, final story step, season
 * winner) are separate settings, off by default. The relic itself is created by RelicInventory::award(); bots never get
 * one. Every method returns the report line (HTML, '' when nothing was found) for the message the caller sends.
 */
final class RelicDrops extends EngagementFeature
{
    /** source => [how the rarity setting is used, default chance in percent] */
    private const SOURCES = [
        'mothership' => ['min', 100],
        'story' => ['min', 50],
        'season' => ['fixed', 100],
    ];

    private const DEFAULT_RARITY = ['mothership' => 2, 'story' => 0, 'season' => 3];

    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'relics_enabled', false);
    }

    /** @return array<string, mixed> effective catalog */
    private function catalog(int $universe): array
    {
        return (new RelicCatalog($this->db, $this->configs, $this->state))->config($universe);
    }

    private function inventory(): RelicInventory
    {
        return new RelicInventory($this->db, $this->configs, $this->state);
    }

    /**
     * The roll of a finished expedition.
     *
     * @param array<string, mixed> $fleet the expedition fleet row (owner, universe, ships, resources, stay_time)
     * @return string report line, '' when nothing was found or the feature is off
     */
    public function rollForExpedition(Language $LNG, array $fleet): string
    {
        $universe = (int) $fleet['universe'];
        $userId = (int) $fleet['owner'];
        if (!$this->enabled($universe)) {
            return '';
        }
        $permille = max(0, min(1000, (int) $this->setting($universe, 'relics_drop_permille', 20)));
        if ($permille === 0) {
            return '';
        }
        $points = (float) (new ExpeditionFleetValue($this->db, $this->configs, $this->state))->pointsAndCapacity($fleet)[0];
        $reference = (float) $this->fleetFunctions()->calculateMaxFactor($universe);
        $ratio = $reference > 0 ? min(1.0, $points / $reference) : 1.0;
        if (random_int(1, 100000) > (int) round($permille * 100 * $ratio)) {
            return '';
        }

        return $this->describe($LNG, $this->inventory()->award($userId, $universe, 'expedition', (int) $fleet['stay_time']), $universe);
    }

    /**
     * The extra source "$source" (mothership, story, season) for a player: nothing unless its setting is on; rolls its
     * chance, then awards a relic of at least (or exactly, for the season) the configured rarity.
     *
     * @return string report line, '' when nothing was found
     */
    public function fromSource(string $source, int $userId, int $universe, Language $LNG, int $now): string
    {
        if (!isset(self::SOURCES[$source]) || !$this->enabled($universe) || !$this->setting($universe, 'relics_src_' . $source, false)) {
            return '';
        }
        [$mode, $defaultChance] = self::SOURCES[$source];
        $chance = max(0, min(100, (int) $this->setting($universe, 'relics_src_' . $source . '_chance', $defaultChance)));
        if ($chance < 100 && random_int(1, 100) > $chance) {
            return '';
        }
        $rarity = max(0, min(3, (int) $this->setting($universe, 'relics_src_' . $source . '_rarity', self::DEFAULT_RARITY[$source])));
        $result = $mode === 'fixed'
            ? $this->inventory()->award($userId, $universe, $source, $now, 0, $rarity)
            : $this->inventory()->award($userId, $universe, $source, $now, $rarity);

        return $this->describe($LNG, $result, $universe);
    }

    /**
     * The report line of an award result: the relic found, or the dust it turned into when the inventory was full.
     *
     * @param array{status: string, rarity: int, dust: int, relic: array<string, mixed>|null} $result
     */
    public function describe(Language $LNG, array $result, int $universe): string
    {
        $rarity = htmlspecialchars((string) $LNG['rl_rarity_' . (RelicCatalog::RARITIES[$result['rarity']] ?? 'common')], ENT_QUOTES);
        if ($result['status'] === 'relic' && $result['relic'] !== null) {
            $relic = $result['relic'];
            $set = RelicCatalog::setLabel($LNG, $this->catalog($universe), (string) $relic['set_code']);

            return '<br><br><span class="relic-found relic-found--' . (int) $relic['rarity'] . '">' . $LNG->format(
                'rl_found',
                $rarity,
                htmlspecialchars((string) $LNG['rl_slot_' . RelicCatalog::SLOTS[(int) $relic['slot']]], ENT_QUOTES),
                htmlspecialchars($set, ENT_QUOTES),
            ) . '</span>';
        }
        if ($result['status'] === 'dust') {
            return '<br><br><span class="relic-found relic-found--dust">' . $LNG->format('rl_found_full', $rarity, $result['dust']) . '</span>';
        }

        return '';
    }
}
