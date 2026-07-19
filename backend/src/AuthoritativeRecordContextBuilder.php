<?php

declare(strict_types=1);

/**
 * 対象期間の DB 記録を authoritative_record_context（構造化）へ組み立てる。
 */
final class AuthoritativeRecordContextBuilder
{
    private DietAnswerEvidenceBuilder $evidenceBuilder;

    public function __construct(?DietAnswerEvidenceBuilder $evidenceBuilder = null)
    {
        $this->evidenceBuilder = $evidenceBuilder ?? new DietAnswerEvidenceBuilder();
    }

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
     *   carbsG?: float|null,
     *   servingLabel?: string|null,
     *   servingWeightG?: float|null
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
        $evidence = $this->evidenceBuilder->build(
            $scope,
            $dailyRecords,
            $weightByDate,
            $profileSnapshot,
        );

        $payload = [
            'query_scope' => $scope->toArray(),
            'profile' => $profileSnapshot,
            'scope_records' => $dailyRecords,
            'daily_records' => $dailyRecords,
            'meal_record_meta' => $evidence['meal_record_meta'],
            'pfc_evidence' => $evidence['pfc_evidence'],
            'energy_evidence' => $evidence['energy_evidence'],
            'weight_evidence' => $evidence['weight_evidence'],
            'answer_permissions' => $evidence['answer_permissions'],
            'registered_intake_kcal' => $evidence['registered_intake_kcal'],
            'notes' => [
                'record_status=no_record means no DB entry for that day; do not assert the user ate nothing',
                'food names and kcal must come only from scope_records / daily_records.meals',
                'pfc_evidence.registered_totals are DB-registered values only; partial is not full-day PFC',
                'day_completion=unknown: do not assert complete daily intake',
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
            'scope_records' => $dailyRecords,
            'daily_records' => $dailyRecords,
            'meal_count' => $mealCount,
            'meal_record_meta' => $evidence['meal_record_meta'],
            'pfc_evidence' => $evidence['pfc_evidence'],
            'energy_evidence' => $evidence['energy_evidence'],
            'weight_evidence' => $evidence['weight_evidence'],
            'answer_permissions' => $evidence['answer_permissions'],
            'registered_intake_kcal' => $evidence['registered_intake_kcal'],
            'json' => $json,
            'text' => $this->formatText($scope, $dailyRecords, $profileSnapshot, $evidence),
        ];
    }

