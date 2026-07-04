<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public static string $rootDir;
    public static string $dataDir;
    public static string $booksDir;
    public static string $runsDir;
    public static string $dbPath;
    public static string $agentsTemplatesDir;

    public static function init(string $rootDir): void
    {
        self::$rootDir = $rootDir;
        Env::load($rootDir . '/.env');

        self::$dataDir = Env::get('DATA_DIR', $rootDir . '/data');
        self::$booksDir = self::$dataDir . '/books';
        self::$runsDir = self::$dataDir . '/.runs';
        self::$dbPath = self::$dataDir . '/app.db';
        self::$agentsTemplatesDir = $rootDir . '/agents-templates';

        foreach ([self::$dataDir, self::$booksDir, self::$runsDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    public static function appPasswordHash(): ?string
    {
        return Env::get('APP_PASSWORD_HASH');
    }

    public static function claudeOAuthToken(): ?string
    {
        return Env::get('CLAUDE_CODE_OAUTH_TOKEN');
    }

    public static function claudeBinary(): string
    {
        return Env::get('CLAUDE_BINARY', 'claude');
    }

    public static function runTimeoutSeconds(): int
    {
        return (int) Env::get('RUN_TIMEOUT_SECONDS', '600');
    }
}
