<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $pdo = new PDO('sqlite:' . Config::$dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            self::migrate($pdo);
            self::$instance = $pdo;
        }

        return self::$instance;
    }

    private static function migrate(PDO $pdo): void
    {
        $migrationsDir = Config::$rootDir . '/migrations';
        $files = glob($migrationsDir . '/*.sql');
        sort($files);

        $pdo->exec('CREATE TABLE IF NOT EXISTS _migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT (datetime(\'now\')))');
        $applied = $pdo->query('SELECT name FROM _migrations')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO _migrations (name) VALUES (:name)');
            $stmt->execute(['name' => $name]);
        }
    }
}
