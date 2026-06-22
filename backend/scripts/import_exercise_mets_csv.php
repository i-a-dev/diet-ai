<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php backend/scripts/import_exercise_mets_csv.php <csv_path>\n");
    exit(1);
}

$csvPath = $argv[1];
if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV file not found: {$csvPath}\n");
    exit(1);
}

$db = Database::connection();
$handle = fopen($csvPath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Failed to open CSV: {$csvPath}\n");
    exit(1);
}

$header = fgetcsv($handle);
if ($header === false) {
    fwrite(STDERR, "CSV is empty: {$csvPath}\n");
    fclose($handle);
    exit(1);
}

$headerIndex = [];
foreach ($header as $index => $name) {
    $headerIndex[trim((string) $name)] = $index;
}

foreach (['activity_code', 'exercise_name', 'mets', 'source', 'source_url'] as $required) {
    if (!array_key_exists($required, $headerIndex)) {
        fwrite(STDERR, "Missing required column: {$required}\n");
        fclose($handle);
        exit(1);
    }
}

$selectByCode = $db->prepare('SELECT id FROM exercise_mets WHERE activity_code = :activity_code LIMIT 1');
$selectByName = $db->prepare('SELECT id FROM exercise_mets WHERE lower(exercise_name) = lower(:exercise_name) LIMIT 1');
$insert = $db->prepare(
    'INSERT INTO exercise_mets (activity_code, exercise_name, mets, source, source_url, created_at, updated_at)
     VALUES (:activity_code, :exercise_name, :mets, :source, :source_url, :created_at, :updated_at)'
);
$update = $db->prepare(
    'UPDATE exercise_mets
     SET activity_code = :activity_code,
         exercise_name = :exercise_name,
         mets = :mets,
         source = :source,
         source_url = :source_url,
         updated_at = :updated_at
     WHERE id = :id'
);

$inserted = 0;
$updated = 0;
$skipped = 0;
$now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

$db->beginTransaction();
try {
    while (($row = fgetcsv($handle)) !== false) {
        $activityCode = trim((string) ($row[$headerIndex['activity_code']] ?? ''));
        $exerciseName = trim((string) ($row[$headerIndex['exercise_name']] ?? ''));
        $metsRaw = trim((string) ($row[$headerIndex['mets']] ?? ''));
        $source = trim((string) ($row[$headerIndex['source']] ?? ''));
        $sourceUrl = trim((string) ($row[$headerIndex['source_url']] ?? ''));

        if ($exerciseName === '' || $metsRaw === '' || !is_numeric($metsRaw)) {
            $skipped++;
            continue;
        }

        $mets = round((float) $metsRaw, 1);
        if ($mets <= 0 || $mets > 25) {
            $skipped++;
            continue;
        }

        $source = $source !== '' ? $source : 'manual';
        $sourceUrl = $sourceUrl !== '' ? $sourceUrl : null;
        $activityCode = $activityCode !== '' ? $activityCode : null;

        $existingId = null;
        if ($activityCode !== null) {
            $selectByCode->execute(['activity_code' => $activityCode]);
            $codeHit = $selectByCode->fetch();
            if ($codeHit !== false) {
                $existingId = (int) $codeHit['id'];
            }
        }

        if ($existingId === null) {
            $selectByName->execute(['exercise_name' => $exerciseName]);
            $nameHit = $selectByName->fetch();
            if ($nameHit !== false) {
                $existingId = (int) $nameHit['id'];
            }
        }

        if ($existingId === null) {
            $insert->execute([
                'activity_code' => $activityCode,
                'exercise_name' => $exerciseName,
                'mets' => $mets,
                'source' => $source,
                'source_url' => $sourceUrl,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inserted++;
            continue;
        }

        $update->execute([
            'id' => $existingId,
            'activity_code' => $activityCode,
            'exercise_name' => $exerciseName,
            'mets' => $mets,
            'source' => $source,
            'source_url' => $sourceUrl,
            'updated_at' => $now,
        ]);
        $updated++;
    }

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fclose($handle);
    throw $exception;
}

fclose($handle);

fwrite(STDOUT, "Import completed.\n");
fwrite(STDOUT, "Inserted: {$inserted}\n");
fwrite(STDOUT, "Updated: {$updated}\n");
fwrite(STDOUT, "Skipped: {$skipped}\n");
