<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * Typed access to the request input (POST over GET) for page controllers: `$this->input()->int('id')`.
 *
 * The values (casts, HTML encoding, CRLF and NUL handling, ASCII-only unless multibyte) are those of the former
 * `_GP()` function: the type of the default decides the conversion (typed()). The typed accessors add a static return type, so
 * no `(int)` / `(string)` casts are needed at the call site.
 * Strings stay HTML-encoded; templates rely on that, so never encode them again.
 */
final class RequestInput
{
    public function __construct(private readonly Singleton $state) {}

    /** whether the request carries a (non-null) value of that name */
    public function has(string $name): bool
    {
        return isset($this->state->requestInput[$name]);
    }

    /**
     * raw POST data of the request
     *
     * @return array<mixed>
     */
    public function post(): array
    {
        return $this->state->requestPost;
    }

    /** integer value; an array where a number is expected counts as 1 (non-empty) or 0, as typed($name, 0) */
    public function int(string $name, int $default = 0): int
    {
        return $this->typed($name, $default);
    }

    /** float value, same as typed($name, 0.0) */
    public function float(string $name, float $default = 0.0): float
    {
        return $this->typed($name, $default);
    }

    /**
     * Large numbers as float even where the default is an integer (resources, fleet amounts), same as
     * typed($name, 0, false, true).
     */
    public function bigNumber(string $name): float
    {
        return $this->typed($name, 0, false, true);
    }

    /**
     * Text: trimmed, line breaks normalised to "\n", NUL removed, HTML special characters encoded, ASCII only unless
     * $multibyte (invalid UTF-8 becomes ''). An array where text is expected counts as missing. Same as
     * typed($name, $default, $multibyte).
     */
    public function string(string $name, string $default = '', bool $multibyte = false): string
    {
        return $this->typed($name, $default, $multibyte);
    }

