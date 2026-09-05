<?php
declare(strict_types=1);

namespace SR;

use PDO;
use RuntimeException;

final class Db
{
    private static ?PDO $pdo = null;
    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = self::$config['db_path'] ?? throw new RuntimeException('db_path not configured');
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create database directory: $dir");
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return self::$pdo = $pdo;
    }

    /** Run every .sql file in migrations/ exactly once, in name order. */
    public static function migrate(): void
    {
        $pdo = self::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                        name       TEXT PRIMARY KEY,
                        applied_at TEXT NOT NULL DEFAULT (datetime(\'now\')))');

        $files = glob(APP_ROOT . '/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            $done = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
            $done->execute([$name]);
            if ($done->fetchColumn() !== false) {
                continue;
            }
            $pdo->exec((string) file_get_contents($file));
            $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
        }
    }

    public static function q(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function insertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
