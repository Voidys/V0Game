<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventListener\PublicCacheListener;
use App\Routing\PublicCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * #[PublicCache]: anonymous GETs get shared-cache headers, no cookies and 304 on a matching ETag; every visitor with a
 * login cookie, a `lang` parameter or a non-GET request keeps the private defaults.
 */
final class PublicCacheListenerTest extends TestCase
{
    #[PublicCache(300)]
    public function cached(): void {}

    public function plain(): void {}

    private function handle(Request $request, string $method = 'cached', ?Response $response = null): Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $listener = new PublicCacheListener();
        $listener->onController(new ControllerEvent($kernel, [$this, $method], $request, HttpKernelInterface::MAIN_REQUEST));
        $response ??= new Response('<html nonce="x">body</html>');
        $response->headers->setCookie(new Cookie('lang', 'en'));
        $response->headers->set('Cache-Control', 'max-age=0, must-revalidate, private');
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    public function testAnonymousGetIsPubliclyCacheableWithoutCookies(): void
    {
        $response = $this->handle(Request::create('/news'));

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('s-maxage=300', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('Accept-Language', (string) $response->headers->get('Vary'));
        $this->assertStringContainsString('Cookie', (string) $response->headers->get('Vary'));
        $this->assertNotNull($response->getEtag());
    }

    public function testMatchingEtagGivesNotModifiedAndNonceIsNoChange(): void
    {
        $first = $this->handle(Request::create('/news'), 'cached', new Response('<html nonce="a">body</html>'));
        $request = Request::create('/news', server: ['HTTP_IF_NONE_MATCH' => $first->getEtag()]);
        $second = $this->handle($request, 'cached', new Response('<html nonce="b">body</html>'));

        $this->assertSame(304, $second->getStatusCode());
        $this->assertFalse($second->headers->has('Content-Security-Policy'));
    }

    public function testLoggedInOrExplicitLanguageOrPostStaysPrivate(): void
    {
        $cases = [
            'session cookie' => Request::create('/news', cookies: ['2Moons' => 'abc']),
            'remember me' => Request::create('/news', cookies: ['REMEMBERME' => 'abc']),
            'lang parameter' => Request::create('/news?lang=en'),
            'fragment' => Request::create('/news', server: ['HTTP_X_GAME_FRAGMENT' => '1']),
            'post' => Request::create('/news', 'POST'),
        ];
        foreach ($cases as $label => $request) {
            $response = $this->handle($request);
            $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'), $label);
            $this->assertNotEmpty($response->headers->getCookies(), $label . ' keeps its cookies');
        }
    }

    public function testPageWithoutTheAttributeAndWithdrawnPagesAreUntouched(): void
    {
        $this->assertStringNotContainsString('public', (string) $this->handle(Request::create('/x'), 'plain')->headers->get('Cache-Control'));

        $request = Request::create('/news');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $listener = new PublicCacheListener();
        $listener->onController(new ControllerEvent($kernel, [$this, 'cached'], $request, HttpKernelInterface::MAIN_REQUEST));
        PublicCache::withdraw($request);
        $response = new Response('message');
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
    }
}
