<?php

declare(strict_types=1);

namespace App\Twig;

use App\Game\Messages\CoordinateLinker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

/**
 * `link_coords` filter for stored message HTML: plain [g:s:p] becomes a link to the galaxy system plus a small
 * fleet icon to the fleet page with the target prefilled (optional mission). Applied at render time only.
 */
final class MessageCoordsExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly TemplateExtension $template,
    ) {}

    /** @return list<TwigFilter> */
    public function getFilters(): array
    {
        return [new TwigFilter('link_coords', $this->linkCoords(...), ['is_safe' => ['all']])];
    }

    /** @param int $mission mission id preselected on the fleet page (0 = none); $label title of the fleet icon */
    public function linkCoords(mixed $html, int $mission = 0, mixed $label = ''): Markup
    {
        $label = strip_tags((string) $label);

        $icon = function (int $g, int $s, int $p) use ($mission, $label): string {
            $fleet = ['galaxy' => $g, 'system' => $s, 'planet' => $p] + ($mission > 0 ? ['target_mission' => $mission] : []);

            return '<a class="mail-coord-fleet" href="' . htmlspecialchars($this->router->generate('game_fleet_table_show', $fleet), ENT_QUOTES) . '" title="' . htmlspecialchars($label, ENT_QUOTES) . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '">'
                . $this->template->icon('send') . '</a>';
        };
        $plain = fn(int $g, int $s, int $p): string => '<a class="mail-coord" href="' . htmlspecialchars($this->router->generate('game_galaxy_show', ['galaxy' => $g, 'system' => $s]), ENT_QUOTES) . '">[' . $g . ':' . $s . ':' . $p . ']</a>' . $icon($g, $s, $p);

        $out = CoordinateLinker::link((string) $html, $plain, $icon);

        return new Markup($out, 'UTF-8');
    }
}
