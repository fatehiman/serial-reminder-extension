<?php
declare(strict_types=1);

namespace SR;

/**
 * Provider scripts are plain .json files in server/providers/.
 * Adding support for a new site = drop one more file there. No code change,
 * no new extension release: the extension downloads them from /api/providers.
 */
final class Providers
{
    public static function dir(): string
    {
        return APP_ROOT . '/providers';
    }

    /** @return array<string,array> keyed by provider name */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                error_log("serial-reminder: provider file is not valid JSON: $file");
                continue;
            }
            $name = (string) ($data['name'] ?? pathinfo($file, PATHINFO_FILENAME));
            $data['name']     = $name;
            $data['version']  = (int) ($data['version'] ?? 1);
            $data['hash']     = substr(hash('sha256', $raw), 0, 16);
            $data['enabled']  = (bool) ($data['enabled'] ?? true);
            $out[$name] = $data;
        }
        ksort($out);
        return $out;
    }

    /** Only the ones the extension should actually run. */
    public static function enabled(): array
    {
        return array_filter(self::all(), static fn (array $p): bool => $p['enabled']);
    }

    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /** One short string that changes whenever any provider file changes. */
    public static function bundleHash(): string
    {
        $parts = [];
        foreach (self::all() as $name => $p) {
            $parts[] = $name . ':' . $p['version'] . ':' . $p['hash'];
        }
        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }
}
