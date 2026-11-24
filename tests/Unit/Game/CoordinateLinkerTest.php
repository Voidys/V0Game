<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\Messages\CoordinateLinker;
use PHPUnit\Framework\TestCase;

final class CoordinateLinkerTest extends TestCase
{
    private static function wrap(int $g, int $s, int $p): string
    {
        return '<b>' . $g . '-' . $s . '-' . $p . '</b>';
    }

    public function testLinksPlainCoordinates(): void
    {
        self::assertSame('Target <b>1-2-3</b> found', CoordinateLinker::link('Target [1:2:3] found', self::wrap(...)));
    }

    public function testLeavesLinkedAndAttributeCoordinatesAlone(): void
    {
        $html = '<a href="x" title="[1:2:3]">Planet [1:2:3]</a> and <span title="[4:5:6]">[4:5:6]</span>';

        self::assertSame(
            '<a href="x" title="[1:2:3]">Planet [1:2:3]</a> and <span title="[4:5:6]"><b>4-5-6</b></span>',
            CoordinateLinker::link($html, self::wrap(...)),
        );
    }

    public function testLinksAfterAnAnchorClosed(): void
    {
        self::assertSame('<a href="x">a</a> <b>7-8-9</b>', CoordinateLinker::link('<a href="x">a</a> [7:8:9]', self::wrap(...)));
    }

    public function testIgnoresOtherBracketText(): void
    {
        self::assertSame('[1:2] [a:b:c] [1:2:3:4]', CoordinateLinker::link('[1:2] [a:b:c] [1:2:3:4]', self::wrap(...)));
    }

    public function testLinkedCoordinateGetsIconAfterAnchor(): void
    {
        $icon = static fn(int $g, int $s, int $p): string => '<i class="mail-coord-fleet">' . $g . $s . $p . '</i>';

        self::assertSame(
            'at <a href="x">[1:2:3]</a><i class="mail-coord-fleet">123</i> done',
            CoordinateLinker::link('at <a href="x">[1:2:3]</a> done', self::wrap(...), $icon),
        );
        self::assertSame(
            '<a href="x"><b>[1:2:3]</b></a><i class="mail-coord-fleet">123</i>',
            CoordinateLinker::link('<a href="x"><b>[1:2:3]</b></a>', self::wrap(...), $icon),
        );
    }

    public function testAnchorWithOtherTextOrExistingIconGetsNothing(): void
    {
        $icon = static fn(int $g, int $s, int $p): string => '<i class="mail-coord-fleet">x</i>';

        $named = '<a href="x">Main Planet [1:2:3]</a>';
        self::assertSame($named, CoordinateLinker::link($named, self::wrap(...), $icon));

        $done = '<a href="x">[1:2:3]</a><a class="mail-coord-fleet" href="y">i</a>';
        self::assertSame($done, CoordinateLinker::link($done, self::wrap(...), $icon));
    }
}
