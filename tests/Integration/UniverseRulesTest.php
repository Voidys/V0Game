<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\UniverseRules;
use App\Tests\Support\GameTestCase;

/**
 * UniverseRules: grouped, display-ready universe settings; values come from the config, switched-off features and
 * missing keys are left out cleanly.
 */
final class UniverseRulesTest extends GameTestCase
{
    /** @return array<string, array<string, array{label: string, value: mixed, unit: string}>> group id => label => row */
    private function byGroup(): array
    {
        $out = [];
        foreach ((new UniverseRules($this->configs))->groups(1) as $group) {
            foreach ($group['rows'] as $row) {
                $out[$group['id']][$row['label']] = $row;
            }
        }

        return $out;
    }

    public function testGroupsHoldTheConfiguredValues(): void
    {
        $config = $this->configs->get(1);
        $groups = $this->byGroup();

        $this->assertArrayHasKey('speeds', $groups);
        $this->assertSame(round($config->fleet_speed / 2500, 2), $groups['speeds']['ur_speed_fleet']['value']);
        $this->assertSame('x', $groups['speeds']['ur_speed_fleet']['unit']);
        $this->assertSame((int) $config->max_galaxy, (int) $groups['galaxy']['ur_galaxies']['value']);
        $this->assertSame($config->fleet_debris_percentage, $groups['combat']['ur_debris_fleet']['value']);
        $this->assertSame('%', $groups['combat']['ur_debris_fleet']['unit']);
        $this->assertArrayHasKey('features', $groups);
    }

    public function testSwitchedOffProtectionListsOnlyTheSwitch(): void
    {
        $config = $this->configs->get(1);
        $config->noob_protection = 0;
        $groups = $this->byGroup();

        $this->assertFalse($groups['protection']['ur_noob_on']['value']);
        $this->assertArrayNotHasKey('ur_noob_points', $groups['protection']);
    }

    public function testModuleSwitchDecidesTheFeatureValue(): void
    {
        $config = $this->configs->get(1);
        $modules = $config->moduls;
        $modules[MODULE_MISSION_EXPEDITION] = 0;
        $config->moduls = $modules;
        $groups = $this->byGroup();

        $this->assertFalse($groups['features']['ur_mod_expedition']['value']);
        $this->assertArrayNotHasKey('expedition', $groups);
    }
}
