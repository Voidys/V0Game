<?php

namespace App\Game\Core;

use App\Game\Enum\AuthLevel;

/**
 * View helpers of the game: number/time formatting, rights and module checks, the message page
 * (formerly globals in functions.php, which remain as wrappers). Twig reaches them through
 * App\Twig\TemplateExtension (container service), PHP code through GameUtilities::viewHelpers().
 */
class ViewHelpers
{
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Prepares a date() format: replaces the D (weekday) and M (month) letters by the language's names (escaped), so date() prints localized names.
     * @param int $time timestamp used to pick the weekday and month.
     */
    public function localeDateFormat($format, $time, $LNG = null)
    {
        // Workaround for locale Names.

        if (!isset($LNG)) {
            $LNG = $this->state->LNG;
        }

        $weekDay = date('w', $time);
        $months = date('n', $time) - 1;

        $format = str_replace(['D', 'M'], ['$D$', '$M$'], $format);
        $format = str_replace('$D$', addcslashes($LNG['week_day'][$weekDay], 'A..z'), $format);
        $format = str_replace('$M$', addcslashes($LNG['months'][$months], 'A..z'), $format);

        return $format;
    }

    /**
     * Ends the request with the standalone message page (admin area, installer), see
     * App\View\GameView::messagePage(). Plain text is escaped, pass Twig\Markup for HTML.
     */
    public function message(string|\Twig\Markup $mes, string|false $dest = '', int $time = 3, bool $topnav = false): never
    {
        throw EndOfRequest::withResponse((new \App\View\GameView($this->db, $this->configs, $this->state))->messagePage($mes, $dest, $time, !$topnav));
    }

    /**
     * Duration as "[Nd ]HHh MMm SSs" with the language's unit labels.
     * @param int|float $seconds duration in seconds.
     */
    public function prettyTime($seconds)
    {
        $LNG = $this->state->LNG;
        $day = $seconds / 86400;
        $day = floor($day);
        $hour = $seconds / 3600;
        $hour = floor($hour);
        $hour = $hour % 24;
        $minute = $seconds / 60;
        $minute = floor($minute);
        $minute = $minute % 60;
        $second = floor($seconds);
        $second = $seconds % 60;

        $time = '';

        if ($day > 0) {
            $time .= sprintf('%d%s ', $day, $LNG['short_day']);
        }

        return $time . sprintf(
            '%02d%s %02d%s %02d%s',
            $hour,
            $LNG['short_hour'],
            $minute,
            $LNG['short_minute'],
            $second,
            $LNG['short_second'],
        );
    }

    /**
     * Number with thousands separators in the player's notation (setting decimal_separator: 0 = 1.234,56, 1 = 1,234.56); non-numeric input is returned unchanged.
     * @param int $dec decimals.
     */
    public function prettyNumber($n, $dec = 0)
    {
        if (is_numeric($n)) {
            $USER = $this->state->USER;
            if ($USER['decimal_separator'] == 0) {
                return number_format($n, $dec, ',', '.');
            } else {
                return number_format($n, $dec, '.', ',');
            }
        } else {
            return $n;
        }
    }

    /**
     * Compact number (÷1000 steps with the language's suffixes from NumberAbbreviation, e.g. 1.2 Mio); a decimal is
     * shown for non-integer values below 100 when $decimal is not given.
     */
    public function shortlyNumber($number, $decimal = null, bool $significant = false)
    {
        $negate = $number < 0 ? -1 : 1;
        $number = abs($number);

        $USER = $this->state->USER;
        $userLang = $USER['lang'] ?? DEFAULT_LANG;
        $unit = (new NumberAbbreviation())->suffixesForLang($userLang);
        $maxKey = count($unit) - 1;
        $key = 0;

        // All profiles use ÷1000 steps; only suffix labels differ by language profile.
        while ($number >= 1000 && $key < $maxKey) {
            ++$key;
            $number = $number / 1000;
        }

        if ($key > $maxKey) {
            $key = $maxKey;
        }

        if ($significant && $key !== 0) {
            // three significant digits: 8,98 Mrd / 89,8 Mrd / 898 Mrd
            $decimal = $number < 10 ? 2 : ($number < 100 ? 1 : 0);
        }

        if (!is_numeric($decimal)) {
            $decimal = ((int) (((int) $number != $number) && $key != 0 && $number != 0 && $number < 100));
        }
        return $this->prettyNumber($negate * $number, $decimal) . ($key !== 0 ? '&nbsp;' . $unit[$key] : '');
    }

