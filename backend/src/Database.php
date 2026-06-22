<?php

declare(strict_types=1);

/**
 * SQLite データベースへの接続と初期化を担当するクラス。
 * 初回接続時にマイグレーション（テーブル作成）とサンプルデータ投入を行う。
 */
final class Database
{
    /** @var PDO|null 接続を使い回すためのシングルトン */
    private static ?PDO $pdo = null;

    /**
     * SQLite への PDO 接続を返す。
     * 初回のみマイグレーションとシードを実行する。
     */
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

    /**
     * migrations/ 配下の SQL を実行してテーブルを作成する。
     */
    private static function migrate(): void
    {
        $migrationPaths = glob(dirname(__DIR__) . '/migrations/*.sql');

        if ($migrationPaths === false || $migrationPaths === []) {
            throw new RuntimeException('Migration files not found.');
        }

        sort($migrationPaths, SORT_STRING);

        foreach ($migrationPaths as $migrationPath) {
            $sql = file_get_contents($migrationPath);

            if ($sql === false) {
                throw new RuntimeException(sprintf('Migration file not found: %s', $migrationPath));
            }

            self::$pdo->exec($sql);
        }
    }

    /**
     * weight_entries が空のとき、直近7日分のサンプル体重データを投入する。
     */
    private static function seedIfEmpty(): void
    {
        self::seedWeightIfEmpty();
        self::seedActivityIfEmpty();
    }

    private static function seedWeightIfEmpty(): void
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

    private static function seedActivityIfEmpty(): void
    {
        $stepCount = (int) self::$pdo->query('SELECT COUNT(*) FROM step_entries')->fetchColumn();
        $exerciseCount = (int) self::$pdo->query('SELECT COUNT(*) FROM exercise_entries')->fetchColumn();

        if ($stepCount > 0 || $exerciseCount > 0) {
            return;
        }

        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

        $stepStatement = self::$pdo->prepare(
            'INSERT INTO step_entries (recorded_on, step_count, burned_calories_kcal, created_at, updated_at)
             VALUES (:recorded_on, :step_count, :burned_calories_kcal, :created_at, :updated_at)'
        );
        $stepStatement->execute([
            'recorded_on' => $today,
            'step_count' => 5842,
            'burned_calories_kcal' => 231,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exerciseStatement = self::$pdo->prepare(
            'INSERT INTO exercise_entries (recorded_on, exercise_name, amount, unit, burned_calories_kcal, created_at, updated_at)
             VALUES (:recorded_on, :exercise_name, :amount, :unit, :burned_calories_kcal, :created_at, :updated_at)'
        );

        $seedExercises = [
            ['name' => 'スクワット', 'amount' => 30, 'unit' => 'rep', 'burned' => 60],
            ['name' => '腹筋', 'amount' => 40, 'unit' => 'rep', 'burned' => 60],
            ['name' => 'ウォーキング', 'amount' => 30, 'unit' => 'min', 'burned' => 90],
        ];

        foreach ($seedExercises as $exercise) {
            $exerciseStatement->execute([
                'recorded_on' => $today,
                'exercise_name' => $exercise['name'],
                'amount' => $exercise['amount'],
                'unit' => $exercise['unit'],
                'burned_calories_kcal' => $exercise['burned'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
