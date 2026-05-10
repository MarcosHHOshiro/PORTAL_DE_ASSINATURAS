<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Env;
use PDO;

final class Connection
{
    private static ?PDO $pdo = null;

    public static function getInstance(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $databasePath = Env::get('DATABASE_PATH', 'storage/database.sqlite');
        $absolutePath = self::resolvePath($databasePath);
        $directory = dirname($absolutePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        self::$pdo = new PDO('sqlite:' . $absolutePath);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo->exec('PRAGMA busy_timeout = 5000');
        self::$pdo->exec('PRAGMA temp_store = MEMORY');
        self::$pdo->exec('PRAGMA journal_mode = MEMORY');
        self::$pdo->exec('PRAGMA foreign_keys = ON');

        return self::$pdo;
    }

    private static function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return BASE_PATH . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
