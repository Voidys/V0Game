<?php

namespace App\Runtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Admin update notice: looks up the latest GitHub release of the project and compares it with the installed version
 * (install/VERSION). The answer is cached in the app cache (6 h, failures 30 min) so the admin dashboard makes at most
 * one short outbound request per period. Any failure yields "no notice"; this class never throws.
 */
final class UpdateCheck
{
    public const RELEASE_URL = 'https://api.github.com/repos/Voidys/V0Game/releases/latest';
    public const UPDATE_DOCS_URL = InstallService::UPDATE_DOCS_URL;

    private const CACHE_KEY = 'admin_update_check';
    private const TTL_OK = 6 * 3600;
    private const TTL_FAILED = 1800;
    private const TIMEOUT = 3.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly RuntimeContext $runtime,
        #[Autowire('%env(default:app.update_check_url:UPDATE_CHECK_URL)%')]
        private readonly string $apiUrl = self::RELEASE_URL,
    ) {}

    /** Version of this installation (install/VERSION), '' when unknown. */
    public function installedVersion(): string
    {
        $file = $this->runtime->rootPath . 'install/VERSION';

        return is_readable($file) ? trim((string) file_get_contents($file)) : '';
    }

    /**
     * Cached check result.
     *
     * @return array{ok: bool, checkedAt: int, current: string, latest?: string, name?: string, url?: string, published?: int, notes?: string, newer?: bool}
     */
    public function status(bool $refresh = false, ?string $currentOverride = null): array
    {
        if ($refresh) {
            $this->cache->delete(self::CACHE_KEY);
        }

        $current = $currentOverride ?? $this->installedVersion();
        try {
            $data = $this->cache->get(self::CACHE_KEY, function ($item): array {
                $result = $this->fetch();
                $item->expiresAfter($result['ok'] ? self::TTL_OK : self::TTL_FAILED);

                return $result;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('update check cache failed: ' . $e->getMessage());
            $data = ['ok' => false, 'checkedAt' => time()];
        }

        $data['current'] = $current;
        if ($data['ok'] && $current !== '') {
            $data['newer'] = version_compare(ltrim($data['latest'], 'vV'), ltrim($current, 'vV'), '>');
        } else {
            $data['newer'] = false;
        }

        return $data;
    }

    /** @return array{ok: bool, checkedAt: int, latest?: string, name?: string, url?: string, published?: int, notes?: string} */
    private function fetch(): array
    {
        $failed = ['ok' => false, 'checkedAt' => time()];
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl, [
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'headers' => ['User-Agent' => 'V0Game-update-check', 'Accept' => 'application/vnd.github+json'],
            ]);
            $json = $response->toArray();
            $tag = (string) ($json['tag_name'] ?? '');
            if ($tag === '') {
                return $failed;
            }
            $url = (string) ($json['html_url'] ?? '');
            $notes = trim((string) preg_replace(['/^\s*(#+|[-*])\s*/m', '/\s+/'], ['', ' '], strip_tags((string) ($json['body'] ?? ''))));

            return [
                'ok' => true,
                'checkedAt' => time(),
                'latest' => ltrim($tag, 'vV'),
                'name' => (string) ($json['name'] ?? $tag),
                'url' => str_starts_with($url, 'https://') ? $url : 'https://github.com/Voidys/V0Game/releases',
                'published' => (int) strtotime((string) ($json['published_at'] ?? '')),
                'notes' => mb_strimwidth($notes, 0, 300, '…'),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('update check failed: ' . $e->getMessage());

            return $failed;
        }
    }
}