    /** exactly as sent (no encoding, trimming or charset change; '' when missing or an array): passwords and tokens */
    public function rawString(string $name): string
    {
        $value = $this->state->requestInput[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Flag: true for 1, true, on and yes (any case), false for every other sent value (0, off, '', arrays, ...);
     * $default when the parameter is missing.
     */
    public function bool(string $name, bool $default = false): bool
    {
        if (!$this->has($name)) {
            return $default;
        }

        return in_array(strtolower($this->typed($name, '')), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Integers of an array parameter (`id[]=1&id[]=2`), re-indexed from 0; an element that is itself an array is dropped,
     * and a missing parameter or a scalar where an array is expected gives [] (typed($name, [0]) would give its default
     * [0] there). Elements are cast like typed($name, [0]) does.
     *
     * @return list<int>
     */
    public function intList(string $name): array
    {
        if (!is_array($this->state->requestInput[$name] ?? null)) {
            return [];
        }
        $list = [];
        foreach ($this->typed($name, [0]) as $value) {
            if (!is_array($value)) {
                $list[] = (int) $value;
            }
        }

        return $list;
    }

    /**
     * Integers of an array parameter with their keys (`ship[202]=5`), for lists keyed by element or queue id; an element that is
     * itself an array is dropped, a missing parameter or a scalar where an array is expected gives []. Elements are cast like
     * typed($name, [0]) does.
     *
     * @return array<int|string, int>
     */
    public function intMap(string $name): array
    {
        if (!is_array($this->state->requestInput[$name] ?? null)) {
            return [];
        }
        $map = [];
        foreach ($this->typed($name, [0]) as $key => $value) {
            if (!is_array($value)) {
                $map[$key] = (int) $value;
            }
        }

        return $map;
    }

    /**
     * Amounts of an array parameter with their keys (`fmenge[202]=1e6`), as floats like bigNumber(): for orders keyed by element id,
     * where the values may exceed the integer range; text that is not a number counts as 0. An element that is itself an array
     * is dropped, a missing parameter or a scalar where an array is expected gives [].
     *
     * @return array<int|string, float>
     */
    public function bigNumberMap(string $name): array
    {
        if (!is_array($this->state->requestInput[$name] ?? null)) {
            return [];
        }
        $map = [];
        foreach ($this->typed($name, [''], false) as $key => $value) {
            if (!is_array($value)) {
                $map[$key] = is_numeric($value) ? (float) $value : 0.0;
            }
        }

        return $map;
    }

    /**
     * Texts of an array parameter, encoded like string(), re-indexed from 0; nested arrays are dropped, a missing
     * parameter or a scalar where an array is expected gives [].
     *
     * @return list<string>
     */
    public function stringList(string $name, bool $multibyte = false): array
    {
        if (!is_array($this->state->requestInput[$name] ?? null)) {
            return [];
        }
        $list = [];
        foreach ($this->typed($name, [''], $multibyte) as $value) {
            if (!is_array($value)) {
                $list[] = (string) $value;
            }
        }

        return $list;
    }

    /**
     * Texts of an array parameter with their keys (`newrank[rankName]=x`), encoded like string(); an element that is itself
     * an array is dropped, a missing parameter or a scalar where an array is expected gives [].
     *
     * @return array<int|string, string>
     */
    public function stringMap(string $name, bool $multibyte = false): array
    {
        if (!is_array($this->state->requestInput[$name] ?? null)) {
            return [];
        }
        $map = [];
        foreach ($this->typed($name, [''], $multibyte) as $key => $value) {
            if (!is_array($value)) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }

    /**
     * An array parameter of any depth (`rank[3][rankName]=x`), every text encoded like string(); a missing parameter or a
     * scalar where an array is expected gives [].
     *
     * @return array<int|string, mixed>
     */
    public function nested(string $name, bool $multibyte = false): array
    {
        return is_array($this->state->requestInput[$name] ?? null) ? $this->typed($name, [''], $multibyte) : [];
    }

    /**
     * A (nested) array parameter (`battleinput[0][1][202]=5`) with its keys, every text encoded like string(); a missing
     * parameter or a scalar where an array is expected gives [].
     *
     * @return array<int|string, mixed>
     */
    public function stringTree(string $name, bool $multibyte = false): array
    {
        return is_array($this->state->requestInput[$name] ?? null) ? $this->typed($name, [], $multibyte) : [];
    }

    /**
     * Reads one request value (POST overrides GET) typed and sanitised by the type of $default: float (or $highnum)
     * -> float, int -> int, string -> trimmed text with line breaks normalised, NUL removed and HTML special
     * characters encoded (ASCII only unless $multibyte; invalid UTF-8 becomes ''), array -> the same per element
     * (integers when the default array starts with 0). Missing or mistyped input returns $default.
     * Because the value is already HTML-encoded, templates must not encode it again.
     *
     * @param mixed $default value returned when the parameter is absent; its type selects the conversion.
     * @param bool $multibyte allow UTF-8 characters in strings.
     * @param bool $highnum read as float even for integer defaults (large numbers).
     */
    public function typed(string $name, mixed $default, bool $multibyte = false, bool $highnum = false): mixed
    {
        $input = $this->state->requestInput;
        if (!isset($input[$name])) {
            return $default;
        }

        if (is_float($default) || $highnum) {
            return (float) $input[$name];
        }

        if (is_int($default)) {
            return (int) $input[$name];
        }

        if (is_string($default)) {
            // an array (`?x[]=1`) where a string is expected counts as missing
            if (!is_scalar($input[$name])) {
                return $default;
            }

            return $this->quote((string) $input[$name], $multibyte);
        }

        if (is_array($default) && is_array($input[$name])) {
            return $this->quoteArray($input[$name], $multibyte, !empty($default) && $default[0] === 0);
        }

        return $default;
    }

    /**
     * @param array<mixed> $var
     * @return array<mixed>
     */
    private function quoteArray(array $var, bool $multibyte, bool $onlyNumbers = false): array
    {
        $data = [];
        foreach ($var as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->quoteArray($value, $multibyte);
            } elseif ($onlyNumbers) {
                $data[$key] = (int) $value;
            } else {
                $data[$key] = $this->quote((string) $value, $multibyte);
            }
        }

        return $data;
    }

    private function quote(string $var, bool $multibyte): string
    {
        $var = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $var);
        $var = htmlspecialchars($var, ENT_QUOTES, 'UTF-8');
        $var = trim($var);

        if ($multibyte) {
            if (!preg_match('/^./u', $var)) {
                $var = '';
            }
        } else {
            $var = preg_replace('/[\x80-\xFF]/', '?', $var); // no multibyte, allow only ASCII (0-127)
        }

        return $var;
    }
}
