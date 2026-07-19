<?php

declare(strict_types=1);

/**
 * AIチャット確認用: 食事・体重が空の日だけサンプルを投入する（既存は上書きしない）。
 *
 * 使い方:
 *   php scripts/seed_chat_demo_records.php [user_id]
 *
 * 今日のメニューは「痩せる？」「バランスどう？」確認向けに、
 * PFC一部登録・食品名つき明細・現実的なカロリー構成にしている。
 */

require_once dirname(__DIR__) . '/scripts/EnvLoader.php';
load_project_env();
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/DailyNutritionSummaryRepository.php';

$pdo = Database::connection();
$userId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($userId <= 0) {
    $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id DESC LIMIT 1')->fetchColumn();
}
if ($userId <= 0) {
    fwrite(STDERR, "user が見つかりません。先にログイン用ユーザーを作成してください。\n");
    exit(1);
}

$tz = new DateTimeZone('Asia/Tokyo');
$today = new DateTimeImmutable('today', $tz);
$now = $today->format('Y-m-d H:i:s');

// 過去日用のプール（PFCあり寄り）
$foodPool = [
    ['トーストと目玉焼き', 'breakfast', 320, 1.0, '食', 14.0, 15.0, 28.0, true],
    ['ヨーグルトとバナナ', 'breakfast', 250, 1.0, '食', 10.0, 5.0, 40.0, true],
    ['オートミール牛乳がけ', 'breakfast', 280, 1.0, '食', 9.0, 6.0, 45.0, true],
    ['納豆ごはん', 'breakfast', 380, 1.0, '食', 14.0, 8.0, 60.0, true],
    ['鶏むねサラダ', 'lunch', 420, 1.0, '食', 35.0, 12.0, 20.0, true],
    ['焼き魚定食', 'lunch', 650, 1.0, '食', 28.0, 18.0, 70.0, true],
    ['コンビニサンド', 'lunch', 480, 1.0, '食', 16.0, 18.0, 55.0, false],
    ['かけうどん', 'lunch', 520, 1.0, '食', null, null, null, false],
    ['親子丼', 'lunch', 700, 1.0, '食', 30.0, 22.0, 85.0, true],
    ['生姜焼き定食', 'dinner', 720, 1.0, '食', 32.0, 25.0, 75.0, true],
    ['サラダチキンと野菜', 'dinner', 450, 1.0, '食', 40.0, 10.0, 25.0, true],
    ['トマトパスタ', 'dinner', 620, 1.0, '食', null, null, null, false],
    ['魚と豆腐の煮物', 'dinner', 480, 1.0, '食', 30.0, 12.0, 35.0, true],
    ['カレーライス', 'dinner', 780, 1.0, '食', null, null, null, false],
    ['プロテインバー', 'snack', 180, 1.0, '本', 15.0, 6.0, 18.0, true],
    ['りんご', 'snack', 90, 1.0, '個', 0.3, 0.2, 22.0, true],
    ['ギリシャヨーグルト', 'snack', 120, 100.0, 'g', 10.0, 3.0, 8.0, true],
    ['アーモンド一小袋', 'snack', 200, 30.0, 'g', 5.0, 18.0, 6.0, true],
];

/**
 * 今日専用: AIコーチ確認向けの「いい感じ」メニュー。
 * - 食品名がはっきりしている（PFC参考推定しやすい）
 * - 一部だけPFC登録（partial）
 * - 減量向き寄りだが断定しすぎない構成
 *
 * @return list<array{0:string,1:string,2:int,3:float,4:string,5:?float,6:?float,7:?float,8:?string,9:?float}>
 */
function todayDemoMeals(): array
{
    return [
        // breakfast: PFC登録あり
        ['鶏むね肉のサラダ', 'breakfast', 280, 150.0, 'g', 35.0, 5.0, 8.0, '150g', 150.0],
        ['玄米ごはん', 'breakfast', 220, 120.0, 'g', 4.0, 1.5, 46.0, '茶碗小', 120.0],
        // lunch: PFCなし（推定させたい）
        ['コンビニのサラダチキンランチ', 'lunch', 450, 1.0, '食', null, null, null, null, null],
        ['わかめスープ', 'lunch', 35, 1.0, '杯', null, null, null, null, null],
        // dinner: 一部登録
        ['鮭の塩焼き', 'dinner', 220, 1.0, '切れ', 25.0, 12.0, 0.0, '1切れ', 80.0],
        ['冷ややっこ', 'dinner', 80, 0.5, '丁', 8.0, 4.0, 2.0, '半丁', 150.0],
        ['キャベツの千切り', 'dinner', 40, 100.0, 'g', null, null, null, null, null],
        ['白米', 'dinner', 250, 150.0, 'g', null, null, null, '茶碗1杯', 150.0],
        // snack: 登録あり
        ['ギリシャヨーグルト 無糖', 'snack', 100, 150.0, 'g', 15.0, 0.5, 6.0, '150g', 150.0],
    ];
}

$mealCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM meal_entries WHERE user_id = :user_id AND recorded_on = :recorded_on'
);
$weightCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM weight_entries WHERE user_id = :user_id AND recorded_on = :recorded_on'
);
$latestWeightStmt = $pdo->prepare(
    'SELECT weight_kg FROM weight_entries WHERE user_id = :user_id ORDER BY recorded_on DESC LIMIT 1'
);
$mealInsert = $pdo->prepare(
    'INSERT INTO meal_entries (
        user_id, recorded_on, meal_type, food_name, calories_kcal, calories_edited,
        calorie_source, confidence, amount, unit, serving_label, serving_weight_g,
        protein_g, fat_g, carbs_g, created_at, updated_at
     ) VALUES (
        :user_id, :recorded_on, :meal_type, :food_name, :calories_kcal, 0,
        :calorie_source, :confidence, :amount, :unit, :serving_label, :serving_weight_g,
        :protein_g, :fat_g, :carbs_g, :created_at, :updated_at
     )'
);
$weightUpsert = $pdo->prepare(
    'INSERT INTO weight_entries (user_id, recorded_on, weight_kg, created_at, updated_at)
     VALUES (:user_id, :recorded_on, :weight_kg, :created_at, :updated_at)
     ON DUPLICATE KEY UPDATE weight_kg = VALUES(weight_kg), updated_at = VALUES(updated_at)'
);

$latestWeightStmt->execute(['user_id' => $userId]);
$latestWeight = $latestWeightStmt->fetchColumn();
$baseWeight = is_numeric($latestWeight) ? (float) $latestWeight : 59.8;

$mealRows = 0;
$weightDays = 0;
$seededDates = [];
$skippedMealDays = 0;

