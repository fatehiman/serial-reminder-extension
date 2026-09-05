<?php
declare(strict_types=1);

namespace SR;

/**
 * Which account is logged in on each site.
 *
 * Every platform signs you up by mobile number, and one person can hold several
 * numbers with the subscription on a different one for each site. The dashboard
 * shows the number next to the provider so you can see at a glance that you are
 * logged in with the right one.
 */
final class Accounts
{
    /** Called by the extension, only while something is really playing. */
    public static function record(int $userId, string $provider, array $in): array
    {
        $provider = trim($provider);
        $label    = Val::phone(trim((string) ($in['label'] ?? '')));

        if ($provider === '' || $label === '') {
            throw new \InvalidArgumentException('provider and label are required');
        }

        $name = self::str($in['name'] ?? null);
        $note = self::str($in['note'] ?? null);

        $existing = Db::one(
            'SELECT * FROM provider_accounts WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );

        if ($existing === null) {
            Db::q(
                'INSERT INTO provider_accounts (user_id, provider, label, name, note) VALUES (?, ?, ?, ?, ?)',
                [$userId, $provider, $label, $name, $note]
            );
        } else {
            Db::q(
                "UPDATE provider_accounts SET
                    label      = ?,
                    name       = COALESCE(?, name),
                    note       = COALESCE(?, note),
                    seen_at    = datetime('now'),
                    updated_at = CASE WHEN label = ? THEN updated_at ELSE datetime('now') END
                 WHERE id = ?",
                [$label, $name, $note, $label, (int) $existing['id']]
            );
        }

        return [
            'changed' => $existing !== null && $existing['label'] !== $label,
            'account' => self::forProvider($userId, $provider),
        ];
    }

    /** @return array<string,array> provider name => account */
    public static function forUser(int $userId): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM provider_accounts WHERE user_id = ?', [$userId]) as $row) {
            $out[(string) $row['provider']] = self::out($row);
        }
        return $out;
    }

    public static function forProvider(int $userId, string $provider): ?array
    {
        $row = Db::one(
            'SELECT * FROM provider_accounts WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );
        return $row === null ? null : self::out($row);
    }

    public static function forget(int $userId, string $provider): bool
    {
        return Db::q(
            'DELETE FROM provider_accounts WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        )->rowCount() > 0;
    }

    private static function out(array $row): array
    {
        return [
            'provider' => $row['provider'],
            'label'    => $row['label'],
            'name'     => $row['name'],
            'note'     => $row['note'],
            'seenAt'   => $row['seen_at'],
            'since'    => $row['updated_at'],
        ];
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
