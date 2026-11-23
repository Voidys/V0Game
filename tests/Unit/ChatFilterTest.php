<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Chat\ChatFilter;
use PHPUnit\Framework\TestCase;

/**
 * The text filter of the global chat: collapsed runs, masked words (whole words, case-insensitive, unicode), links, capitals, duplicates.
 */
final class ChatFilterTest extends TestCase
{
    public function testRunsOfIdenticalCharactersAreCollapsedToFour(): void
    {
        $this->assertSame(['text' => 'yeaaaah!!!! ok'], (new ChatFilter())->apply('yeaaaaaaah!!!!!!!!!! ok'));
        $this->assertSame(['text' => 'aaaa'], (new ChatFilter())->apply('aaaa'));
        $this->assertSame(['text' => 'ööööh'], (new ChatFilter())->apply('öööööööh'));
    }

    public function testBlockedWordsAreMaskedAsWholeWordsCaseInsensitiveAndUnicodeSafe(): void
    {
        $filter = new ChatFilter("Scheiße\nbad*\n  \nfoo.bar", false);

        $this->assertSame(['text' => 'what *** is this'], $filter->apply('what SCHEIßE is this'));
        $this->assertSame(['text' => '*** and *** x'], $filter->apply('badass and badger x'));
        $this->assertSame(['text' => 'Scheißehaus stays'], $filter->apply('Scheißehaus stays'), 'part of a longer word is not matched');
        $this->assertSame(['text' => 'a *** b'], $filter->apply('a foo.bar b'), 'regex characters are literal');
        $this->assertSame(['text' => 'afooxbar'], $filter->apply('afooxbar'));
    }

    public function testLinksAreRejectedWhenEnabled(): void
    {
        $on = new ChatFilter('', true);
        $links = [
            'go to https://evil.example/x', 'see www.foo', 'visit buy-stuff.com now', 'mail me at foo.de', 'HTTP://X',
            'join discord.gg/abc', 'try cheap.shop', 'foo.museum', 'a.b.cdefgh', 'ip 192.168.0.1 here', 'hxxp://bad.example',
            'evil[.]com', 'evil(.)com', 'evil{.}com', 'evil dot com', 'evil (dot) com', 'evil．com', 'evil。com', 'ok.ok',
        ];
        foreach ($links as $text) {
            $this->assertSame(['error' => ChatFilter::ERR_LINK], $on->apply($text), $text);
        }
        $fine = ['version 3.5 is out', 'e.g. this', 'i.e. that', 'hello world.', 'planet 1:2:3', 'ok.', 'wait... what', 'price 2.50 each', 'a.b', '1.5.3'];
        foreach ($fine as $text) {
            $this->assertArrayHasKey('text', $on->apply($text), $text);
        }
        $this->assertArrayHasKey('text', (new ChatFilter('', false))->apply('see www.foo.com'));
    }

    public function testAllowedDomainsAreNotLinks(): void
    {
        $filter = new ChatFilter('', true, ['https://www.Play.Example.org/', "  \n", 'wiki.example.org']);
        $this->assertArrayHasKey('text', $filter->apply('we play at play.example.org today'));
        $this->assertArrayHasKey('text', $filter->apply('see https://www.play.example.org/rules and sub.play.example.org'));
        $this->assertArrayHasKey('text', $filter->apply('read wiki.example.org'));
        $this->assertSame(['error' => ChatFilter::ERR_LINK], $filter->apply('play.example.org and evil.example.com'));
        $this->assertSame(['error' => ChatFilter::ERR_LINK], $filter->apply('notplay.example.org.evil.com'));
    }

    public function testShoutingIsRejectedOnlyForLongTexts(): void
    {
        $filter = new ChatFilter();
        $this->assertSame(['error' => ChatFilter::ERR_CAPS], $filter->apply('BUY NOW EVERYBODY HURRY'));
        $this->assertArrayHasKey('text', $filter->apply('SHORT SHOUT'), 'ten letters or fewer are fine (SHORTSHOUT has 10)');
        $this->assertArrayHasKey('text', $filter->apply('Hello EVERYONE how are you doing today'));
        $this->assertArrayHasKey('text', $filter->apply('ÄÖÜ ÄÖÜ'));
    }

    public function testRepeatedMessagesAreRejected(): void
    {
        $filter = new ChatFilter();
        $this->assertSame(['error' => ChatFilter::ERR_DUPLICATE], $filter->apply('Hello there', ['other', 'hello THERE']));
        $this->assertArrayHasKey('text', $filter->apply('Hello there', ['hello']));
        $this->assertSame(['error' => ChatFilter::ERR_DUPLICATE], $filter->apply('heyyyyyyyy', ['heyyyy']), 'compared after collapsing');
    }
}
