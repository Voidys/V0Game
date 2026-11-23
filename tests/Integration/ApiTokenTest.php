<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\ApiTokens;
use App\Api\PlayerApi;
use App\Controller\Api\ApiController;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Personal API tokens: creation (hash only, limit of five), authentication of the bearer token (valid, revoked, wrong scope, other
 * universe, banned, rate limit) through the API controller, and the payload of /me, /empire and the reports.
 */
final class ApiTokenTest extends GameTestCase
{
    private ApiTokens $tokens;
    private ApiController $controller;
    private static int $ip = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = self::container()->get(ApiTokens::class);
        $this->controller = self::container()->get(ApiController::class);
    }

    private function token(int $userId, array $scopes = ['reports', 'empire'], int $universe = 1): string
    {
        return (string) $this->tokens->create($userId, $universe, 'test', $scopes, time());
    }

    /** @return array{0: int, 1: array<string, mixed>} status and decoded body */
    private function call(string $action, ?string $token, string $uni = '', string $query = ''): array
    {
        $request = Request::create('/' . $uni . 'api/v1/x' . $query, 'GET', [], [], [], ['REMOTE_ADDR' => '10.9.' . intdiv(++self::$ip, 250) . '.' . (self::$ip % 250)]);
        $request->attributes->set('uni', $uni);
        if ($token !== null) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }
        /** @var JsonResponse $response */
        $response = $this->controller->$action($request);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    public function testTokenIsStoredHashedAndLimitedToFive(): void
    {
        [$user] = $this->createPlayer();
        $plain = $this->token((int) $user['id']);

        $this->assertStringStartsWith('v0_', $plain);
        $this->assertSame(67, strlen($plain));
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%API_TOKENS%% WHERE token_hash = :h;', [':h' => hash('sha256', $plain)], 'c'));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%API_TOKENS%% WHERE token_hash = :h;', [':h' => $plain], 'c'));

        for ($i = 1; $i < ApiTokens::MAX_ACTIVE; $i++) {
            $this->token((int) $user['id']);
        }
        $this->assertNull($this->tokens->create((int) $user['id'], 1, 'sixth', ['reports'], time()));
        $id = $this->tokens->listFor((int) $user['id'])[0]['id'];
        $this->assertTrue($this->tokens->revoke((int) $user['id'], (int) $id));
        $this->assertNotNull($this->tokens->create((int) $user['id'], 1, 'again', ['reports'], time()));
    }

    public function testAuthentication(): void
    {
        [$user] = $this->createPlayer();
        $id = (int) $user['id'];
        $plain = $this->token($id);

        [$status, $body] = $this->call('me', $plain);
        $this->assertSame(200, $status);
        $this->assertSame($id, $body['id']);

        $this->assertSame(401, $this->call('me', null)[0]);
        $this->assertSame(401, $this->call('me', 'v0_' . str_repeat('0', 64))[0]);
        // another universe prefix than the token's
        $this->assertSame(401, $this->call('me', $plain, 'uni2/')[0]);
        $this->assertSame(200, $this->call('me', $plain, 'uni1/')[0]);

        // revoked
        $tokenId = (int) $this->tokens->listFor($id)[0]['id'];
        $this->tokens->revoke($id, $tokenId);
        $this->assertSame(401, $this->call('me', $plain)[0]);
    }

    public function testScopeAndBan(): void
    {
        [$user] = $this->createPlayer();
        $id = (int) $user['id'];
        $reportsOnly = $this->token($id, ['reports']);

        [$status, $body] = $this->call('empire', $reportsOnly);
        $this->assertSame(403, $status);
        $this->assertSame('missing_scope', $body['error']);
        $this->assertSame(200, $this->call('spyReports', $reportsOnly)[0]);

        $this->db->update('UPDATE %%USERS%% SET bana = 1, banaday = :until WHERE id = :id;', [':until' => time() + 3600, ':id' => $id]);
        [$status, $body] = $this->call('me', $reportsOnly);
        $this->assertSame(403, $status);
        $this->assertSame('banned', $body['error']);
    }

    public function testRateLimitPerToken(): void
    {
        [$user] = $this->createPlayer();
        $plain = $this->token((int) $user['id']);

        $last = 200;
        for ($i = 0; $i < 61; $i++) {
            $last = $this->call('me', $plain)[0];
        }
        $this->assertSame(429, $last);
    }

    public function testLastUsedIsTouchedOncePerMinute(): void
    {
        [$user] = $this->createPlayer();
        $id = (int) $user['id'];
        $plain = $this->token($id);
        $tokenId = (int) $this->tokens->listFor($id)[0]['id'];

        $this->tokens->touch($tokenId, 1_800_000_000);
        $this->tokens->touch($tokenId, 1_800_000_030);
        $this->assertSame(1_800_000_000, (int) $this->db->selectSingle('SELECT last_used_at FROM %%API_TOKENS%% WHERE id = :id;', [':id' => $tokenId], 'last_used_at'));
        $this->tokens->touch($tokenId, 1_800_000_061);
        $this->assertSame(1_800_000_061, (int) $this->db->selectSingle('SELECT last_used_at FROM %%API_TOKENS%% WHERE id = :id;', [':id' => $tokenId], 'last_used_at'));
        $this->assertNotSame('', $plain);
    }

    public function testEmpireAppliesAFinishedBuildingQueueOnceAndSavesIt(): void
    {
        $start = time() - 3600;
        [$user, $planet] = $this->createPlayer([], ['metal_mine' => 3, 'last_update' => $start]);
        $this->db->update('UPDATE %%PLANETS%% SET b_building = :end, b_building_id = :queue WHERE id = :id;', [
            ':end' => $start + 100,
            ':queue' => \App\Game\Core\StoredData::encode([[1, 4, 100.0, (float) ($start + 100), 'build', [901 => 100.0, 902 => 25.0]]]),
            ':id' => $planet['id'],
        ]);
        $api = self::container()->get(PlayerApi::class);

        $first = $api->empire((int) $user['id'], time());
        $this->assertSame(4, $first['planets'][0]['buildings']['metal_mine']);
        $saved = $this->loadPlanet((int) $planet['id']);
        $this->assertSame(4, (int) $saved['metal_mine'], 'the finished building is persisted');
        $this->assertEmpty($saved['b_building_id']);

        $second = $api->empire((int) $user['id'], time());
        $this->assertSame(4, $second['planets'][0]['buildings']['metal_mine'], 'applied once, not again');
    }

    public function testEmpireAndReportsPayload(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 1234, 'metal_mine' => 7, 'solar_plant' => 10]);
        $id = (int) $user['id'];
        $api = self::container()->get(PlayerApi::class);

        $empire = $api->empire($id, time() + 3600);
        $this->assertCount(1, $empire['planets']);
        $home = $empire['planets'][0];
        $this->assertSame((int) $planet['id'], $home['id']);
        $this->assertSame('planet', $home['type']);
        $this->assertSame(7, $home['buildings']['metal_mine']);
        $this->assertGreaterThanOrEqual(1234, $home['resources']['metal']);
        $this->assertGreaterThan(0, $home['production_per_hour']['metal']);
        $this->assertArrayHasKey('small_ship_cargo', $home['ships']);
        $this->assertArrayHasKey('spy_tech', $empire['research']);

        $this->db->insert('INSERT INTO %%MESSAGES%% SET message_owner = :u, message_time = :t, message_type = 0, message_universe = 1, message_text = :text;', [
            ':u' => $id, ':t' => 1_800_000_000, ':text' => json_encode(['targetUserName' => 'foe', 'targetPlanet' => ['name' => 'P', 'planet_type' => 1, 'galaxy' => 2, 'system' => 3, 'planet' => 4], 'spyData' => [900 => [901 => 55]], 'time' => 1_800_000_000]),
        ]);
        $messageId = (int) $this->db->lastInsertId();
        $this->db->insert('INSERT INTO %%USERS_TO_MESSAGES%% SET message_id = :m, user_id = :u;', [':m' => $messageId, ':u' => $id]);

        $reports = $api->spyReports($id, 1, 0, 10);
        $this->assertCount(1, $reports);
        $this->assertSame('foe', $reports[0]['target']['player']);
        $this->assertSame(['galaxy' => 2, 'system' => 3, 'planet' => 4], $reports[0]['target']['coords']);
        $this->assertSame(55, $reports[0]['data']['resources']['metal']);
        $this->assertSame([], $api->spyReports($id, 1, 1_800_000_001, 10));
    }
}
