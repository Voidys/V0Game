<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Marks a read-only page that is the same for every anonymous visitor (of one language): PublicCacheListener then sends
 * `Cache-Control: public` with `max-age`/`s-maxage`, an ETag (304 on a matching If-None-Match) and
 * `Vary: Accept-Language, Cookie`, and drops every cookie and the CSRF meta tag from the response. Visitors with a session
 * or remember-me cookie, `?lang=` requests (they store the language cookie) and fragment requests keep the private
 * defaults. A page that ends in an error or message page must call PublicCache::withdraw() on the request.
 *
 *   #[PublicCache(300)]       listings, rules, news
 *   #[PublicCache(86400)]     immutable content (combat reports)
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class PublicCache
{
    /** request attribute set by the listener when the response is publicly cacheable (the CSRF meta tag then stays out) */
    public const ATTRIBUTE = '_public_cache';

    public function __construct(
        public readonly int $maxAge = 300,
        public readonly ?int $sharedMaxAge = null,
    ) {}

    /** Takes the page out of the public cache (error and message pages, content that depends on the viewer). */
    public static function withdraw(?\Symfony\Component\HttpFoundation\Request $request): void
    {
        $request?->attributes->remove(self::ATTRIBUTE);
    }
}
