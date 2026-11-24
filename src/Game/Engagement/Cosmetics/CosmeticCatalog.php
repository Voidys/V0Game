<?php

declare(strict_types=1);

namespace App\Game\Engagement\Cosmetics;

use App\Game\Core\Language;

/**
 * Turns a cosmetic code into what the player sees: a label, a Lucide icon name, a tint class (gold/silver/bronze) and a source
 * text for the tooltip. Unknown codes get a generic label and never break. Name colours are codes mapped to a fixed whitelist
 * of CSS classes (public/styles/resource/css/app/community.css), never free CSS.
 */
final class CosmeticCatalog
{
    /** colour code => CSS class (theme-safe, defined in community.css) */
    public const COLORS = [
        'champion_gold' => 'cosm-name--gold',
    ];

    private const CATEGORY_ICONS = [
        'raider' => 'crosshair',
        'warrior' => 'swords',
        'defender' => 'shield',
        'explorer' => 'compass',
        'recycler' => 'refresh-cw',
    ];

    private const CATEGORIES = 'raider|warrior|defender|explorer|recycler';

    public function __construct(
        private Language $LNG,
    ) {}

    /** CSS class of a colour code, null for anything that is not on the whitelist. */
    public static function colorClass(?string $code): ?string
    {
        return $code !== null ? (self::COLORS[$code] ?? null) : null;
    }

    /**
     * Description of one cosmetic.
     *
     * @return array{code: string, kind: string, label: string, icon: string, tint: string, source: string, tooltip: string, colorClass: ?string}
     */
    public function describe(string $kind, string $code, string $source = ''): array
    {
        [$label, $icon, $tint] = $this->parse($kind, $code);
        $sourceLabel = $this->sourceLabel($source);

        return [
            'code' => $code,
            'kind' => $kind,
            'label' => $label,
            'icon' => $icon,
            'tint' => $tint,
            'source' => $sourceLabel,
            'tooltip' => $sourceLabel !== '' ? $label . ' - ' . $sourceLabel : $label,
            'colorClass' => $kind === Cosmetics::KIND_COLOR ? self::colorClass($code) : null,
        ];
    }

    /**
     * What the player card shows for a user: badges, the active title and the name colour class.
     *
     * @param list<array{kind: string, code: string, source: string, granted_at: int, active: bool}> $owned from Cosmetics::owned()
     * @return array{badges: list<array<string, mixed>>, title: ?string, colorClass: ?string}
     */
    public function card(array $owned): array
    {
        $badges = [];
        $title = null;
        $colorClass = null;
        foreach ($owned as $item) {
            if ($item['kind'] === Cosmetics::KIND_BADGE) {
                $badges[] = $this->describe($item['kind'], $item['code'], $item['source']);
            } elseif ($item['kind'] === Cosmetics::KIND_TITLE && $item['active']) {
                $title = $this->describe($item['kind'], $item['code'], $item['source'])['label'];
            } elseif ($item['kind'] === Cosmetics::KIND_COLOR && $item['active']) {
                $colorClass = self::colorClass($item['code']);
            }
        }

        return ['badges' => $badges, 'title' => $title, 'colorClass' => $colorClass];
    }

    /** @return array{0: string, 1: string, 2: string} label, icon, tint */
    private function parse(string $kind, string $code): array
    {
        $category = self::CATEGORIES;
        if (preg_match('/^season(\d{1,5})_(' . $category . ')_(\d{1,2})$/', $code, $m) === 1) {
            $rank = (int) $m[3];

            return [
                $this->LNG->format('cosm_season_badge', $m[1], $this->LNG['season_cat_' . $m[2]], $rank),
                self::CATEGORY_ICONS[$m[2]],
                [1 => 'gold', 2 => 'silver', 3 => 'bronze'][$rank] ?? 'plain',
            ];
        }
        if (preg_match('/^season(\d{1,5})_(' . $category . ')_champion$/', $code, $m) === 1) {
            return [$this->LNG->format('cosm_season_champion', $this->LNG['season_cat_' . $m[2]], $m[1]), 'trophy', 'gold'];
        }
        if (preg_match('/^contract_streak_(\d{1,3})$/', $code, $m) === 1) {
            return [$this->LNG->format('cosm_contract_streak', $m[1]), 'star', (int) $m[1] >= 12 ? 'gold' : 'silver'];
        }
        if (preg_match('/^ally_goal_(\d{1,3})$/', $code, $m) === 1) {
            return [$this->LNG->format('cosm_ally_goal', $m[1]), 'users', match (true) {
                (int) $m[1] >= 25 => 'gold',
                (int) $m[1] >= 10 => 'silver',
                default => 'bronze',
            }];
        }
        if ($kind === Cosmetics::KIND_COLOR && $code === 'champion_gold') {
            return [$this->LNG['cosm_color_champion_gold'], 'trophy', 'gold'];
        }

        return [$this->LNG['cosm_unknown'], 'award', 'plain'];
    }

    private function sourceLabel(string $source): string
    {
        if (preg_match('/^season:(\d{1,5})$/', $source, $m) === 1) {
            return $this->LNG->format('cosm_source_season', $m[1]);
        }

        return match ($source) {
            'contracts' => $this->LNG['cosm_source_contracts'],
            'alliance_goal' => $this->LNG['cosm_source_alliance_goal'],
            default => '',
        };
    }
}
