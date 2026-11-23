<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Language;
use App\Tests\Support\GameTestCase;

/** Lean-boot polls read the language packs on first use: the texts are the same as with includeData(). */
final class LazyLanguageTest extends GameTestCase
{
    public function testLazyPacksGiveTheSameTextsOnFirstAccess(): void
    {
        $eager = new Language($this->configs, 'en');
        $eager->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);

        $lazy = new Language($this->configs, 'en');
        $lazy->includeDataLazily(['L18N', 'INGAME', 'TECH', 'CUSTOM']);

        $this->assertSame($eager['sys_module_inactive'], $lazy['sys_module_inactive']);
        $this->assertSame($eager['tech'][202], $lazy['tech'][202]);
        $this->assertTrue(isset($lazy['lm_overview']));
        $this->assertSame($eager->format('cff_aproaching'), $lazy->format('cff_aproaching'));
    }

    public function testWritingBeforeReadingKeepsTheLoadedTexts(): void
    {
        $lazy = new Language($this->configs, 'en');
        $lazy->includeDataLazily(['INGAME']);
        $lazy['my_key'] = 'mine';

        $this->assertSame('mine', $lazy['my_key']);
        $this->assertNotSame('lm_overview', $lazy['lm_overview']);
    }
}
