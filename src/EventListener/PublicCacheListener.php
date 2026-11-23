<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Routing\PublicCache;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HTTP cache of public pages for anonymous visitors (#[PublicCache]): on the way in the request is checked and marked
 * (so templates leave the per-session CSRF token out), on the way out the response gets the shared-cache headers and
 * loses its cookies. Only plain GET/HEAD requests without any session, remember-me or `lang` parameter qualify, so a
 * cached copy never carries personal data and a logged-in visitor always gets private responses.
 */
final class PublicCacheListener
{
    /** cookies that mean "this visitor has (had) a login": the session, the remember-me cookie */
    private const LOGIN_COOKIES = ['2Moons', 'REMEMBERME'];

    /** Marks the request as publicly cacheable when the action carries #[PublicCache] and the visitor is anonymous. */
    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $attribute = $event->getAttributes(PublicCache::class)[0] ?? null;
        if ($attribute === null || !$this->eligible($event->getRequest())) {
            return;
        }
        $event->getRequest()->attributes->set(PublicCache::ATTRIBUTE, $attribute);
    }

    /** Sends the cache headers (after the session listener, which would add `private`) and answers 304 on a matching ETag. */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1100)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $attribute = $request->attributes->get(PublicCache::ATTRIBUTE);
        if (!$event->isMainRequest() || !$attribute instanceof PublicCache) {
            return;
        }
        $response = $event->getResponse();
        if ($response->getStatusCode() !== 200 || str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            return;
        }

        // cookies of the game helpers arrive as plain headers, the session's as Cookie objects: remove() clears both
        $response->headers->remove('Set-Cookie');
        $response->headers->remove('Expires');
        $response->headers->remove('Pragma');
        $shared = $attribute->sharedMaxAge ?? $attribute->maxAge;
        $response->headers->set('Cache-Control', sprintf('public, max-age=%d, s-maxage=%d', $attribute->maxAge, $shared));
        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary'))));
        $response->headers->set('Vary', implode(', ', array_unique([...$vary, 'Accept-Language', 'Cookie'])));
        if (!$response->headers->has('ETag') && is_string($response->getContent())) {
            // the CSP nonces and the server clock of the game env data differ on every request: not a change of the page
            $response->setEtag(md5((string) preg_replace(['/ nonce="[^"]*"/', '/"date":\[[^\]]*\],"dateMs":\d+/'], '', $response->getContent())));
        }
        // Apache mod_deflate/mod_brotli append "-gzip"/"-br" to the ETag on the way out; the browser sends that back
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null) {
            $request->headers->set('If-None-Match', (string) preg_replace('/-(?:gzip|br)"/', '"', $ifNoneMatch));
        }
        if ($response->isNotModified($request)) {
            // the revalidated body keeps the nonce of its own response: the browser must keep that CSP header, too
            $response->headers->remove('Content-Security-Policy');
            $response->headers->remove('Content-Security-Policy-Report-Only');
        }
    }

    /** Anonymous plain read: GET/HEAD without login cookies, `lang` parameter or fragment/XHR marker. */
    private function eligible(Request $request): bool
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return false;
        }
        foreach (self::LOGIN_COOKIES as $name) {
            if ($request->cookies->has($name)) {
                return false;
            }
        }

        return !$request->query->has('lang') && !$request->query->has('ajax') && !$request->headers->has('X-Game-Fragment')
            && !$request->isXmlHttpRequest();
    }
}
