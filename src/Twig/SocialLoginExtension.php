<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\Social\SocialSettings;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `social_providers()` for the templates: the providers of the social login a visitor can use (switched on and configured)
 * with id, label, icon name and the URL that starts the login (stays inside the current universe directory).
 */
final class SocialLoginExtension extends AbstractExtension
{
    public function __construct(
        private readonly SocialSettings $settings,
        private readonly UrlGeneratorInterface $router,
    ) {}

    /** @return list<TwigFunction> social_providers() */
    public function getFunctions(): array
    {
        return [new TwigFunction('social_providers', $this->providers(...))];
    }

    /**
     * @return list<array{id: string, label: string, icon: string, url: string}>
     */
    public function providers(): array
    {
        $buttons = [];
        foreach ($this->settings->enabledProviders() as $provider) {
            $buttons[] = [
                'id' => $provider->id,
                'label' => $provider->label,
                'icon' => $provider->icon,
                'url' => $this->router->generate('login_social_start', ['provider' => $provider->id]),
            ];
        }

        return $buttons;
    }
}