// 直近14日で空の日を埋める（今日は専用メニュー）
for ($offset = 13; $offset >= 0; $offset--) {
    $date = $today->modify(sprintf('-%d days', $offset));
    $dateStr = $date->format('Y-m-d');

    $mealCountStmt->execute(['user_id' => $userId, 'recorded_on' => $dateStr]);
    $existingMeals = (int) $mealCountStmt->fetchColumn();

    $weightCountStmt->execute(['user_id' => $userId, 'recorded_on' => $dateStr]);
    $existingWeight = (int) $weightCountStmt->fetchColumn();

    if ($existingWeight === 0 && ($offset === 0 || $offset === 1 || random_int(1, 100) <= 70)) {
        $trend = -($offset * 0.02);
        $noise = random_int(-10, 10) / 100;
        $weight = round($baseWeight + $trend + $noise, 1);
        $weightUpsert->execute([
            'user_id' => $userId,
            'recorded_on' => $dateStr,
            'weight_kg' => $weight,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $weightDays++;
    }

    if ($existingMeals > 0) {
        $skippedMealDays++;
        continue;
    }

    // 古い空き日はたまにスキップ（記録抜け感）
    if ($offset > 2 && random_int(1, 100) > 75) {
        continue;
    }

    if ($offset === 0) {
        $meals = todayDemoMeals();
    } else {
        $types = $offset <= 2
            ? ['breakfast', 'lunch', 'dinner']
            : array_slice(['breakfast', 'lunch', 'dinner', 'snack'], 0, random_int(2, 3));
        $meals = [];
        foreach ($types as $type) {
            $candidates = array_values(array_filter(
                $foodPool,
                static fn (array $f): bool => $f[1] === $type
            ));
            $food = $candidates[random_int(0, count($candidates) - 1)];
            $meals[] = [
                $food[0],
                $food[1],
                max(80, (int) $food[2] + random_int(-25, 35)),
                $food[3],
                $food[4],
                $food[8] ? $food[5] : null,
                $food[8] ? $food[6] : null,
                $food[8] ? $food[7] : null,
                null,
                null,
            ];
        }
    }

    foreach ($meals as $meal) {
        $mealInsert->execute([
            'user_id' => $userId,
            'recorded_on' => $dateStr,
            'meal_type' => $meal[1],
            'food_name' => $meal[0],
            'calories_kcal' => (int) $meal[2],
            'calorie_source' => 'manual',
            'confidence' => 'high',
            'amount' => $meal[3],
            'unit' => $meal[4],
            'serving_label' => $meal[8] ?? null,
            'serving_weight_g' => $meal[9] ?? null,
            'protein_g' => $meal[5],
            'fat_g' => $meal[6],
            'carbs_g' => $meal[7],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $mealRows++;
    }

    $seededDates[$dateStr] = true;
}

// プロフィールの穴を軽く埋める（既存値は維持）
$profile = $pdo->prepare('SELECT desired_diet_method, activity_level, target_pace_kg_per_month FROM user_profile WHERE user_id = :user_id LIMIT 1');
$profile->execute(['user_id' => $userId]);
$profileRow = $profile->fetch(PDO::FETCH_ASSOC);
if (is_array($profileRow)) {
    $updates = [];
    $params = ['user_id' => $userId];
    if (trim((string) ($profileRow['desired_diet_method'] ?? '')) === '') {
        $updates[] = 'desired_diet_method = :desired_diet_method';
        $params['desired_diet_method'] = '無理な制限はせず、たんぱく質を意識しつつ目標カロリー内で続ける';
    }
    if (trim((string) ($profileRow['activity_level'] ?? '')) === '') {
        $updates[] = 'activity_level = :activity_level';
        $params['activity_level'] = 'light';
    }
    if ($profileRow['target_pace_kg_per_month'] === null || $profileRow['target_pace_kg_per_month'] === '') {
        $updates[] = 'target_pace_kg_per_month = :target_pace';
        $params['target_pace'] = 2.0;
    }
    if ($updates !== []) {
        $updates[] = 'updated_at = :updated_at';
        $params['updated_at'] = $now;
        $pdo->prepare(
            'UPDATE user_profile SET ' . implode(', ', $updates) . ' WHERE user_id = :user_id'
        )->execute($params);
        echo "Updated sparse profile fields for user_id={$userId}\n";
    }
}

$summaryRepo = new DailyNutritionSummaryRepository($userId, $pdo);
foreach (array_keys($seededDates) as $dateStr) {
    $summaryRepo->recalculateForDate($dateStr);
}

$todayStr = $today->format('Y-m-d');
$todayMeals = $pdo->prepare(
    'SELECT meal_type, food_name, calories_kcal, protein_g, fat_g, carbs_g
     FROM meal_entries WHERE user_id = :user_id AND recorded_on = :d ORDER BY id'
);
$todayMeals->execute(['user_id' => $userId, 'd' => $todayStr]);
$todayRows = $todayMeals->fetchAll(PDO::FETCH_ASSOC);

echo "Seeded chat demo records for user_id={$userId}\n";
echo "  added meal rows: {$mealRows} across " . count($seededDates) . " empty day(s)\n";
echo "  skipped days that already had meals: {$skippedMealDays}\n";
echo "  upserted missing weight days: {$weightDays}\n";
echo "  today ({$todayStr}) meals:\n";
foreach ($todayRows as $row) {
    $pfc = ($row['protein_g'] !== null || $row['fat_g'] !== null || $row['carbs_g'] !== null)
        ? sprintf('P%s/F%s/C%s', $row['protein_g'] ?? '-', $row['fat_g'] ?? '-', $row['carbs_g'] ?? '-')
        : 'PFC未登録';
    echo sprintf(
        "    [%s] %s %dkcal (%s)\n",
        $row['meal_type'],
        $row['food_name'],
        (int) $row['calories_kcal'],
        $pfc,
    );
}
echo "\n試してみる質問例:\n";
echo "  ・今日のメニュー痩せる？\n";
echo "  ・今日の食事バランスどう？\n";
echo "  ・今日あと何カロリー食べられる？\n";
echo "  ・タンパク質は足りてる？\n";
echo "  ・あと何kg？\n";
