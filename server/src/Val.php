<?php
declare(strict_types=1);

namespace SR;

/**
 * Small helpers shared by the provider engine: dot-paths into decoded JSON,
 * "{placeholder}" templates, and number cleaning (Persian/Arabic digits).
 */
final class Val
{
    private const DIGIT_MAP = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** Turn Persian/Arabic digits into ASCII ones. */
    public static function digits(?string $s): string
    {
        return strtr((string) $s, self::DIGIT_MAP);
    }

    /** First integer found in a value, after digit folding. Null when there is none. */
    public static function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) $v;
        }
        if (!is_string($v)) {
            return null;
        }
        return preg_match('/-?\d+/', self::digits($v), $m) ? (int) $m[0] : null;
    }

    /**
     * Read "a.b.c" out of a nested array. "a.*.c" collects every match into a list.
     * Returns $default when the path does not exist.
     */
    public static function path(mixed $data, string $path, mixed $default = null): mixed
    {
        if ($path === '' || $path === '.') {
            return $data;
        }
        $node = $data;
        $parts = explode('.', $path);
        foreach ($parts as $i => $part) {
            if ($part === '*') {
                if (!is_array($node)) {
                    return $default;
                }
                $rest = implode('.', array_slice($parts, $i + 1));
                $out  = [];
                foreach ($node as $child) {
                    $v = $rest === '' ? $child : self::path($child, $rest, null);
                    if ($v !== null) {
                        $out[] = $v;
                    }
                }
                return $out;
            }
            if (is_array($node) && array_key_exists($part, $node)) {
                $node = $node[$part];
                continue;
            }
            return $default;
        }
        return $node;
    }

    /**
     * Fill "{name}" and "{a.b}" placeholders from $vars. Values are URL-safe when
     * the template looks like a URL; otherwise inserted as-is.
     */
    public static function template(string $tpl, array $vars): string
    {
        $isUrl = (bool) preg_match('~^[a-z]+://~i', $tpl);
        return (string) preg_replace_callback(
            '/\{([A-Za-z0-9_.\-]+)\}/',
            static function (array $m) use ($vars, $isUrl): string {
                $v = self::path($vars, $m[1]);
                if ($v === null || is_array($v)) {
                    return '';
                }
                $s = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
                return $isUrl ? rawurlencode($s) : $s;
            },
            $tpl
        );
    }

    /**
     * Turn words into values: {"season one": 1, "season two": 2}.
     * Tries an exact match first, then looks for a key inside the text, longest
     * key first so "chapter twelve" never matches a "chapter two" key.
     * Persian and Arabic digits are folded before comparing.
     */
    public static function applyMap(string $value, array $map): mixed
    {
        $needle = trim(self::digits($value));

        foreach ($map as $key => $out) {
            if (trim(self::digits((string) $key)) === $needle) {
                return $out;
            }
        }

        $keys = array_keys($map);
        usort($keys, static fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        foreach ($keys as $key) {
            $k = trim(self::digits((string) $key));
            if ($k !== '' && mb_strpos($needle, $k) !== false) {
                return $map[$key];
            }
        }
        return null;
    }

    /** "01:05:39" or "56:27" or "3600" -> seconds. */
    public static function duration(string $v): ?int
    {
        $v = trim(self::digits($v));
        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $v, $m)) {
            return ((int) ($m[1] ?: 0)) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
        }
        return self::int($v);
    }

    /**
     * Minutes to seconds. Some catalogs give a plain episode length in minutes
     * (namava's "mediaDuration": 54), and everything here counts in seconds.
     */
    public static function minutes(mixed $v): ?int
    {
        $n = self::int($v);
        return $n === null ? null : $n * 60;
    }

    /**
     * A timestamp, as a Unix time. strtotime() understands most of what these
     * sites send, but not the "basic" ISO 8601 form namava uses
     * ("20260419T181800013Z"), so that one is unpacked by hand first.
     */
    public static function time(string $v): ?int
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})\d{0,3}Z?$/', $v, $m)) {
            $v = "$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]Z";
        }
        $ts = strtotime($v);
        return $ts === false ? null : $ts;
    }

    /**
     * Show an Iranian mobile number the way people write it: 989133169571 and
     * +98 913 316 9571 both become 09133169571. Anything else is left alone.
     */
    public static function phone(string $v): string
    {
        $d = preg_replace('/\D+/', '', self::digits($v)) ?? '';
        if ($d === '') {
            return trim($v);
        }
        if (str_starts_with($d, '98') && strlen($d) === 12) {
            return '0' . substr($d, 2);
        }
        if (strlen($d) === 10 && $d[0] === '9') {
            return '0' . $d;
        }
        return $d;
    }

    /**
     * First row of $rows where every $match key equals its (templated) value.
     * Lets a field say "the season whose id is the one this episode belongs to".
     */
    public static function findIn(array $rows, array $match, array $vars): ?array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ok = true;
            foreach ($match as $path => $wanted) {
                $want = is_string($wanted) && str_contains($wanted, '{')
                    ? self::template($wanted, $vars)
                    : $wanted;
                if ((string) self::path($row, (string) $path) !== (string) $want) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Flatten a list of lists into one list. Stops at objects, so a list of
     * episode objects is left alone but seasons-of-episodes is unwrapped.
     */
    public static function flattenList(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && $row !== [] && array_is_list($row)) {
                foreach (self::flattenList($row) as $inner) {
                    $out[] = $inner;
                }
            } else {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Fill "{...}" placeholders everywhere inside a nested array (a POST body). */
    public static function templateDeep(mixed $data, array $vars): mixed
    {
        if (is_string($data)) {
            return self::template($data, $vars);
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[is_string($k) ? self::template($k, $vars) : $k] = self::templateDeep($v, $vars);
            }
            return $out;
        }
        return $data;
    }

    /** True when a template still has an unresolved placeholder. */
    public static function hasHoles(string $s): bool
    {
        return str_contains($s, '{') && (bool) preg_match('/\{[A-Za-z0-9_.\-]+\}/', $s);
    }

    /**
     * A field rule is either a plain dot-path ("attributes.uid"), a template
     * ("https://x/{attributes.uid}"), or an object:
     *   {"path": "...", "as": "int|string|bool|float", "default": ...}
     *
     * "extract" runs a regular expression over the value first and keeps capture
     * group 1, for sites that pack two numbers into one caption.
     */
    public static function field(mixed $rule, array $item, array $extraVars = []): mixed
    {
        $as      = null;
        $map     = null;
        $find    = null;
        $pick    = null;
        $extract = null;
        if (is_array($rule)) {
            $as      = $rule['as']      ?? null;
            $map     = $rule['map']     ?? null;
            $find    = $rule['find']    ?? null;
            $pick    = $rule['pick']    ?? null;
            $extract = $rule['extract'] ?? null;
            $default = $rule['default'] ?? null;
            $spec    = $rule['path']    ?? $rule['template'] ?? null;
            if ($spec === null) {
                return $default;
            }
            $rule = $spec;
        } else {
            $default = null;
        }
        if (!is_string($rule)) {
            return $default;
        }

        $vars  = $item + $extraVars;
        $value = str_contains($rule, '{')
            ? self::template($rule, $vars)
            : self::path($vars, $rule, $default);

        // "find the row in this list whose id matches, then take that field"
        if (is_array($find)) {
            $value = self::findIn(is_array($value) ? $value : [], $find, $vars);
            if ($value === null) {
                return $default;
            }
            if (is_string($pick)) {
                $value = self::path($value, $pick);
            }
        }

        if ($value === null || $value === '') {
            return $default;
        }

        // "extract": keep one group out of the text before anything else reads it.
        // Namava names an episode "فصل ۴ قسمت ۷" — two numbers in one string, so
        // "first integer found" would take the season for the episode number.
        if (is_string($extract) && $extract !== '' && is_scalar($value)) {
            $hit = preg_match('~' . str_replace('~', '\~', $extract) . '~u', (string) $value, $m);
            if ($hit !== 1) {
                return $default;
            }
            $value = $m[1] ?? $m[0];
        }

        if (is_array($map) && is_scalar($value)) {
            $mapped = self::applyMap((string) $value, $map);
            if ($mapped === null) {
                return $default;
            }
            $value = $mapped;
        }

        return match ($as) {
            'int'      => self::int($value),
            'float'    => is_numeric(self::digits((string) $value)) ? (float) self::digits((string) $value) : null,
            'bool'     => (bool) $value,
            'string'   => is_scalar($value) ? (string) $value : null,
            'duration' => self::duration((string) $value),
            'minutes'  => self::minutes($value),
            'phone'    => self::phone((string) $value),
            default    => $value,
        };
    }
}
