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

        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );

        $appliedRows = self::$pdo->query('SELECT version FROM schema_migrations');
        $appliedVersions = [];
        if ($appliedRows !== false) {
            foreach ($appliedRows->fetchAll() as $row) {
                $appliedVersions[(string) $row['version']] = true;
            }
        }

        foreach ($migrationPaths as $migrationPath) {
            $version = basename($migrationPath);
            if (isset($appliedVersions[$version])) {
                continue;
            }

            $sql = file_get_contents($migrationPath);

            if ($sql === false) {
                throw new RuntimeException(sprintf('Migration file not found: %s', $migrationPath));
            }

            self::$pdo->beginTransaction();
            try {
                self::$pdo->exec($sql);
                $statement = self::$pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
                );
                $statement->execute([
                    'version' => $version,
                    'applied_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))
                        ->format('Y-m-d H:i:s'),
                ]);
                self::$pdo->commit();
            } catch (Throwable $exception) {
                if (self::$pdo->inTransaction()) {
                    self::$pdo->rollBack();
                }
                throw $exception;
            }
        }
    }

    /**
     * weight_entries が空のとき、直近7日分のサンプル体重データを投入する。
     */
    private static function seedIfEmpty(): void
    {
        if (self::hasSeedCompleted()) {
            return;
        }

        self::seedWeightIfEmpty();
        self::seedActivityIfEmpty();
        self::markSeedCompleted();
    }

    private static function hasSeedCompleted(): bool
    {
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_metadata (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );

        $statement = self::$pdo->prepare(
            'SELECT value FROM app_metadata WHERE key = :key LIMIT 1'
        );
        $statement->execute(['key' => 'seed_completed']);
        $row = $statement->fetch();

        return $row !== false && (string) ($row['value'] ?? '') === '1';
    }

    private static function markSeedCompleted(): void
    {
        $statement = self::$pdo->prepare(
            'INSERT INTO app_metadata (key, value)
             VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $statement->execute([
            'key' => 'seed_completed',
            'value' => '1',
        ]);
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
            'INSERT INTO exercise_entries (
                recorded_on, exercise_name, amount, unit, minutes, mets, source, confidence, is_estimated, estimate_note,
                weight_kg, weight_source, burned_calories_kcal, created_at, updated_at
             )
             VALUES (
                :recorded_on, :exercise_name, :amount, :unit, :minutes, :mets, :source, :confidence, :is_estimated, :estimate_note,
                :weight_kg, :weight_source, :burned_calories_kcal, :created_at, :updated_at
             )'
        );

        $seedExercises = [
            ['name' => 'スクワット', 'amount' => 30, 'unit' => 'rep', 'minutes' => 4, 'mets' => 5.0, 'burned' => 21],
            ['name' => '腹筋', 'amount' => 40, 'unit' => 'rep', 'minutes' => 5, 'mets' => 3.8, 'burned' => 20],
            ['name' => 'ウォーキング', 'amount' => 30, 'unit' => 'min', 'minutes' => 30, 'mets' => 3.5, 'burned' => 110],
        ];

        foreach ($seedExercises as $exercise) {
            $exerciseStatement->execute([
                'recorded_on' => $today,
                'exercise_name' => $exercise['name'],
                'amount' => $exercise['amount'],
                'unit' => $exercise['unit'],
                'minutes' => $exercise['minutes'],
                'mets' => $exercise['mets'],
                'source' => 'local_db',
                'confidence' => 'high',
                'is_estimated' => 0,
                'estimate_note' => 'シードデータ',
                'weight_kg' => 62.4,
                'weight_source' => 'reference',
                'burned_calories_kcal' => $exercise['burned'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
