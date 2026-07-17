<?php

declare(strict_types=1);

/**
 * 対象期間の DB 記録を authoritative_record_context（構造化）へ組み立てる。
 */
final class AuthoritativeRecordContextBuilder
{
    /**
     * 単一期間の日次記録を組み立てる（単体テスト・後方互換用）。
     *
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
        $built = $this->buildDailyRecordsForRange(
            $scope->startDate,
            $scope->endDate,
            $mealRows,
            $nutritionByDate,
            $weightByDate,
            $stepsByDate,
            $exercisesByDate,
        );
        $dailyRecords = $built['records'];
        $mealCount = $built['meal_count'];

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
     * 今日詳細・直近7日・30日サマリーの層付きコンテキストを組み立てる。
     *
     * @param list<array<string, mixed>> $mealRows30
     * @param array<string, array<string, mixed>> $nutritionByDate30
     * @param array<string, float|null> $weightByDate30
     * @param array<string, array{count: int, burnedCalories: int}> $stepsByDate7
     * @param array<string, array{entries: list<array<string, mixed>>, burnedCalories: int}> $exercisesByDate7
     * @param array<string, int> $stepsCountByDate30 date => step count
     * @param array<string, int> $exerciseKcalByDate30 date => burned kcal
     * @param array<string, mixed> $profileSnapshot
     * @return array<string, mixed>
     */
    public function buildLayered(
        RecordQueryScope $scope,
        DateTimeImmutable $today,
        array $mealRows30,
        array $nutritionByDate30,
        array $weightByDate30,
        array $stepsByDate7,
        array $exercisesByDate7,
        array $stepsCountByDate30,
        array $exerciseKcalByDate30,
        array $profileSnapshot,
    ): array {
        $today = $today->setTime(0, 0);
        $start7 = $today->modify('-6 days');
        $start30 = $today->modify('-29 days');
        $todayDate = $today->format('Y-m-d');

        $recent7 = $this->buildDailyRecordsForRange(
            $start7,
            $today,
            $mealRows30,
            $nutritionByDate30,
            $weightByDate30,
            $stepsByDate7,
            $exercisesByDate7,
        );
        $recent7d = $recent7['records'];
        $todayDetail = null;
        foreach ($recent7d as $day) {
            if (($day['date'] ?? null) === $todayDate) {
                $todayDetail = $day;
                break;
            }
        }
        if ($todayDetail === null) {
            $todayDetail = [
                'date' => $todayDate,
                'meals' => [],
                'total_calories' => 0,
                'record_status' => 'no_record',
                'weight_kg' => null,
                'weight_status' => 'no_record',
                'steps' => null,
                'steps_status' => 'no_record',
                'exercises' => null,
                'exercises_status' => 'no_record',
            ];
        }

        $summary30d = $this->buildSummary30d(
            $start30->format('Y-m-d'),
            $todayDate,
            $mealRows30,
            $weightByDate30,
            $stepsCountByDate30,
            $exerciseKcalByDate30,
        );

        $primaryFocus = $scope->type === RecordScopeType::TODAY
            ? 'today_detail'
            : 'recent_7d_and_summary_30d';

        $layerGuidance = $primaryFocus === 'today_detail'
            ? '主参照は today_detail。recent_7d と summary_30d は補助。'
            : '主参照は recent_7d と summary_30d。today_detail は当日状況の補足。';

        $payload = [
            'query_scope' => $scope->toArray(),
            'primary_focus' => $primaryFocus,
            'layer_guidance' => $layerGuidance,
            'profile' => $profileSnapshot,
            'today_detail' => $todayDetail,
            'recent_7d' => $recent7d,
            'summary_30d' => $summary30d,
            'notes' => [
                'record_status=no_record means no DB entry for that day; do not assert the user ate nothing',
                'food names and kcal for specific meals must come from today_detail or recent_7d',
                'summary_30d has aggregates only (no meal food names)',
                'conversation history must not supply food facts',
            ],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            $json = '{}';
        }

        return [
            'query_scope' => $scope->toArray(),
            'primary_focus' => $primaryFocus,
            'layer_guidance' => $layerGuidance,
            'profile' => $profileSnapshot,
            'today_detail' => $todayDetail,
            'recent_7d' => $recent7d,
            'summary_30d' => $summary30d,
            'daily_records' => $recent7d,
            'meal_count' => $recent7['meal_count'],
            'json' => $json,
            'text' => $this->formatLayeredText(
                $scope,
                $primaryFocus,
                $layerGuidance,
                $todayDetail,
                $recent7d,
                $summary30d,
                $profileSnapshot,
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $mealRows
     * @param array<string, array<string, mixed>> $nutritionByDate
     * @param array<string, float|null> $weightByDate
     * @param array<string, array{count?: int, burnedCalories?: int}|mixed> $stepsByDate
     * @param array<string, array{entries?: list<array<string, mixed>>, burnedCalories?: int}|mixed> $exercisesByDate
     * @return array{records: list<array<string, mixed>>, meal_count: int}
     */
    public function buildDailyRecordsForRange(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $mealRows,
        array $nutritionByDate,
        array $weightByDate,
        array $stepsByDate,
        array $exercisesByDate,
    ): array {
        $mealsByDate = [];
        foreach ($mealRows as $row) {
            $date = (string) $row['recordedOn'];
            if ($date < $start->format('Y-m-d') || $date > $end->format('Y-m-d')) {
                continue;
            }
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
        $cursor = $start->setTime(0, 0);
        $endDay = $end->setTime(0, 0);

        while ($cursor <= $endDay) {
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

        return [
            'records' => $dailyRecords,
            'meal_count' => $mealCount,
        ];
    }

    /**
     * @param list<array<string, mixed>> $mealRows
     * @param array<string, float|null> $weightByDate
     * @param array<string, int> $stepsCountByDate
     * @param array<string, int> $exerciseKcalByDate
     * @return array<string, mixed>
     */
    public function buildSummary30d(
        string $startDate,
        string $endDate,
        array $mealRows,
        array $weightByDate,
        array $stepsCountByDate,
        array $exerciseKcalByDate,
    ): array {
        $kcalByDate = [];
        foreach ($mealRows as $row) {
            $date = (string) ($row['recordedOn'] ?? '');
            if ($date < $startDate || $date > $endDate) {
                continue;
            }
            $kcalByDate[$date] = ($kcalByDate[$date] ?? 0) + (int) ($row['calories'] ?? 0);
        }

        $daysWithMeals = count($kcalByDate);
        $totalIntakeKcal = array_sum($kcalByDate);
        $avgIntakeKcal = $daysWithMeals > 0
            ? (int) round($totalIntakeKcal / $daysWithMeals)
            : null;

        $weights = [];
        foreach ($weightByDate as $date => $value) {
            if ($value === null) {
                continue;
            }
            if ($date < $startDate || $date > $endDate) {
                continue;
            }
            $weights[$date] = (float) $value;
        }
        ksort($weights);
        $weightDates = array_keys($weights);
        $weightStart = $weightDates === [] ? null : $weights[$weightDates[0]];
        $weightEnd = $weightDates === [] ? null : $weights[$weightDates[count($weightDates) - 1]];
        $weightDelta = ($weightStart !== null && $weightEnd !== null)
            ? round($weightEnd - $weightStart, 2)
            : null;

        $stepsTotal = 0;
        $stepsDays = 0;
        foreach ($stepsCountByDate as $date => $count) {
            if ($date < $startDate || $date > $endDate) {
                continue;
            }
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }
            $stepsTotal += $count;
            $stepsDays++;
        }

        $exerciseTotal = 0;
        $exerciseDays = 0;
        foreach ($exerciseKcalByDate as $date => $kcal) {
            if ($date < $startDate || $date > $endDate) {
                continue;
            }
            $kcal = (int) $kcal;
            if ($kcal <= 0) {
                continue;
            }
            $exerciseTotal += $kcal;
            $exerciseDays++;
        }

        return [
            'period_start' => $startDate,
            'period_end' => $endDate,
            'days_with_meals' => $daysWithMeals,
            'total_intake_kcal' => $totalIntakeKcal,
            'avg_intake_kcal_on_recorded_days' => $avgIntakeKcal,
            'weight_start_kg' => $weightStart,
            'weight_end_kg' => $weightEnd,
            'weight_delta_kg' => $weightDelta,
            'weight_record_days' => count($weights),
            'steps_total' => $stepsTotal,
            'steps_recorded_days' => $stepsDays,
            'avg_steps_on_recorded_days' => $stepsDays > 0 ? (int) round($stepsTotal / $stepsDays) : null,
            'exercise_burned_kcal_total' => $exerciseTotal,
            'exercise_recorded_days' => $exerciseDays,
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

    /**
     * @param array<string, mixed> $todayDetail
     * @param list<array<string, mixed>> $recent7d
     * @param array<string, mixed> $summary30d
     * @param array<string, mixed> $profileSnapshot
     */
    private function formatLayeredText(
        RecordQueryScope $scope,
        string $primaryFocus,
        string $layerGuidance,
        array $todayDetail,
        array $recent7d,
        array $summary30d,
        array $profileSnapshot,
    ): string {
        $lines = [];
        $lines[] = 'authoritative_record_context（DB由来・唯一の記録事実ソース / 層分け）';
        $lines[] = sprintf(
            'query_scope: %s 〜 %s（scope=%s / 表現=%s）',
            $scope->startDateString(),
            $scope->endDateString(),
            $scope->type->value,
            $scope->originalExpression,
        );
        $lines[] = 'primary_focus: ' . $primaryFocus;
        $lines[] = 'layer_guidance: ' . $layerGuidance;
        $lines[] = '';
        $lines[] = '■ プロフィール・目標（登録値）';
        foreach ($profileSnapshot as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $lines[] = '- ' . $key . ': ' . ($value === null || $value === '' ? '未設定' : (string) $value);
            }
        }
        $lines[] = '';
        $lines[] = '■ today_detail（' . (string) ($todayDetail['date'] ?? '') . '）';
        $lines[] = '食事合計: ' . (int) ($todayDetail['total_calories'] ?? 0) . 'kcal / status='
            . (string) ($todayDetail['record_status'] ?? 'no_record');
        $lines[] = '';
        $lines[] = '■ recent_7d（' . count($recent7d) . '日）';
        foreach ($recent7d as $day) {
            $lines[] = sprintf(
                '- %s: %s / %dkcal',
                (string) ($day['date'] ?? ''),
                (string) ($day['record_status'] ?? 'no_record'),
                (int) ($day['total_calories'] ?? 0),
            );
        }
        $lines[] = '';
        $lines[] = '■ summary_30d';
        $lines[] = sprintf(
            '期間: %s 〜 %s / 食事記録日数: %s / 平均摂取: %s / 体重変化: %s',
            (string) ($summary30d['period_start'] ?? ''),
            (string) ($summary30d['period_end'] ?? ''),
            (string) ($summary30d['days_with_meals'] ?? '0'),
            $summary30d['avg_intake_kcal_on_recorded_days'] === null
                ? 'なし'
                : $summary30d['avg_intake_kcal_on_recorded_days'] . 'kcal',
            $summary30d['weight_delta_kg'] === null
                ? 'なし'
                : $summary30d['weight_delta_kg'] . 'kg',
        );

        return implode("\n", $lines);
    }
}
