<?php
declare(strict_types=1);

namespace SR;

final class Config
{
    private static array $data = [];

    public static function set(array $data): void
    {
        self::$data = $data;
    }

    /** Dot-path lookup: Config::get('http.timeout', 20) */
    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function all(): array
    {
        return self::$data;
    }
}
