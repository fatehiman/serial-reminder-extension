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

    /** True when a template still has an unresolved placeholder. */
    public static function hasHoles(string $s): bool
    {
        return str_contains($s, '{') && (bool) preg_match('/\{[A-Za-z0-9_.\-]+\}/', $s);
    }

    /**
     * A field rule is either a plain dot-path ("attributes.uid"), a template
     * ("https://x/{attributes.uid}"), or an object:
     *   {"path": "...", "as": "int|string|bool|float", "default": ...}
     */
    public static function field(mixed $rule, array $item, array $extraVars = []): mixed
    {
        $as = null;
        if (is_array($rule)) {
            $as      = $rule['as']      ?? null;
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

        if ($value === null || $value === '') {
            return $default;
        }

        return match ($as) {
            'int'    => self::int($value),
            'float'  => is_numeric(self::digits((string) $value)) ? (float) self::digits((string) $value) : null,
            'bool'   => (bool) $value,
            'string' => is_scalar($value) ? (string) $value : null,
            default  => $value,
        };
    }
}
