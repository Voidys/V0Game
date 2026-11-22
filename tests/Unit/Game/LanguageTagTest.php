<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\Core\LanguageCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Browser language tags map to the two-letter pack codes (lang columns are varchar(2)): Brazilian Portuguese is "br",
 * Traditional Chinese "tw", Filipino "tl"; every other tag uses its primary subtag.
 */
final class LanguageTagTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function tags(): iterable
    {
        yield 'pt-BR' => ['pt-BR', 'br'];
        yield 'pt_BR (Symfony)' => ['pt_BR', 'br'];
        yield 'pt-PT' => ['pt-PT', 'pt'];
        yield 'zh-TW' => ['zh-TW', 'tw'];
        yield 'zh-HK' => ['zh-HK', 'tw'];
        yield 'zh_Hant_TW (Symfony)' => ['zh_Hant_TW', 'tw'];
        yield 'zh-CN' => ['zh-CN', 'zh'];
        yield 'zh-Hans' => ['zh-Hans', 'zh'];
        yield 'fil' => ['fil', 'tl'];
        yield 'de-AT' => ['de-AT', 'de'];
        yield 'cs' => ['cs', 'cs'];
    }

    #[DataProvider('tags')]
    public function testBrowserTagMapsToPackCode(string $tag, string $code): void
    {
        self::assertSame($code, LanguageCatalog::packCodeForTag($tag));
    }

    public function testEveryPackHasAValidHtmlTag(): void
    {
        foreach ((new LanguageCatalog())->getPacks() as $code => $pack) {
            self::assertMatchesRegularExpression('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', (string) ($pack['tag'] ?? ''), "pack $code needs a BCP 47 tag for <html lang>");
            self::assertSame(2, strlen((string) $code), "pack code $code must fit the varchar(2) lang columns");
        }
    }
}
