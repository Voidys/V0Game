<?php

declare(strict_types=1);

namespace App\Game\Chat;

/**
 * Text filter of the global chat channel (pure, no database): collapses runs of identical characters, masks blocked
 * words with `***`, and rejects links, shouting and repeated messages. The alliance channel is not filtered.
 */
final class ChatFilter
{
    public const ERR_LINK = 'link';
    public const ERR_CAPS = 'caps';
    public const ERR_DUPLICATE = 'duplicate';

    public const MASK = '***';
    /** longest run of one character that stays (longer runs are cut to this) */
    public const MAX_RUN = 4;
    /** letters needed before the capitals rule applies, and the share of capitals that is too much */
    public const CAPS_MIN_LETTERS = 10;
    public const CAPS_RATIO = 0.7;

    /** @var list<string> compiled word patterns */
    private array $patterns = [];

    /** @var list<string> domains that may be written out (lower case) */
    private array $allowedDomains = [];

    /**
     * @param string $words one blocked word or pattern per line (`*` stands for any letters, matching is case-insensitive, whole words only)
     * @param list<string> $allowedDomains domains that are not treated as links (e.g. the game's own host; subdomains are allowed too)
     */
    public function __construct(string $words = '', private readonly bool $blockLinks = true, array $allowedDomains = [])
    {
        foreach ($allowedDomains as $domain) {
            $domain = mb_strtolower(trim((string) preg_replace(['~^(?:https?://)?(?:www\.)?~i', '~/.*$~'], '', trim($domain))));
            if ($domain !== '') {
                $this->allowedDomains[] = $domain;
            }
        }
        foreach (preg_split('/\R/u', $words) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $quoted = str_replace('\*', '[\p{L}\p{N}_]*', preg_quote($line, '/'));
            $this->patterns[] = '/(?<![\p{L}\p{N}_])' . $quoted . '(?![\p{L}\p{N}_])/iu';
        }
    }

    /**
     * Filters a cleaned message.
     *
     * @param list<string> $recent the sender's last messages in the channel within the duplicate window (newest first)
     * @return array{text: string}|array{error: string} the text to store (collapsed and masked), or the error code
     */
    public function apply(string $text, array $recent = []): array
    {
        $text = (string) preg_replace('/(.)\1{' . self::MAX_RUN . ',}/su', str_repeat('$1', self::MAX_RUN), $text);
        foreach ($this->patterns as $pattern) {
            $text = (string) preg_replace($pattern, self::MASK, $text);
        }
        if ($this->blockLinks && $this->hasLink($text)) {
            return ['error' => self::ERR_LINK];
        }
        if ($this->shouting($text)) {
            return ['error' => self::ERR_CAPS];
        }
        $key = mb_strtolower($text);
        foreach ($recent as $old) {
            if (mb_strtolower($old) === $key) {
                return ['error' => self::ERR_DUPLICATE];
            }
        }

        return ['text' => $text];
    }

    /**
     * True when the text contains a web address: a scheme (also written hxxp), `www.`, an IPv4 address, or any
     * `label.label` whose last label is 2-24 letters. Disguised dots ([.], (.), {.}, "dot", fullwidth and ideographic dots) count as dots;
     * allowed domains are ignored. Short forms like "e.g." or "3.5" are no links.
     */
    public function hasLink(string $text): bool
    {
        $text = (string) preg_replace('/[\x{FF0E}\x{3002}\x{FF61}\x{2024}\x{FE52}]/u', '.', $text);
        $text = (string) preg_replace('/\s*[\[\(\{]\s*(?:\.|dot)\s*[\]\)\}]\s*|(?<=\S)\s+dot\s+(?=\S)/iu', '.', $text);
        $text = (string) preg_replace('/\bh(?:tt|xx)p(s?):\/\//iu', 'http$1://', $text);
        foreach ($this->allowedDomains as $domain) {
            $text = (string) preg_replace('~(?<![\p{L}\p{N}.-])(?:https?://)?(?:www\.)?(?:[\p{L}\p{N}-]+\.)*' . preg_quote($domain, '~') . '(?![\p{L}\p{N}-])(?:/\S*)?~iu', ' ', $text);
        }

        return preg_match('~https?://\S|(?<![\p{L}\p{N}.])www\.\S|(?<!\d)(?:\d{1,3}\.){3}\d{1,3}(?!\d)|(?<![\p{L}\p{N}.-])[\p{L}\p{N}][\p{L}\p{N}-]*(?:\.[\p{L}\p{N}-]+)*\.\p{L}{2,24}(?![\p{L}\p{N}-])~iu', $text) === 1;
    }

    /** True when the text has more than CAPS_MIN_LETTERS letters and more than CAPS_RATIO of them are capitals. */
    private function shouting(string $text): bool
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters <= self::CAPS_MIN_LETTERS) {
            return false;
        }

        return preg_match_all('/\p{Lu}/u', $text) / $letters > self::CAPS_RATIO;
    }
}