    /**
     * Full number below 1e9, from 1e9 on the value in millions (floor) with the language's million suffix, e.g.
     * 8.980 Mio (de) / 8,980 M (en). Top bar resource values.
     */
    public function millionsNumber($number): string
    {
        if (abs($number) < 1000000000) {
            return $this->prettyNumber($number);
        }
        $USER = $this->state->USER;
        $unit = (new NumberAbbreviation())->suffixesForLang($USER['lang'] ?? DEFAULT_LANG);

        return $this->prettyNumber(floor($number / 1000000)) . '&nbsp;' . ($unit[2] ?? 'M');
    }

    /**
     * Cost tile number: the full number below 10,000, from there on the largest language unit with value/unit >= 1,
     * floored to at most 6 letters + digits in total (decimals = clamp(6 - suffix length - integer digits, 0, 2)),
     * trailing zeros trimmed, e.g. 12,8K / 1,63 Mio / 25 Mio / 8 Mrd.
     */
    public function compactNumber($number): string
    {
        $number = (float) $number;
        if (abs($number) < 10000) {
            return $this->prettyNumber($number);
        }
        $negate = $number < 0 ? '-' : '';
        $number = abs($number);
        $USER = $this->state->USER;
        $unit = (new NumberAbbreviation())->suffixesForLang($USER['lang'] ?? DEFAULT_LANG);
        $key = 0;
        while ($number >= 1000 && $key < count($unit) - 1) {
            ++$key;
            $number /= 1000;
        }
        $suffix = (string) $unit[$key];
        $intDigits = strlen((string) (int) floor($number));
        $decimals = max(0, min(2, 6 - strlen($suffix) - $intDigits));
        $factor = 10 ** $decimals;
        $text = $this->prettyNumber(floor($number * $factor + 1e-9) / $factor, $decimals);
        if ($decimals > 0) {
            $text = preg_replace('/[.,]?0+$/', '', $text);
        }

        return $negate . $text . ($suffix === '' ? '' : (strlen($suffix) > 1 ? '&nbsp;' : '') . $suffix);
    }

    /**
     * True if the game module (MODULE_* id) is enabled in the universe (0 = current). Unknown module ids are logged and count as enabled.
     */
    public function isModuleAvailable($ID, $universe = 0)
    {
        $config = $this->configs->get($universe);
        $modules = $config->moduls;

        if (!isset($modules[$ID])) {
            $e = new \Exception();
            error_log('Unknown module ID: ' . $ID);
            error_log('\n' . $e->getTraceAsString());
        }

        return isset($modules[$ID]) ? $modules[$ID] == 1 : true;
    }

    /**
     * True if the current user may use the admin function $side: admins always, other staff when `rights[$side]` is 1.
     */
    public function allowedTo($side)
    {

        $USER = $this->state->USER;
        return ($USER['authlevel'] == AuthLevel::Admin->value || (isset($USER['rights']) && isset($USER['rights'][$side]) && $USER['rights'][$side] == 1));
    }

    /** date() with localized day/month names and optional target time zone (formerly _date()) */
    public function formatDate($format, $time = null, $toTimeZone = null, $LNG = null)
    {
        if (!isset($time)) {
            $time = $this->state->runtime->timestamp;
        }

        if (isset($toTimeZone)) {
            $date = new \DateTime();
            if (method_exists($date, 'setTimestamp')) {    // PHP > 5.3
                $date->setTimestamp($time);
            } else {
                // PHP < 5.3
                $tempDate = getdate((int) $time);
                $date->setDate($tempDate['year'], $tempDate['mon'], $tempDate['mday']);
                $date->setTime($tempDate['hours'], $tempDate['minutes'], $tempDate['seconds']);
            }

            $time -= $date->getOffset();
            try {
                $date->setTimezone(new \DateTimeZone($toTimeZone));
            } catch (\Exception $e) {
            }
            $time += $date->getOffset();
        }

        $format = $this->localeDateFormat($format, $time, $LNG);
        return date($format, $time);
    }
}
