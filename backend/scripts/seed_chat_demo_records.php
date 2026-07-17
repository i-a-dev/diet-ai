<?php

declare(strict_types=1);

/**
 * AIチャット確認用: 直近30日の食事・体重サンプルを user に投入する。
 * 使い方: php scripts/seed_chat_demo_records.php [user_id]
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/DailyNutritionSummaryRepository.php';

$userId = isset($argv[1]) ? (int) $argv[1] : 4;
if ($userId <= 0) {
    fwrite(STDERR, "user_id is required\n");
    exit(1);
}

$pdo = Database::connection();
$tz = new DateTimeZone('Asia/Tokyo');
$today = new DateTimeImmutable('today', $tz);

$foods = [
    ['朝食 トーストと卵', 'breakfast', 320, 12.0, 14.0, 30.0],
    ['朝食 ヨーグルトとバナナ', 'breakfast', 250, 10.0, 5.0, 40.0],
    ['朝食 オートミール', 'breakfast', 280, 9.0, 6.0, 45.0],
    ['朝食 納豆ごはん', 'breakfast', 380, 14.0, 8.0, 60.0],
    ['昼食 鶏むねサラダ', 'lunch', 420, 35.0, 12.0, 20.0],
    ['昼食 定食（焼き魚）', 'lunch', 650, 28.0, 18.0, 70.0],
    ['昼食 コンビニサンド', 'lunch', 480, 16.0, 18.0, 55.0],
    ['昼食 うどん', 'lunch', 520, 15.0, 8.0, 90.0],
    ['昼食 親子丼', 'lunch', 700, 30.0, 22.0, 85.0],
    ['夕食 生姜焼き定食', 'dinner', 720, 32.0, 25.0, 75.0],
    ['夕食 サラダチキンと野菜', 'dinner', 450, 40.0, 10.0, 25.0],
    ['夕食 パスタ（トマト）', 'dinner', 620, 20.0, 15.0, 90.0],
    ['夕食 魚と豆腐の煮物', 'dinner', 480, 30.0, 12.0, 35.0],
    ['夕食 カレーライス', 'dinner', 780, 22.0, 24.0, 100.0],
    ['間食 プロテインバー', 'snack', 180, 15.0, 6.0, 18.0],
    ['間食 りんご', 'snack', 90, 0.3, 0.2, 22.0],
    ['間食 ギリシャヨーグルト', 'snack', 120, 10.0, 3.0, 8.0],
    ['間食 ナッツ一小袋', 'snack', 200, 5.0, 18.0, 6.0],
];

$mealInsert = $pdo->prepare(
    'INSERT INTO meal_entries (
        user_id, recorded_on, meal_type, food_name, calories_kcal, calories_edited,
        calorie_source, confidence, amount, unit, protein_g, fat_g, carbs_g,
        created_at, updated_at
     ) VALUES (
        :user_id, :recorded_on, :meal_type, :food_name, :calories_kcal, 0,
        :calorie_source, :confidence, :amount, :unit, :protein_g, :fat_g, :carbs_g,
        :created_at, :updated_at
     )'
);

$weightUpsert = $pdo->prepare(
    'INSERT INTO weight_entries (user_id, recorded_on, weight_kg, created_at, updated_at)
     VALUES (:user_id, :recorded_on, :weight_kg, :created_at, :updated_at)
     ON DUPLICATE KEY UPDATE weight_kg = VALUES(weight_kg), updated_at = VALUES(updated_at)'
);

$now = $today->format('Y-m-d H:i:s');
$baseWeight = 60.8;
$mealDays = 0;
$mealRows = 0;
$weightDays = 0;
$touchedDates = [];

for ($offset = 29; $offset >= 0; $offset--) {
    $date = $today->modify(sprintf('-%d days', $offset));
    $dateStr = $date->format('Y-m-d');
    $dayIndex = 29 - $offset;

    // 体重: 約8割の日に記録。ゆるやかに減る + 小さな揺らぎ
    if ($offset === 0 || $offset === 1 || random_int(1, 100) <= 78) {
        $trend = -($dayIndex * 0.045);
        $noise = (random_int(-18, 18) / 100);
        $weekendBump = ((int) $date->format('N') >= 6) ? 0.15 : 0.0;
        $weight = round($baseWeight + $trend + $noise + $weekendBump, 1);
        $weight = max(55.0, min(63.0, $weight));
        $weightUpsert->execute([
            'user_id' => $userId,
            'recorded_on' => $dateStr,
            'weight_kg' => $weight,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $weightDays++;
    }

    // 食事: 今日は必ず。その他は約70%の日に2〜4食
    $forceMeals = $offset <= 1;
    if (!$forceMeals && random_int(1, 100) > 72) {
        continue;
    }

    $mealCount = $forceMeals ? random_int(3, 4) : random_int(2, 4);
    $typesPool = ['breakfast', 'lunch', 'dinner', 'snack'];
    shuffle($typesPool);
    $chosenTypes = array_slice($typesPool, 0, $mealCount);
    // 今日は朝昼夕を優先
    if ($offset === 0) {
        $chosenTypes = ['breakfast', 'lunch', 'dinner'];
        if (random_int(0, 1) === 1) {
            $chosenTypes[] = 'snack';
        }
    }

    foreach ($chosenTypes as $type) {
        $candidates = array_values(array_filter(
            $foods,
            static fn (array $f): bool => $f[1] === $type
        ));
        $food = $candidates[random_int(0, count($candidates) - 1)];
        $kcalJitter = random_int(-30, 40);
        $calories = max(80, (int) $food[2] + $kcalJitter);

        $mealInsert->execute([
            'user_id' => $userId,
            'recorded_on' => $dateStr,
            'meal_type' => $food[1],
            'food_name' => $food[0],
            'calories_kcal' => $calories,
            'calorie_source' => 'manual',
            'confidence' => 'high',
            'amount' => 1,
            'unit' => '食',
            'protein_g' => $food[3],
            'fat_g' => $food[4],
            'carbs_g' => $food[5],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $mealRows++;
    }

    $mealDays++;
    $touchedDates[$dateStr] = true;
}

$summaryRepo = new DailyNutritionSummaryRepository($userId, $pdo);
foreach (array_keys($touchedDates) as $dateStr) {
    $summaryRepo->recalculateForDate($dateStr);
}

// プロフィールの現在体重を直近体重に合わせる
$latestWeight = $pdo->prepare(
    'SELECT weight_kg FROM weight_entries
     WHERE user_id = :user_id
     ORDER BY recorded_on DESC
     LIMIT 1'
);
$latestWeight->execute(['user_id' => $userId]);
$latest = $latestWeight->fetchColumn();
if ($latest !== false) {
    $pdo->prepare(
        'UPDATE user_profile SET current_weight_kg = :w, updated_at = :updated_at WHERE user_id = :user_id'
    )->execute([
        'w' => (float) $latest,
        'updated_at' => $now,
        'user_id' => $userId,
    ]);
}

$mealTotal = (int) $pdo->query(
    "SELECT COUNT(*) FROM meal_entries WHERE user_id = {$userId}"
)->fetchColumn();
$weightTotal = (int) $pdo->query(
    "SELECT COUNT(*) FROM weight_entries WHERE user_id = {$userId}"
)->fetchColumn();
$todayMeals = (int) $pdo->query(
    "SELECT COUNT(*) FROM meal_entries WHERE user_id = {$userId} AND recorded_on = " . $pdo->quote($today->format('Y-m-d'))
)->fetchColumn();

echo "Seeded chat demo records for user_id={$userId}\n";
echo "  added meal rows: {$mealRows} across {$mealDays} days\n";
echo "  upserted weight days: {$weightDays}\n";
echo "  today ({$today->format('Y-m-d')}) meals: {$todayMeals}\n";
echo "  totals now: meals={$mealTotal}, weights={$weightTotal}\n";
