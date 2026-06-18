<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = getenv('DATABASE_PATH') ?: dirname(__DIR__) . '/data/diet.db';
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create database directory.');
        }

        self::$pdo = new PDO('sqlite:' . $path);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        self::migrate();
        self::seedIfEmpty();

        return self::$pdo;
    }

    private static function migrate(): void
    {
        $migrationPath = dirname(__DIR__) . '/migrations/001_create_weight_entries.sql';
        $sql = file_get_contents($migrationPath);

        if ($sql === false) {
            throw new RuntimeException('Migration file not found.');
        }

        self::$pdo->exec($sql);
    }

    private static function seedIfEmpty(): void
    {
        $count = (int) self::$pdo->query('SELECT COUNT(*) FROM weight_entries')->fetchColumn();

        if ($count > 0) {
            return;
        }

        $timezone = new DateTimeZone('Asia/Tokyo');
        $today = new DateTimeImmutable('now', $timezone);
        $weights = [63.0, 62.9, 62.8, 62.7, 62.65, 62.6, 62.4];

        $statement = self::$pdo->prepare(
            'INSERT INTO weight_entries (recorded_on, weight_kg, created_at, updated_at)
             VALUES (:recorded_on, :weight_kg, :created_at, :updated_at)'
        );

        foreach ($weights as $index => $weight) {
            $daysAgo = count($weights) - 1 - $index;
            $date = $today->modify(sprintf('-%d days', $daysAgo));
            $timestamp = $date->format('Y-m-d H:i:s');

            $statement->execute([
                'recorded_on' => $date->format('Y-m-d'),
                'weight_kg' => $weight,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }
}
