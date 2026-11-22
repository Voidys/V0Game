<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\GameTestCase;

/**
 * Records page rows: the records come from the last statistics run, the own levels are live. A player who already beat
 * the record holds it (marked as not counted yet); staff, who never set records, get an explanation instead.
 */
final class RecordsRowTest extends GameTestCase
{
    private function row(int $own, bool $staff): string
    {
        $this->state->USER = ($this->state->USER ?? []) + ['decimal_separator' => 0, 'lang' => 'en'];
        $template = self::container()->get('twig')->createTemplate(
            "{% import 'game/page.records.default.html.twig' as rec %}{{ rec.element_row(1, rows, own, staff) }}",
        );

        return $template->render([
            'rows' => [['userID' => 7, 'username' => 'holder', 'level' => 20, 'records_optIn' => 1, 'name' => 'metal_mine']],
            'own' => $own,
            'staff' => $staff,
        ]);
    }

    public function testPlayerAboveTheRecordHoldsItUntilTheNextRun(): void
    {
        $html = $this->row(23, false);

        $this->assertStringNotContainsString('holder', $html, 'the outdated holder is replaced by the viewer');
        $this->assertStringContainsString('colorPositive', $html);
        $this->assertStringContainsString('23', $html);
    }

    public function testStaffAboveTheRecordGetsTheExplanation(): void
    {
        $html = $this->row(23, true);

        $this->assertStringContainsString('holder', $html, 'staff do not take the record');
        $this->assertStringContainsString('class="num muted"', $html);
    }

    public function testBelowTheRecordShowsTheHolder(): void
    {
        $html = $this->row(12, false);

        $this->assertStringContainsString('holder', $html);
        $this->assertStringNotContainsString('colorPositive', $html);
    }
}