    /**
     * 今日詳細・直近7日・8〜14日前・30日/6ヶ月サマリーの層付きコンテキストを組み立てる。
     *
     * @param list<array<string, mixed>> $mealRows6m
     * @param array<string, array<string, mixed>> $nutritionByDate14 直近14日分の日次栄養サマリー
     * @param array<string, float|null> $weightByDate6m
     * @param array<string, array{count: int, burnedCalories: int}> $stepsByDate14 直近14日分の歩数
     * @param array<string, array{entries: list<array<string, mixed>>, burnedCalories: int}> $exercisesByDate14 直近14日分の運動
     * @param array<string, int> $stepsCountByDate6m date => step count
     * @param array<string, int> $exerciseKcalByDate6m date => burned kcal
     * @param array<string, mixed> $profileSnapshot
     * @param array{
     *   first_meal_recorded_on: ?string,
     *   first_weight_recorded_on: ?string,
     *   first_steps_recorded_on: ?string,
     *   first_exercise_recorded_on: ?string
     * }|null $recordingStartDates
     * @return array<string, mixed>
     */
    public function buildLayered(
        RecordQueryScope $scope,
        DateTimeImmutable $today,
        array $mealRows6m,
        array $nutritionByDate14,
        array $weightByDate6m,
        array $stepsByDate14,
        array $exercisesByDate14,
        array $stepsCountByDate6m,
        array $exerciseKcalByDate6m,
        array $profileSnapshot,
        ?array $recordingStartDates = null,
    ): array {
        $today = $today->setTime(0, 0);
        $start7 = $today->modify('-6 days');
        $start14 = $today->modify('-13 days');
        $end8to14 = $today->modify('-7 days');
        $start30 = $today->modify('-29 days');
        $start6m = $today->modify('-6 months');
        $todayDate = $today->format('Y-m-d');
        $start30Str = $start30->format('Y-m-d');
        $start6mStr = $start6m->format('Y-m-d');

        $recent7 = $this->buildDailyRecordsForRange(
            $start7,
            $today,
            $mealRows6m,
            $nutritionByDate14,
            $weightByDate6m,
            $stepsByDate14,
            $exercisesByDate14,
        );
        $recent7d = $recent7['records'];

        $prior8to14 = $this->buildDailyRecordsForRange(
            $start14,
            $end8to14,
            $mealRows6m,
            $nutritionByDate14,
            $weightByDate6m,
            $stepsByDate14,
            $exercisesByDate14,
        );
        $recent8to14d = $prior8to14['records'];

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

        $summary30d = $this->buildPeriodSummary(
            $start30Str,
            $todayDate,
            $mealRows6m,
            $weightByDate6m,
            $stepsCountByDate6m,
            $exerciseKcalByDate6m,
        );
        $summary6m = $this->buildPeriodSummary(
            $start6mStr,
            $todayDate,
            $mealRows6m,
            $weightByDate6m,
            $stepsCountByDate6m,
            $exerciseKcalByDate6m,
        );

        $recordingMeta = $this->buildRecordingMeta(
            $today,
            $recordingStartDates ?? [
                'first_meal_recorded_on' => null,
                'first_weight_recorded_on' => null,
                'first_steps_recorded_on' => null,
                'first_exercise_recorded_on' => null,
            ],
        );

        $scopeBuilt = $this->buildDailyRecordsForRange(
            $scope->startDate,
            $scope->endDate,
            $mealRows6m,
            $nutritionByDate14,
            $weightByDate6m,
            $stepsByDate14,
            $exercisesByDate14,
        );
        $scopeRecords = $scopeBuilt['records'];
        $scopeMealCount = $scopeBuilt['meal_count'];

        // 体重傾向・「あと何kg」は対象期間外の直近記録も必要なため、利用可能な体重記録を渡す
        $weightForEvidence = $this->latestWeights($weightByDate6m, 90);

        $evidence = $this->evidenceBuilder->build(
            $scope,
            $scopeRecords,
            $weightForEvidence,
            $profileSnapshot,
        );

        $primaryFocus = 'scope_records';
        $layerGuidance = $scope->type === RecordScopeType::TODAY
            ? '主参照は scope_records（＝今日）。today_detail は同一日の補助。recent_7d / recent_8_14d / summary は対象外の補助であり、回答の中心にしない。'
            : '主参照は scope_records（query_scope の start_date〜end_date）。today_detail があっても対象期間外なら回答の主根拠にしない。recent_7d / summary は補助。';

        $payload = [
            'query_scope' => $scope->toArray(),
            'scope_start_date' => $scope->startDateString(),
            'scope_end_date' => $scope->endDateString(),
            'scope_type' => $scope->type->value,
            'scope_original_expression' => $scope->originalExpression,
            'primary_focus' => $primaryFocus,
            'layer_guidance' => $layerGuidance,
            'recording_meta' => $recordingMeta,
            'profile' => $profileSnapshot,
            'scope_records' => $scopeRecords,
            'meal_record_meta' => $evidence['meal_record_meta'],
            'pfc_evidence' => $evidence['pfc_evidence'],
            'energy_evidence' => $evidence['energy_evidence'],
            'weight_evidence' => $evidence['weight_evidence'],
            'answer_permissions' => $evidence['answer_permissions'],
            'registered_intake_kcal' => $evidence['registered_intake_kcal'],
            'today_detail' => $todayDetail,
            'recent_7d' => $recent7d,
            'recent_8_14d' => $recent8to14d,
            'summary_30d' => $summary30d,
            'summary_6m' => $summary6m,
            'notes' => [
                'Answers must center on scope_records for query_scope dates only',
                'record_status=no_record means no DB entry for that day; do not assert the user ate nothing',
                'food names and kcal for the question must come from scope_records meals',
                'today_detail is supplemental; ignore it as primary evidence when outside query_scope',
                'recent_8_14d is days 8-14 ago (exclusive of recent_7d); use for week-over-week comparison',
                'summary_30d and summary_6m have aggregates only (no meal food names)',
                'summary weight_start_kg/weight_end_kg must be quoted with weight_start_recorded_on/weight_end_recorded_on',
                'weight_end_kg is the latest (直近) recorded weight in the period; not necessarily period_end',
                'if weight_on_period_start.status=no_record, there was no weight on period_start',
                'recording_meta.first_any_recorded_on is when the user started recording; do not invent history before that date',
                'pfc_evidence.status=partial means registered_totals are partial sums, not full-period PFC',
                'day_completion=unknown: prefer「登録された範囲では」; never assert complete daily intake',
                'conversation history must not supply food facts',
            ],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            $json = '{}';
        }

        return [
            'query_scope' => $scope->toArray(),
            'scope_start_date' => $scope->startDateString(),
            'scope_end_date' => $scope->endDateString(),
            'scope_type' => $scope->type->value,
            'scope_original_expression' => $scope->originalExpression,
            'primary_focus' => $primaryFocus,
            'layer_guidance' => $layerGuidance,
            'recording_meta' => $recordingMeta,
            'profile' => $profileSnapshot,
            'scope_records' => $scopeRecords,
            'meal_record_meta' => $evidence['meal_record_meta'],
            'pfc_evidence' => $evidence['pfc_evidence'],
            'energy_evidence' => $evidence['energy_evidence'],
            'weight_evidence' => $evidence['weight_evidence'],
            'answer_permissions' => $evidence['answer_permissions'],
            'registered_intake_kcal' => $evidence['registered_intake_kcal'],
            'today_detail' => $todayDetail,
            'recent_7d' => $recent7d,
            'recent_8_14d' => $recent8to14d,
            'summary_30d' => $summary30d,
            'summary_6m' => $summary6m,
            'daily_records' => $scopeRecords,
            'meal_count' => $scopeMealCount,
            'json' => $json,
            'text' => $this->formatLayeredText(
                $scope,
                $primaryFocus,
                $layerGuidance,
                $recordingMeta,
                $todayDetail,
                $recent7d,
                $recent8to14d,
                $summary30d,
                $summary6m,
                $profileSnapshot,
                $evidence,
                $scopeRecords,
            ),
        ];
    }

