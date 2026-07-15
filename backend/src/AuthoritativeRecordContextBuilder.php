<?php

declare(strict_types=1);

/**
 * 対象期間の DB 記録を authoritative_record_context（構造化）へ組み立てる。
 */
final class AuthoritativeRecordContextBuilder
{
    /**
     * @param list<array{
     *   recordedOn: string,
     *   mealType: string,
     *   foodName: string,
     *   calories: int,
     *   amount?: float|null,
     *   unit?: string|null,
     *   proteinG?: float|null,
     *   fatG?: float|null,
     *   carbsG?: float|null
     * }> $mealRows
     * @param array<string, array<string, mixed>> $nutritionByDate recordedOn => summary
     * @param array<string, float|null> $weightByDate
     * @param array<string, array{count: int, burnedCalories: int}> $stepsByDate
     * @param array<string, array{entries: list<array<string, mixed>>, burnedCalories: int}> $exercisesByDate
     * @param array<string, mixed> $profileSnapshot
     * @return array{
     *   query_scope: array<string, string>,
     *   profile: array<string, mixed>,
     *   daily_records: list<array<string, mixed>>,
     *   meal_count: int,
     *   json: string,
     *   text: string
     * }
     */
    public function build(
        RecordQueryScope $scope,
        array $mealRows,
        array $nutritionByDate,
        array $weightByDate,
        array $stepsByDate,
        array $exercisesByDate,
        array $profileSnapshot,
    ): array {
        $mealsByDate = [];
        foreach ($mealRows as $row) {
            $date = (string) $row['recordedOn'];
            $mealsByDate[$date][] = [
                'meal_type' => (string) $row['mealType'],
                'food_name' => (string) $row['foodName'],
                'calories' => (int) $row['calories'],
                'amount' => $row['amount'] ?? null,
                'unit' => $row['unit'] ?? null,
                'protein_g' => $row['proteinG'] ?? null,
                'fat_g' => $row['fatG'] ?? null,
                'carbs_g' => $row['carbsG'] ?? null,
            ];
        }

        $dailyRecords = [];
        $mealCount = 0;
        $cursor = $scope->startDate;
        $end = $scope->endDate;

        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $meals = $mealsByDate[$date] ?? [];
            $mealCount += count($meals);
            $totalCalories = 0;
            foreach ($meals as $meal) {
                $totalCalories += (int) $meal['calories'];
            }

            $record = [
                'date' => $date,
                'meals' => $meals,
                'total_calories' => $totalCalories,
                'record_status' => $meals === [] ? 'no_record' : 'recorded',
            ];

            if (isset($nutritionByDate[$date])) {
                $record['nutrition_summary'] = $this->mapNutrition($nutritionByDate[$date]);
            }

            if (array_key_exists($date, $weightByDate)) {
                $record['weight_kg'] = $weightByDate[$date];
            } else {
                $record['weight_kg'] = null;
                $record['weight_status'] = 'no_record';
            }

            if (isset($stepsByDate[$date]) && ($stepsByDate[$date]['count'] ?? 0) > 0) {
                $record['steps'] = $stepsByDate[$date];
            } else {
                $record['steps'] = null;
                $record['steps_status'] = 'no_record';
            }

            if (isset($exercisesByDate[$date]) && ($exercisesByDate[$date]['entries'] ?? []) !== []) {
                $record['exercises'] = $exercisesByDate[$date];
            } else {
                $record['exercises'] = null;
                $record['exercises_status'] = 'no_record';
            }

            $dailyRecords[] = $record;
            $cursor = $cursor->modify('+1 day');
        }

        $payload = [
            'query_scope' => $scope->toArray(),
            'profile' => $profileSnapshot,
            'daily_records' => $dailyRecords,
            'notes' => [
                'record_status=no_record means no DB entry for that day; do not assert the user ate nothing',
                'food names and kcal must come only from daily_records.meals',
                'conversation history must not supply food facts',
            ],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            $json = '{}';
        }

        return [
            'query_scope' => $scope->toArray(),
            'profile' => $profileSnapshot,
            'daily_records' => $dailyRecords,
            'meal_count' => $mealCount,
            'json' => $json,
            'text' => $this->formatText($scope, $dailyRecords, $profileSnapshot),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function mapNutrition(array $summary): array
    {
        return [
            'total_kcal' => $summary['totalKcal'] ?? 0,
            'breakfast_kcal' => $summary['breakfastKcal'] ?? 0,
            'lunch_kcal' => $summary['lunchKcal'] ?? 0,
            'dinner_kcal' => $summary['dinnerKcal'] ?? 0,
            'snack_kcal' => $summary['snackKcal'] ?? 0,
            'protein_g' => $summary['totalProteinG'] ?? null,
            'fat_g' => $summary['totalFatG'] ?? null,
            'carbs_g' => $summary['totalCarbsG'] ?? null,
            'pfc_known_entry_count' => $summary['pfcKnownEntryCount'] ?? 0,
            'meal_entry_count' => $summary['mealEntryCount'] ?? 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $dailyRecords
     * @param array<string, mixed> $profileSnapshot
     */
    private function formatText(RecordQueryScope $scope, array $dailyRecords, array $profileSnapshot): string
    {
        $lines = [];
        $lines[] = 'authoritative_record_context（DB由来・唯一の記録事実ソース）';
        $lines[] = sprintf(
            '対象期間: %s 〜 %s（scope=%s / 表現=%s / timezone=%s）',
            $scope->startDateString(),
            $scope->endDateString(),
            $scope->type->value,
            $scope->originalExpression,
            $scope->timezone,
        );
        $lines[] = '';
        $lines[] = '■ プロフィール・目標（登録値）';
        foreach ($profileSnapshot as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $lines[] = '- ' . $key . ': ' . ($value === null || $value === '' ? '未設定' : (string) $value);
            }
        }
        $lines[] = '';
        $lines[] = '■ 日次記録';

        foreach ($dailyRecords as $day) {
            $date = (string) $day['date'];
            $status = (string) ($day['record_status'] ?? 'no_record');
            $lines[] = '--- ' . $date . ' ---';
            if ($status === 'no_record') {
                $lines[] = '食事: 未記録（食べていないとは断定しない）';
            } else {
                $lines[] = '食事合計: ' . (int) ($day['total_calories'] ?? 0) . 'kcal';
                foreach ($day['meals'] as $meal) {
                    $amount = '';
                    if (($meal['amount'] ?? null) !== null && ($meal['unit'] ?? null) !== null) {
                        $amount = ' / ' . $meal['amount'] . $meal['unit'];
                    }
                    $lines[] = sprintf(
                        '- [%s] %s %dkcal%s',
                        (string) $meal['meal_type'],
                        (string) $meal['food_name'],
                        (int) $meal['calories'],
                        $amount,
                    );
                }
            }

            if (($day['weight_kg'] ?? null) !== null) {
                $lines[] = '体重: ' . $day['weight_kg'] . 'kg';
            } else {
                $lines[] = '体重: 未記録';
            }
        }

        return implode("\n", $lines);
    }
}
