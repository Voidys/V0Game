<?php

declare(strict_types=1);

namespace App\Game\Messages;

/**
 * Render-time linking of plain coordinates ([galaxy:system:planet]) in stored message HTML. Only text nodes
 * outside anchors, scripts, styles and buttons are touched; tags, attributes and the stored text stay as they are.
 */
final class CoordinateLinker
{
    private const TOKEN = '/(<!--.*?-->|<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)/s';
    private const COORDINATES = '/\[(\d{1,2}):(\d{1,3}):(\d{1,3})\]/';
    private const SKIPPED = ['a', 'script', 'style', 'button', 'textarea'];

    /**
     * An anchor whose text is exactly one coordinate keeps its link and gets $icon(...) appended after </a> (once).
     *
     * @param callable(int, int, int): string $render HTML that replaces one plain coordinate (receives galaxy, system, planet)
     * @param (callable(int, int, int): string)|null $icon HTML appended after an anchor that already links a coordinate
     */
    public static function link(string $html, callable $render, ?callable $icon = null): string
    {
        if (!str_contains($html, '[')) {
            return $html;
        }

        $parts = preg_split(self::TOKEN, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $depth = 0;
        $anchorText = null;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                if ($icon !== null) {
                    $anchorText = self::trackAnchor($parts, $i, $anchorText, $icon);
                }
                $depth = max(0, $depth + self::depthChange($part));
                continue;
            }
            if ($anchorText !== null) {
                $anchorText .= $part;
            }
            // a stray `<` means a tag the tokenizer could not close (unterminated quote or comment): leave that text alone
            if ($depth === 0 && $part !== '' && !str_contains($part, '<')) {
                $parts[$i] = preg_replace_callback(
                    self::COORDINATES,
                    static fn(array $m): string => $render((int) $m[1], (int) $m[2], (int) $m[3]),
                    $part,
                ) ?? $part;
            }
        }

        return implode('', $parts);
    }

    /**
     * Anchor bookkeeping for the tag at $parts[$i]: opening <a> starts collecting its text, </a> appends the icon when the
     * text was exactly one coordinate and no icon follows yet.
     *
     * @param list<string> $parts
     * @param callable(int, int, int): string $icon
     * @return string|null the text collected so far (null outside an anchor)
     */
    private static function trackAnchor(array &$parts, int $i, ?string $text, callable $icon): ?string
    {
        $tag = $parts[$i];
        if (preg_match('/^<a[\s>]/i', $tag)) {
            return '';
        }
        if ($text === null || !preg_match('/^<\/a\s*>/i', $tag)) {
            return $text;
        }
        if (
            preg_match('/^\s*\[(\d{1,2}):(\d{1,3}):(\d{1,3})\]\s*$/', $text, $m)
            && !str_contains($parts[$i + 2] ?? '', 'mail-coord-fleet')
        ) {
            $parts[$i] .= $icon((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return null;
    }

    /** +1 for an opening tag of a skipped element, -1 for its closing tag, else 0 */
    private static function depthChange(string $tag): int
    {
        if (!preg_match('/^<(\/?)([a-z][a-z0-9]*)/i', $tag, $m) || !in_array(strtolower($m[2]), self::SKIPPED, true)) {
            return 0;
        }
        if ($m[1] === '/') {
            return -1;
        }

        return str_ends_with($tag, '/>') ? 0 : 1;
    }
}