    /**
     * @param array{
     *   first_meal_recorded_on: ?string,
     *   first_weight_recorded_on: ?string,
     *   first_steps_recorded_on: ?string,
     *   first_exercise_recorded_on: ?string
     * } $recordingStartDates
     * @return array<string, mixed>
     */
    public function buildRecordingMeta(DateTimeImmutable $today, array $recordingStartDates): array
    {
        $today = $today->setTime(0, 0);
        $candidates = array_values(array_filter([
            $recordingStartDates['first_meal_recorded_on'] ?? null,
            $recordingStartDates['first_weight_recorded_on'] ?? null,
            $recordingStartDates['first_steps_recorded_on'] ?? null,
            $recordingStartDates['first_exercise_recorded_on'] ?? null,
        ], static fn ($value): bool => is_string($value) && $value !== ''));
        sort($candidates);
        $firstAny = $candidates[0] ?? null;

        $daysSinceFirst = null;
        if ($firstAny !== null) {
            $firstDate = new DateTimeImmutable($firstAny, $today->getTimezone());
            $daysSinceFirst = (int) $firstDate->diff($today)->format('%a');
        }

        return [
            'first_meal_recorded_on' => $recordingStartDates['first_meal_recorded_on'] ?? null,
            'first_weight_recorded_on' => $recordingStartDates['first_weight_recorded_on'] ?? null,
            'first_steps_recorded_on' => $recordingStartDates['first_steps_recorded_on'] ?? null,
            'first_exercise_recorded_on' => $recordingStartDates['first_exercise_recorded_on'] ?? null,
            'first_any_recorded_on' => $firstAny,
            'days_since_first_record' => $daysSinceFirst,
            'has_any_record' => $firstAny !== null,
            'note' => 'first_any_recorded_on より前の日付にはユーザー記録がない。summary の period_start と混同しないこと。',
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
                'serving_label' => $row['servingLabel'] ?? null,
                'serving_weight_g' => $row['servingWeightG'] ?? null,
                'protein_g' => $row['proteinG'] ?? null,
                'fat_g' => $row['fatG'] ?? null,
                'carbs_g' => $row['carbsG'] ?? null,
                'pfc_registered' => is_numeric($row['proteinG'] ?? null)
                    || is_numeric($row['fatG'] ?? null)
                    || is_numeric($row['carbsG'] ?? null),
                'pfc_source' => (
                    is_numeric($row['proteinG'] ?? null)
                    || is_numeric($row['fatG'] ?? null)
                    || is_numeric($row['carbsG'] ?? null)
                ) ? 'registered' : 'none',
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
    public function buildPeriodSummary(
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
        $weightStartRecordedOn = $weightDates === [] ? null : $weightDates[0];
        $weightEndRecordedOn = $weightDates === [] ? null : $weightDates[count($weightDates) - 1];
        $weightStart = $weightStartRecordedOn === null ? null : $weights[$weightStartRecordedOn];
        $weightEnd = $weightEndRecordedOn === null ? null : $weights[$weightEndRecordedOn];
        $weightDelta = ($weightStart !== null && $weightEnd !== null)
            ? round($weightEnd - $weightStart, 2)
            : null;

        $weightOnPeriodStart = array_key_exists($startDate, $weights)
            ? [
                'date' => $startDate,
                'kg' => $weights[$startDate],
                'status' => 'recorded',
            ]
            : [
                'date' => $startDate,
                'kg' => null,
                'status' => 'no_record',
            ];
        $weightOnPeriodEnd = array_key_exists($endDate, $weights)
            ? [
                'date' => $endDate,
                'kg' => $weights[$endDate],
                'status' => 'recorded',
            ]
            : [
                'date' => $endDate,
                'kg' => null,
                'status' => 'no_record',
            ];

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
            'weight_start_recorded_on' => $weightStartRecordedOn,
            'weight_end_kg' => $weightEnd,
            'weight_end_recorded_on' => $weightEndRecordedOn,
            // 直近 = 期間内で最も新しい記録（weight_end と同値）
            'weight_latest_kg' => $weightEnd,
            'weight_latest_recorded_on' => $weightEndRecordedOn,
            'weight_delta_kg' => $weightDelta,
            'weight_record_days' => count($weights),
            'weight_on_period_start' => $weightOnPeriodStart,
            'weight_on_period_end' => $weightOnPeriodEnd,
            'notes' => [
                'weight_start_kg is the earliest recorded weight inside the period; use weight_start_recorded_on for its real date',
                'weight_end_kg / weight_latest_kg is the latest (直近) recorded weight inside the period; use weight_latest_recorded_on',
                'Do not treat weight_start_kg as the weight on period_start unless weight_on_period_start.status=recorded',
                'status=no_record means no weight entry on that calendar date',
            ],
            'steps_total' => $stepsTotal,
            'steps_recorded_days' => $stepsDays,
            'avg_steps_on_recorded_days' => $stepsDays > 0 ? (int) round($stepsTotal / $stepsDays) : null,
            'exercise_burned_kcal_total' => $exerciseTotal,
            'exercise_recorded_days' => $exerciseDays,
        ];
    }

    /** @deprecated use buildPeriodSummary */
    public function buildSummary30d(
        string $startDate,
        string $endDate,
        array $mealRows,
        array $weightByDate,
        array $stepsCountByDate,
        array $exerciseKcalByDate,
    ): array {
        return $this->buildPeriodSummary(
            $startDate,
            $endDate,
            $mealRows,
            $weightByDate,
            $stepsCountByDate,
            $exerciseKcalByDate,
        );
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
     * @param array<string, mixed>|null $evidence
     */
    private function formatText(
        RecordQueryScope $scope,
        array $dailyRecords,
        array $profileSnapshot,
        ?array $evidence = null,
    ): string {
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
        if ($evidence !== null) {
            $lines[] = '';
            $lines[] = '■ 記録状態・証拠';
            $lines[] = 'meal_record_meta.day_completion: '
                . (string) ($evidence['meal_record_meta']['day_completion'] ?? 'unknown');
            $lines[] = 'pfc_evidence.status: ' . (string) ($evidence['pfc_evidence']['status'] ?? 'none');
            $lines[] = 'weight_evidence.trend_status: '
                . (string) ($evidence['weight_evidence']['trend_status'] ?? 'insufficient_data');
        }
        $lines[] = '';
        $lines[] = '■ 日次記録（scope_records）';

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
     * @param array<string, mixed> $recordingMeta
     * @param array<string, mixed> $todayDetail
     * @param list<array<string, mixed>> $recent7d
     * @param list<array<string, mixed>> $recent8to14d
     * @param array<string, mixed> $summary30d
     * @param array<string, mixed> $summary6m
     * @param array<string, mixed> $profileSnapshot
     * @param array<string, mixed> $evidence
     * @param list<array<string, mixed>> $scopeRecords
     */
    private function formatLayeredText(
        RecordQueryScope $scope,
        string $primaryFocus,
        string $layerGuidance,
        array $recordingMeta,
        array $todayDetail,
        array $recent7d,
        array $recent8to14d,
        array $summary30d,
        array $summary6m,
        array $profileSnapshot,
        array $evidence = [],
        array $scopeRecords = [],
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
        $lines[] = '■ recording_meta（記録開始時期）';
        $lines[] = sprintf(
            'first_any_recorded_on: %s / days_since_first_record: %s / meal: %s / weight: %s',
            (string) ($recordingMeta['first_any_recorded_on'] ?? 'なし'),
            $recordingMeta['days_since_first_record'] === null
                ? 'なし'
                : (string) $recordingMeta['days_since_first_record'],
            (string) ($recordingMeta['first_meal_recorded_on'] ?? 'なし'),
            (string) ($recordingMeta['first_weight_recorded_on'] ?? 'なし'),
        );
        $lines[] = '';
        $lines[] = '■ プロフィール・目標（登録値）';
        foreach ($profileSnapshot as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $lines[] = '- ' . $key . ': ' . ($value === null || $value === '' ? '未設定' : (string) $value);
            }
        }
        $lines[] = '';
        $lines[] = '■ 記録状態・証拠';
        $lines[] = 'meal_entry_count: ' . (string) ($evidence['meal_entry_count'] ?? 0);
        $lines[] = 'day_completion: ' . (string) ($evidence['meal_record_meta']['day_completion'] ?? 'unknown');
        $lines[] = 'pfc_status: ' . (string) ($evidence['pfc_evidence']['status'] ?? 'none');
        $lines[] = 'registered_pfc_entry_count: '
            . (string) ($evidence['pfc_evidence']['registered_pfc_entry_count'] ?? 0);
        $lines[] = 'weight_trend_status: '
            . (string) ($evidence['weight_evidence']['trend_status'] ?? 'insufficient_data');
        $lines[] = 'tdee_status: ' . (string) ($evidence['energy_evidence']['tdee_status'] ?? 'unavailable');
        $lines[] = '';
        $lines[] = '■ scope_records（質問対象期間・主根拠 / ' . count($scopeRecords) . '日）';
        foreach ($scopeRecords as $day) {
            $lines[] = sprintf(
                '- %s: %s / %dkcal / meals=%d',
                (string) ($day['date'] ?? ''),
                (string) ($day['record_status'] ?? 'no_record'),
                (int) ($day['total_calories'] ?? 0),
                count($day['meals'] ?? []),
            );
        }
        $lines[] = '';
        $lines[] = '■ today_detail（' . (string) ($todayDetail['date'] ?? '') . '）';
        $lines[] = '食事合計: ' . (int) ($todayDetail['total_calories'] ?? 0) . 'kcal / status='
            . (string) ($todayDetail['record_status'] ?? 'no_record');
        $lines[] = '';
        $lines[] = '■ recent_7d（' . count($recent7d) . '日・日次明細）';
        foreach ($recent7d as $day) {
            $lines[] = sprintf(
                '- %s: %s / %dkcal',
                (string) ($day['date'] ?? ''),
                (string) ($day['record_status'] ?? 'no_record'),
                (int) ($day['total_calories'] ?? 0),
            );
        }
        $lines[] = '';
        $lines[] = '■ recent_8_14d（' . count($recent8to14d) . '日・8〜14日前の日次明細）';
        foreach ($recent8to14d as $day) {
            $lines[] = sprintf(
                '- %s: %s / %dkcal',
                (string) ($day['date'] ?? ''),
                (string) ($day['record_status'] ?? 'no_record'),
                (int) ($day['total_calories'] ?? 0),
            );
        }
        $lines[] = '';
        $lines[] = '■ summary_30d';
        $lines[] = $this->formatSummaryLine($summary30d);
        $lines[] = '';
        $lines[] = '■ summary_6m';
        $lines[] = $this->formatSummaryLine($summary6m);

        return implode("\n", $lines);
    }

    /**
     * @param array<string, float|null> $weightByDate
     * @return array<string, float>
     */
    private function latestWeights(array $weightByDate, int $limit): array
    {
        $weights = [];
        foreach ($weightByDate as $date => $value) {
            if ($value === null || !is_numeric($value)) {
                continue;
            }
            $weights[(string) $date] = (float) $value;
        }
        ksort($weights);
        if (count($weights) <= $limit) {
            return $weights;
        }

        return array_slice($weights, -$limit, null, true);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function formatSummaryLine(array $summary): string
    {
        $startOn = $summary['weight_start_recorded_on'] ?? null;
        $endOn = $summary['weight_end_recorded_on'] ?? null;
        $periodStartStatus = is_array($summary['weight_on_period_start'] ?? null)
            ? (string) ($summary['weight_on_period_start']['status'] ?? 'no_record')
            : 'no_record';
        $periodEndStatus = is_array($summary['weight_on_period_end'] ?? null)
            ? (string) ($summary['weight_on_period_end']['status'] ?? 'no_record')
            : 'no_record';

        return sprintf(
            '期間: %s 〜 %s / 食事記録日数: %s / 平均摂取: %s / 体重変化: %s'
            . ' / 最初の体重記録日: %s / 最後の体重記録日: %s'
            . ' / period_start体重: %s / period_end体重: %s',
            (string) ($summary['period_start'] ?? ''),
            (string) ($summary['period_end'] ?? ''),
            (string) ($summary['days_with_meals'] ?? '0'),
            $summary['avg_intake_kcal_on_recorded_days'] === null
                ? 'なし'
                : $summary['avg_intake_kcal_on_recorded_days'] . 'kcal',
            $summary['weight_delta_kg'] === null
                ? 'なし'
                : $summary['weight_delta_kg'] . 'kg',
            $startOn === null ? 'なし' : (string) $startOn,
            $endOn === null ? 'なし' : (string) $endOn,
            $periodStartStatus === 'recorded'
                ? (string) ($summary['weight_on_period_start']['kg'] ?? '') . 'kg'
                : 'なし(no_record)',
            $periodEndStatus === 'recorded'
                ? (string) ($summary['weight_on_period_end']['kg'] ?? '') . 'kg'
                : 'なし(no_record)',
        );
    }
}
