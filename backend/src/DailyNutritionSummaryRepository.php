<?php

declare(strict_types=1);

/**
 * daily_nutrition_summaries テーブルの集計・読み書きを担当する。
 */
final class DailyNutritionSummaryRepository
{
    private PDO $db;
    private int $userId;

    public function __construct(int $userId, ?PDO $db = null)
    {
        $this->userId = $userId;
        $this->db = $db ?? Database::connection();
    }

    /**
     * 指定日の meal_entries からサマリーを再計算して upsert する。
     *
     * @return array<string, mixed>
     */
    public function recalculateForDate(string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT meal_type, food_name, calories_kcal, calories_edited, calorie_source, confidence,
                    protein_g, fat_g, carbs_g, fiber_g, sodium_mg
             FROM meal_entries
             WHERE user_id = :user_id AND recorded_on = :recorded_on
             ORDER BY id ASC'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'recorded_on' => $date,
        ]);
        $rows = $statement->fetchAll();

        $mealKcal = [
            'breakfast' => 0,
            'lunch' => 0,
            'dinner' => 0,
            'snack' => 0,
        ];
        $totalKcal = 0;
        $proteinSum = 0.0;
        $fatSum = 0.0;
        $carbsSum = 0.0;
        $fiberSum = 0.0;
        $sodiumSum = 0.0;
        $proteinKnown = 0;
        $fatKnown = 0;
        $carbsKnown = 0;
        $fiberKnown = 0;
        $sodiumKnown = 0;
        $pfcKnownEntryCount = 0;
        $estimatedCount = 0;
        $editedCount = 0;
        $lowConfidenceCount = 0;
        $aiWebSearchCount = 0;
        $userRegisteredCount = 0;
        $entryCount = count($rows);

        /** @var array<string, int> $foodTotals */
        $foodTotals = [];

        foreach ($rows as $row) {
            $mealType = (string) $row['meal_type'];
            $kcal = (int) $row['calories_kcal'];
            $totalKcal += $kcal;
            if (isset($mealKcal[$mealType])) {
                $mealKcal[$mealType] += $kcal;
            }

            $label = (string) $row['food_name'];
            $foodTotals[$label] = ($foodTotals[$label] ?? 0) + $kcal;

            if ((int) ($row['calories_edited'] ?? 0) === 1) {
                $editedCount++;
            }

            $source = (string) ($row['calorie_source'] ?? '');
            if ($source === 'claude_estimate') {
                $estimatedCount++;
            }
            if ($source === 'ai_web_search') {
                $aiWebSearchCount++;
            }
            if ($source === 'user_registered') {
                $userRegisteredCount++;
            }

            $confidence = (string) ($row['confidence'] ?? '');
            if ($confidence === 'low') {
                $lowConfidenceCount++;
            }

            $hasPfc = false;
            if ($row['protein_g'] !== null) {
                $proteinSum += (float) $row['protein_g'];
                $proteinKnown++;
                $hasPfc = true;
            }
            if ($row['fat_g'] !== null) {
                $fatSum += (float) $row['fat_g'];
                $fatKnown++;
                $hasPfc = true;
            }
            if ($row['carbs_g'] !== null) {
                $carbsSum += (float) $row['carbs_g'];
                $carbsKnown++;
                $hasPfc = true;
            }
            if ($row['fiber_g'] !== null) {
                $fiberSum += (float) $row['fiber_g'];
                $fiberKnown++;
            }
            if ($row['sodium_mg'] !== null) {
                $sodiumSum += (float) $row['sodium_mg'];
                $sodiumKnown++;
            }
            if ($hasPfc) {
                $pfcKnownEntryCount++;
            }
        }

        arsort($foodTotals);
        $topFoods = [];
        foreach (array_slice($foodTotals, 0, 5, true) as $name => $kcal) {
            $topFoods[] = ['name' => $name, 'kcal' => $kcal];
        }

        $summaryText = $this->buildSummaryText(
            $totalKcal,
            $mealKcal,
            $entryCount,
            $pfcKnownEntryCount,
            $lowConfidenceCount,
            $editedCount,
            $proteinKnown,
            $entryCount,
        );

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $topFoodsJson = $topFoods === [] ? null : json_encode($topFoods, JSON_UNESCAPED_UNICODE);

        $upsert = $this->db->prepare(
            'INSERT INTO daily_nutrition_summaries (
                user_id, recorded_on, total_kcal, total_protein_g, total_fat_g, total_carbs_g,
                total_fiber_g, total_sodium_mg, breakfast_kcal, lunch_kcal, dinner_kcal, snack_kcal,
                meal_entry_count, estimated_entry_count, edited_entry_count, low_confidence_entry_count,
                ai_web_search_entry_count, user_registered_entry_count, pfc_known_entry_count,
                top_foods_json, summary_text, created_at, updated_at
             ) VALUES (
                :user_id, :recorded_on, :total_kcal, :total_protein_g, :total_fat_g, :total_carbs_g,
                :total_fiber_g, :total_sodium_mg, :breakfast_kcal, :lunch_kcal, :dinner_kcal, :snack_kcal,
                :meal_entry_count, :estimated_entry_count, :edited_entry_count, :low_confidence_entry_count,
                :ai_web_search_entry_count, :user_registered_entry_count, :pfc_known_entry_count,
                :top_foods_json, :summary_text, :created_at, :updated_at
             )
             ON DUPLICATE KEY UPDATE
                total_kcal = VALUES(total_kcal),
                total_protein_g = VALUES(total_protein_g),
                total_fat_g = VALUES(total_fat_g),
                total_carbs_g = VALUES(total_carbs_g),
                total_fiber_g = VALUES(total_fiber_g),
                total_sodium_mg = VALUES(total_sodium_mg),
                breakfast_kcal = VALUES(breakfast_kcal),
                lunch_kcal = VALUES(lunch_kcal),
                dinner_kcal = VALUES(dinner_kcal),
                snack_kcal = VALUES(snack_kcal),
                meal_entry_count = VALUES(meal_entry_count),
                estimated_entry_count = VALUES(estimated_entry_count),
                edited_entry_count = VALUES(edited_entry_count),
                low_confidence_entry_count = VALUES(low_confidence_entry_count),
                ai_web_search_entry_count = VALUES(ai_web_search_entry_count),
                user_registered_entry_count = VALUES(user_registered_entry_count),
                pfc_known_entry_count = VALUES(pfc_known_entry_count),
                top_foods_json = VALUES(top_foods_json),
                summary_text = VALUES(summary_text),
                updated_at = VALUES(updated_at)'
        );
        $upsert->execute([
            'user_id' => $this->userId,
            'recorded_on' => $date,
            'total_kcal' => $totalKcal,
            'total_protein_g' => $proteinKnown > 0 ? round($proteinSum, 2) : null,
            'total_fat_g' => $fatKnown > 0 ? round($fatSum, 2) : null,
            'total_carbs_g' => $carbsKnown > 0 ? round($carbsSum, 2) : null,
            'total_fiber_g' => $fiberKnown > 0 ? round($fiberSum, 2) : null,
            'total_sodium_mg' => $sodiumKnown > 0 ? round($sodiumSum, 2) : null,
            'breakfast_kcal' => $mealKcal['breakfast'],
            'lunch_kcal' => $mealKcal['lunch'],
            'dinner_kcal' => $mealKcal['dinner'],
            'snack_kcal' => $mealKcal['snack'],
            'meal_entry_count' => $entryCount,
            'estimated_entry_count' => $estimatedCount,
            'edited_entry_count' => $editedCount,
            'low_confidence_entry_count' => $lowConfidenceCount,
            'ai_web_search_entry_count' => $aiWebSearchCount,
            'user_registered_entry_count' => $userRegisteredCount,
            'pfc_known_entry_count' => $pfcKnownEntryCount,
            'top_foods_json' => $topFoodsJson,
            'summary_text' => $summaryText,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->getForDate($date) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getForDate(string $date): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM daily_nutrition_summaries
             WHERE user_id = :user_id AND recorded_on = :recorded_on
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'recorded_on' => $date,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBetween(string $startDate, string $endDate): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM daily_nutrition_summaries
             WHERE user_id = :user_id AND recorded_on BETWEEN :start AND :end
             ORDER BY recorded_on ASC'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        $summaries = [];
        foreach ($statement->fetchAll() as $row) {
            $summaries[] = $this->mapRow($row);
        }

        return $summaries;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $topFoods = null;
        if (isset($row['top_foods_json']) && $row['top_foods_json'] !== null) {
            $decoded = json_decode((string) $row['top_foods_json'], true);
            if (is_array($decoded)) {
                $topFoods = $decoded;
            }
        }

        return [
            'recordedOn' => (string) $row['recorded_on'],
            'totalKcal' => (int) $row['total_kcal'],
            'totalProteinG' => $row['total_protein_g'] !== null ? (float) $row['total_protein_g'] : null,
            'totalFatG' => $row['total_fat_g'] !== null ? (float) $row['total_fat_g'] : null,
            'totalCarbsG' => $row['total_carbs_g'] !== null ? (float) $row['total_carbs_g'] : null,
            'totalFiberG' => $row['total_fiber_g'] !== null ? (float) $row['total_fiber_g'] : null,
            'totalSodiumMg' => $row['total_sodium_mg'] !== null ? (float) $row['total_sodium_mg'] : null,
            'breakfastKcal' => (int) $row['breakfast_kcal'],
            'lunchKcal' => (int) $row['lunch_kcal'],
            'dinnerKcal' => (int) $row['dinner_kcal'],
            'snackKcal' => (int) $row['snack_kcal'],
            'mealEntryCount' => (int) $row['meal_entry_count'],
            'estimatedEntryCount' => (int) $row['estimated_entry_count'],
            'editedEntryCount' => (int) $row['edited_entry_count'],
            'lowConfidenceEntryCount' => (int) $row['low_confidence_entry_count'],
            'aiWebSearchEntryCount' => (int) $row['ai_web_search_entry_count'],
            'userRegisteredEntryCount' => (int) $row['user_registered_entry_count'],
            'pfcKnownEntryCount' => (int) $row['pfc_known_entry_count'],
            'topFoods' => $topFoods,
            'summaryText' => $row['summary_text'] !== null ? (string) $row['summary_text'] : null,
        ];
    }

    /**
     * @param array<string, int> $mealKcal
     */
    private function buildSummaryText(
        int $totalKcal,
        array $mealKcal,
        int $entryCount,
        int $pfcKnownEntryCount,
        int $lowConfidenceCount,
        int $editedCount,
        int $proteinKnown,
        int $totalEntries,
    ): ?string {
        if ($entryCount === 0) {
            return '食事記録はありません。';
        }

        $notes = [];

        if ($totalKcal > 0 && $mealKcal['snack'] > 0) {
            $snackRatio = $mealKcal['snack'] / $totalKcal;
            if ($snackRatio >= 0.35) {
                $notes[] = '間食のカロリーが多めです';
            }
        }

        if ($totalKcal > 0 && $mealKcal['dinner'] / $totalKcal >= 0.5) {
            $notes[] = '夕食にカロリーが集中しています';
        }

        if ($pfcKnownEntryCount === 0 || $proteinKnown < max(1, (int) floor($totalEntries * 0.5))) {
            $notes[] = 'タンパク質データが不足しているため、PFC分析は参考値です';
        }

        if ($lowConfidenceCount > 0 && $lowConfidenceCount >= max(1, (int) floor($entryCount * 0.5))) {
            $notes[] = '低信頼度の食事データが多く含まれています';
        }

        if ($editedCount > 0 && $editedCount >= max(1, (int) floor($entryCount * 0.5))) {
            $notes[] = '手修正されたカロリー記録が多く含まれています';
        }

        if ($notes === []) {
            return null;
        }

        return implode('。', $notes) . '。';
    }
}
