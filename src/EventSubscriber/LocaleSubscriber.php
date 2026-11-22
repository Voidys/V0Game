<?php

namespace App\EventSubscriber;

use App\Game\Core\LanguageCatalog;
use App\Security\Account;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Picks the request locale like the legacy App\Game\Core\Language does:
 * ?lang= (remembered in the "lang" cookie) > account language > "lang" cookie
 * > Accept-Language > default locale.
 *
 * Runs after the firewall, so the logged-in account is known.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    private const COOKIE = 'lang';
    private const REMEMBER_ATTRIBUTE = '_remember_lang';

    /**
     * @param list<string> $locales
     */
    public function __construct(
        private readonly Security $security,
        private readonly LocaleSwitcher $localeSwitcher,
        #[Autowire(param: 'app.locales')]
        private readonly array $locales,
        #[Autowire(param: 'kernel.default_locale')]
        private readonly string $defaultLocale,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    /**
     * Sets the Symfony request locale (and the translator's) for non-game routes; game routes pick their own
     * language in the game bootstrap. See resolve() for the order.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        // The legacy entry scripts pick their language themselves.
        if (!$event->isMainRequest() || $event->getRequest()->attributes->has('_area')) {
            return;
        }

        $request = $event->getRequest();
        $locale = $this->resolve($request);
        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }

    /** Persists an explicitly requested `?lang=` as the `lang` cookie (read again by resolve() and the login scripts). */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && $request->attributes->has(self::REMEMBER_ATTRIBUTE)) {
            // Not httpOnly: scripts/login/main.js writes this cookie too.
            $event->getResponse()->headers->setCookie(
                Cookie::create(self::COOKIE, $request->attributes->get(self::REMEMBER_ATTRIBUTE), 2147483647, httpOnly: false),
            );
        }
    }

    /**
     * Locale priority: explicit `lang` parameter (also remembered as cookie), language of the logged-in account,
     * `lang` cookie, browser Accept-Language (two-letter prefix), default locale. Only configured locales count.
     */
    private function resolve(Request $request): string
    {
        $requested = $request->query->get('lang', $request->request->get('lang'));
        if (is_string($requested) && $this->isAvailable($requested)) {
            $request->attributes->set(self::REMEMBER_ATTRIBUTE, $requested);

            return $requested;
        }

        $user = $this->security->getUser();
        if ($user instanceof Account && $this->isAvailable($user->getLanguage())) {
            return $user->getLanguage();
        }

        $cookie = $request->cookies->get(self::COOKIE);
        if (is_string($cookie) && $this->isAvailable($cookie)) {
            return $cookie;
        }

        foreach ($request->getLanguages() as $language) {
            $code = LanguageCatalog::packCodeForTag($language);
            if ($this->isAvailable($code)) {
                return $code;
            }
        }

        return $this->defaultLocale;
    }

    private function isAvailable(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }
}
