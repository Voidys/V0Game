<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\CsrfPolicy;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * CSRF token helpers for the templates: `csrf_token()` (the token string), `csrf_meta()` (the
 * <meta name="csrf-token"> tag every layout carries; public/scripts/app/csrf.js reads it) and
 * `csrf_field()` (a hidden input, for forms that are not submitted by the browser's own submit).
 */
final class CsrfExtension extends AbstractExtension
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $tokens,
        private readonly RequestStack $requests,
    ) {}

    /** @return list<TwigFunction> csrf_token(), csrf_meta(), csrf_field() */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_token', $this->token(...)),
            new TwigFunction('csrf_meta', $this->meta(...), ['is_safe' => ['all']]),
            new TwigFunction('csrf_field', $this->field(...), ['is_safe' => ['all']]),
        ];
    }

    /** The session's CSRF token (masked, valid for the whole session). */
    public function token(): string
    {
        return $this->tokens->getToken(CsrfPolicy::TOKEN_ID)->getValue();
    }

    /** `<meta name="csrf-token">` tag, read by public/scripts/app/csrf.js to sign fetch/XHR requests. */
    public function meta(): string
    {
        // a publicly cacheable page (#[PublicCache]) is the same for everybody: no session, so no token
        if ($this->requests->getMainRequest()?->attributes->has(\App\Routing\PublicCache::ATTRIBUTE)) {
            return '';
        }

        return '<meta name="csrf-token" content="' . htmlspecialchars($this->token(), ENT_QUOTES) . '">';
    }

    /** Hidden `_csrf` input for a form (CsrfListener removes the field again before the game code sees the request). */
    public function field(): string
    {
        return '<input type="hidden" name="' . CsrfPolicy::FIELD . '" value="' . htmlspecialchars($this->token(), ENT_QUOTES) . '">';
    }
}
