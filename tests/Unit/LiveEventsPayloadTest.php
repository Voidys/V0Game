<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\LiveEventsPayload;
use PHPUnit\Framework\TestCase;

/** The JSON payload of the live event feed: plain text, hostile count and a hash that ignores the moving countdown. */
final class LiveEventsPayloadTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function table(int $rest = 100): array
    {
        return [
            '1700000100' . '0000000007' => [
                'text' => new \Twig\Markup('<span class="colorMission1Foreign">A <a href="#">fleet</a> &amp; co<br><br>second</span>', 'UTF-8'),
                'returntime' => 1700000100, 'resttime' => $rest, 'hostile' => true,
                'id' => 7, 'mission' => 1, 'returning' => false, 'own' => false, 'cssClass' => 'colorMission1Foreign',
                'origin' => ['galaxy' => 1, 'system' => 2, 'planet' => 3, 'type' => 1, 'name' => 'X'],
                'target' => ['galaxy' => 1, 'system' => 2, 'planet' => 4, 'type' => 1, 'name' => 'Y'],
            ],
            '1700000200' . '0000000008' => [
                'text' => 'own', 'returntime' => 1700000200, 'resttime' => $rest + 100, 'hostile' => false,
            ],
        ];
    }

    public function testBuildsPlainEventsAndHostileCount(): void
    {
        $payload = LiveEventsPayload::build($this->table());

        self::assertSame(1, $payload['hostile']);
        self::assertCount(2, $payload['events']);
        self::assertSame('A fleet & co / second', $payload['events'][0]['text']);
        self::assertSame(1700000100, $payload['events'][0]['arrival']);
        self::assertSame(7, $payload['events'][0]['id']);
        self::assertTrue($payload['events'][0]['hostile']);
        self::assertFalse($payload['events'][1]['hostile']);
    }

    public function testHashIgnoresCountdownButSeesChanges(): void
    {
        $a = LiveEventsPayload::build($this->table(100))['hash'];
        self::assertSame($a, LiveEventsPayload::build($this->table(90))['hash']);

        $changed = $this->table();
        unset($changed['1700000200' . '0000000008']);
        self::assertNotSame($a, LiveEventsPayload::build($changed)['hash']);
    }

    public function testEmptyTable(): void
    {
        $payload = LiveEventsPayload::build([]);
        self::assertSame([], $payload['events']);
        self::assertSame(0, $payload['hostile']);
    }
}
