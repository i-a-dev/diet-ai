<?php

declare(strict_types=1);

/**
 * 対象期間の正式記録から、PFC・エネルギー・体重の証拠状態と回答可能範囲を構築する。
 * 食品分類や栄養知識は持たず、データの有無・正式値・断定可否のみを整理する。
 * BMR比較による「太る／痩せる」判定値は生成しない。
 */
final class DietAnswerEvidenceBuilder
{
    /** 体重傾向を「短期間」とみなす上限日数（この日数以下は fat change 断定不可） */
    private const SHORT_PERIOD_MAX_SPAN_DAYS = 13;

    /**
     * @param list<array<string, mixed>> $scopeDailyRecords buildDailyRecordsForRange の records
     * @param array<string, float|null> $weightByDate 対象期間（および傾向判定用）の体重
     * @param array<string, mixed> $profileSnapshot
     * @return array<string, mixed>
     */
    public function build(
        RecordQueryScope $scope,
        array $scopeDailyRecords,
        array $weightByDate,
        array $profileSnapshot,
    ): array {
        $mealEntries = [];
        $registeredIntakeKcal = 0;
        $namedMealCount = 0;

        foreach ($scopeDailyRecords as $day) {
            foreach ($day['meals'] ?? [] as $meal) {
                if (!is_array($meal)) {
                    continue;
                }
                $mealEntries[] = $meal;
                $registeredIntakeKcal += (int) ($meal['calories'] ?? 0);
                $foodName = trim((string) ($meal['food_name'] ?? ''));
                if ($foodName !== '') {
                    $namedMealCount++;
                }
            }
        }

        $mealEntryCount = count($mealEntries);
        $mealRecordMeta = [
            'has_entries' => $mealEntryCount > 0,
            'entry_count' => $mealEntryCount,
            'day_completion' => 'unknown',
            'note' => 'アプリに食事完了フラグはないため、記録があっても1日分すべてが登録済みとは断定できない',
        ];

        $goal = is_numeric($profileSnapshot['daily_intake_goal_kcal'] ?? null)
            ? (int) $profileSnapshot['daily_intake_goal_kcal']
            : null;
        $tdee = is_numeric($profileSnapshot['tdee_kcal'] ?? null)
            ? (int) $profileSnapshot['tdee_kcal']
            : null;
        $bmr = is_numeric($profileSnapshot['bmr_kcal'] ?? null)
            ? (int) $profileSnapshot['bmr_kcal']
            : null;

        $pfcEvidence = $this->buildPfcEvidence($mealEntries);
        $weightEvidence = $this->buildWeightEvidence($weightByDate, $profileSnapshot);
        $dailyEnergyEvidence = $this->buildDailyEnergyEvidence($scopeDailyRecords, $goal, $tdee);
        $energyEvidence = $this->buildEnergyEvidence(
            $registeredIntakeKcal,
            $scopeDailyRecords,
            $goal,
            $tdee,
            $mealRecordMeta['day_completion'],
        );
        $bmrReference = $this->buildBmrReference($bmr);
        $answerPermissions = $this->buildAnswerPermissions(
            $mealEntryCount,
            $namedMealCount,
            $registeredIntakeKcal,
            $goal,
            $tdee,
            $mealRecordMeta['day_completion'],
            $weightEvidence,
        );

        // LLM向け比較は goal / TDEE のみ（BMR比較は含めない）
        $numericComparisons = [
            'registered_average_vs_goal' => $energyEvidence['registered_average_vs_goal']['status'] ?? 'unavailable',
            'registered_average_vs_estimated_tdee' => $energyEvidence['registered_average_vs_estimated_tdee']['status']
                ?? 'unavailable',
        ];

        return [
            'meal_record_meta' => $mealRecordMeta,
            'pfc_evidence' => $pfcEvidence,
            'energy_evidence' => $energyEvidence,
            'daily_energy_evidence' => $dailyEnergyEvidence,
            'bmr_reference' => $bmrReference,
            'numeric_comparisons' => $numericComparisons,
            'weight_evidence' => $weightEvidence,
            'answer_permissions' => $answerPermissions,
            'scope_meal_entries' => $mealEntries,
            'registered_intake_kcal' => $registeredIntakeKcal,
            'meal_entry_count' => $mealEntryCount,
            'query_scope' => $scope->toArray(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $mealEntries
     * @return array<string, mixed>
     */
    private function buildPfcEvidence(array $mealEntries): array
    {
        $mealEntryCount = count($mealEntries);
        $registeredPfcEntryCount = 0;
        $protein = 0.0;
        $fat = 0.0;
        $carbs = 0.0;
        $hasAnyRegistered = false;

        foreach ($mealEntries as $meal) {
            if (!$this->mealHasRegisteredPfc($meal)) {
                continue;
            }
            $registeredPfcEntryCount++;
            $hasAnyRegistered = true;
            if (is_numeric($meal['protein_g'] ?? null)) {
                $protein += (float) $meal['protein_g'];
            }
            if (is_numeric($meal['fat_g'] ?? null)) {
                $fat += (float) $meal['fat_g'];
            }
            if (is_numeric($meal['carbs_g'] ?? null)) {
                $carbs += (float) $meal['carbs_g'];
            }
        }

        $status = 'none';
        if ($mealEntryCount > 0 && $registeredPfcEntryCount === $mealEntryCount) {
            $status = 'complete';
        } elseif ($registeredPfcEntryCount > 0) {
            $status = 'partial';
        }

        $note = match ($status) {
            'complete' => '対象期間の全食事に登録PFCがある。登録合計を期間内の登録値として扱ってよい',
            'partial' => '一部の食事にのみ登録PFCがある。registered_totals は部分合計であり、期間全体のPFC総量ではない',
            default => '登録PFCがない。食品名などから参考推定する場合は推定である旨を明示すること',
        };

        return [
            'status' => $status,
            'meal_entry_count' => $mealEntryCount,
            'registered_pfc_entry_count' => $registeredPfcEntryCount,
            'registered_totals' => $hasAnyRegistered
                ? [
                    'protein_g' => round($protein, 1),
                    'fat_g' => round($fat, 1),
                    'carbs_g' => round($carbs, 1),
                ]
                : [
                    'protein_g' => null,
                    'fat_g' => null,
                    'carbs_g' => null,
                ],
            'may_estimate_missing_from_foods' => $mealEntryCount > 0,
            'note' => $note,
        ];
    }

    /**
     * @param array<string, mixed> $meal
     */
    private function mealHasRegisteredPfc(array $meal): bool
    {
        return is_numeric($meal['protein_g'] ?? null)
            || is_numeric($meal['fat_g'] ?? null)
            || is_numeric($meal['carbs_g'] ?? null);
    }

    /**
     * @param array<string, float|null> $weightByDate
     * @param array<string, mixed> $profileSnapshot
     * @return array<string, mixed>
     */
    private function buildWeightEvidence(array $weightByDate, array $profileSnapshot): array
    {
        $weights = [];
        foreach ($weightByDate as $date => $value) {
            if ($value === null || !is_numeric($value)) {
                continue;
            }
            $weights[(string) $date] = (float) $value;
        }
        ksort($weights);

        $dates = array_keys($weights);
        $recordCount = count($dates);
        $targetWeight = is_numeric($profileSnapshot['target_weight_kg'] ?? null)
            ? (float) $profileSnapshot['target_weight_kg']
            : null;

        if ($recordCount === 0) {
            return [
                'record_count' => 0,
                'trend_status' => 'insufficient_data',
                'trend_direction' => 'unknown',
                'first_recorded_on' => null,
                'latest_recorded_on' => null,
                'first_weight_kg' => null,
                'latest_weight_kg' => null,
                'change_kg' => null,
                'target_weight_kg' => $targetWeight,
                'remaining_to_target_kg' => null,
                'can_compute_remaining_to_target' => false,
                'may_assert_fat_change' => false,
                'note' => '体重記録がないため、目標体重との差や傾向は計算不能。脂肪増減は断定しない',
            ];
        }

        $firstDate = $dates[0];
        $latestDate = $dates[$recordCount - 1];
        $firstWeight = $weights[$firstDate];
        $latestWeight = $weights[$latestDate];
        $changeKg = round($latestWeight - $firstWeight, 2);

        $spanDays = 0;
        $firstDt = DateTimeImmutable::createFromFormat('Y-m-d', $firstDate);
        $latestDt = DateTimeImmutable::createFromFormat('Y-m-d', $latestDate);
        if ($firstDt instanceof DateTimeImmutable && $latestDt instanceof DateTimeImmutable) {
            $spanDays = (int) $firstDt->diff($latestDt)->format('%a');
        }

        if ($recordCount < 2 || $spanDays < 2) {
            $trendStatus = 'insufficient_data';
        } elseif ($spanDays <= self::SHORT_PERIOD_MAX_SPAN_DAYS) {
            $trendStatus = 'short_period';
        } else {
            $trendStatus = 'available';
        }

        $trendDirection = 'unknown';
        if ($trendStatus === 'available' || $trendStatus === 'short_period') {
            if ($changeKg < -0.05) {
                $trendDirection = 'decreasing';
            } elseif ($changeKg > 0.05) {
                $trendDirection = 'increasing';
            } else {
                $trendDirection = 'stable';
            }
        }

        $remaining = null;
        $canComputeRemaining = false;
        if ($targetWeight !== null) {
            $remaining = round($latestWeight - $targetWeight, 2);
            $canComputeRemaining = true;
        }

        return [
            'record_count' => $recordCount,
            'first_recorded_on' => $firstDate,
            'latest_recorded_on' => $latestDate,
            'first_weight_kg' => $firstWeight,
            'latest_weight_kg' => $latestWeight,
            'change_kg' => $changeKg,
            'span_days' => $spanDays,
            'trend_status' => $trendStatus,
            'trend_direction' => $trendDirection,
            'target_weight_kg' => $targetWeight,
            'remaining_to_target_kg' => $remaining,
            'can_compute_remaining_to_target' => $canComputeRemaining,
            'may_assert_fat_change' => false,
            'note' => 'change_kg は記録上の客観差分。短期間でも脂肪増減・カロリー赤字の断定には使わない',
        ];
    }

    /**
     * @param list<array<string, mixed>> $scopeDailyRecords
     * @return array<string, mixed>
     */
    private function buildEnergyEvidence(
        int $registeredIntakeKcal,
        array $scopeDailyRecords,
        ?int $goal,
        ?int $tdee,
        string $dayCompletion,
    ): array {
        $daysWithMeals = 0;
        foreach ($scopeDailyRecords as $day) {
            if (($day['record_status'] ?? '') === 'recorded' || (($day['meals'] ?? []) !== [])) {
                $daysWithMeals++;
            }
        }
        $avgOnRecordedDays = $daysWithMeals > 0
            ? (int) round($registeredIntakeKcal / $daysWithMeals)
            : null;

        $vsGoal = $this->buildComparisonBlock($avgOnRecordedDays, $goal);
        $vsTdee = $this->buildComparisonBlock($avgOnRecordedDays, $tdee);

        return [
            'registered_intake_total_kcal' => $registeredIntakeKcal,
            'registered_intake_average_kcal' => $avgOnRecordedDays,
            // 後方互換エイリアス
            'registered_intake_kcal' => $registeredIntakeKcal,
            'days_with_meals' => $daysWithMeals,
            'registered_avg_intake_kcal_on_days_with_meals' => $avgOnRecordedDays,
            'daily_intake_goal_kcal' => $goal,
            'estimated_tdee_kcal' => $tdee,
            'tdee_status' => $tdee !== null ? 'available' : 'unavailable',
            'tdee_is_estimated' => true,
            'meal_completion' => $dayCompletion,
            'meal_day_completion' => $dayCompletion,
            'registered_average_vs_goal' => $vsGoal,
            'registered_average_vs_estimated_tdee' => $vsTdee,
            'may_compare_with_goal' => $registeredIntakeKcal > 0 && $goal !== null,
            'may_compare_with_estimated_tdee' => $registeredIntakeKcal > 0 && $tdee !== null,
            'may_estimate_energy_balance' => false,
            'may_assert_fat_loss' => false,
            'note' => '比較は目標摂取カロリーと推定TDEEのみ。BMR比較フィールドは含めない。'
                . 'day_completion=unknown のため差分合計を確定赤字・余剰として扱わない。'
                . 'PHPは fat_loss / gain / lose_weight などの判定ラベルを生成しない。',
        ];
    }

    /**
     * @param list<array<string, mixed>> $scopeDailyRecords
     * @return list<array<string, mixed>>
     */
    private function buildDailyEnergyEvidence(
        array $scopeDailyRecords,
        ?int $goal,
        ?int $tdee,
    ): array {
        $rows = [];
        foreach ($scopeDailyRecords as $day) {
            $hasMeals = ($day['record_status'] ?? '') === 'recorded'
                || (($day['meals'] ?? []) !== []);
            if (!$hasMeals) {
                continue;
            }

            $intake = (int) ($day['total_calories'] ?? 0);
            $vsGoal = $this->buildComparisonBlock($intake, $goal);
            $vsTdee = $this->buildComparisonBlock($intake, $tdee);

            $rows[] = [
                'date' => (string) ($day['date'] ?? ''),
                'registered_intake_kcal' => $intake,
                'daily_intake_goal_kcal' => $goal,
                'difference_vs_goal_kcal' => $vsGoal['difference_kcal'],
                'intake_vs_goal' => $vsGoal['status'],
                'estimated_tdee_kcal' => $tdee,
                'difference_vs_estimated_tdee_kcal' => $vsTdee['difference_kcal'],
                'intake_vs_estimated_tdee' => $vsTdee['status'],
                'meal_completion' => 'unknown',
            ];
        }

        return $rows;
    }

    /**
     * @return array{difference_kcal: int|null, status: string}
     */
    private function buildComparisonBlock(?int $left, ?int $right): array
    {
        if ($left === null || $right === null) {
            return [
                'difference_kcal' => null,
                'status' => 'unavailable',
            ];
        }

        $diff = $left - $right;
        $status = 'equal';
        if ($diff > 0) {
            $status = 'above';
        } elseif ($diff < 0) {
            $status = 'below';
        }

        return [
            'difference_kcal' => $diff,
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBmrReference(?int $bmr): array
    {
        return [
            'bmr_kcal' => $bmr,
            'allowed_uses' => [
                'low_intake_safety_reference',
                'calorie_goal_calculation',
            ],
            'prohibited_uses' => [
                'gain_loss_classification',
                'energy_balance_estimation',
                'daily_weight_prediction',
                'daily_gain_loss_label',
            ],
            'note' => 'BMRは安静時の推定基礎代謝であり1日の総消費ではない。'
                . '太る／痩せる判定、エネルギー収支判定、日別増減ラベルには使用禁止。',
        ];
    }

    /**
     * @param array<string, mixed> $weightEvidence
     * @return array<string, bool>
     */
    private function buildAnswerPermissions(
        int $mealEntryCount,
        int $namedMealCount,
        int $registeredIntakeKcal,
        ?int $goal,
        ?int $tdee,
        string $dayCompletion,
        array $weightEvidence,
    ): array {
        $hasIntake = $registeredIntakeKcal > 0;
        $completionUnknown = $dayCompletion === 'unknown';
        $trendStatus = (string) ($weightEvidence['trend_status'] ?? 'insufficient_data');

        return [
            'may_estimate_pfc_from_foods' => $mealEntryCount > 0,
            'may_comment_on_meal_composition' => $namedMealCount > 0,
            'may_compare_registered_intake_with_goal' => $hasIntake && $goal !== null,
            'may_compare_registered_intake_with_estimated_tdee' => $hasIntake && $tdee !== null,
            'may_estimate_energy_balance' => false,
            'may_use_bmr_for_gain_loss' => false,
            'may_label_daily_intake_as_gain_or_loss' => false,
            'may_assert_energy_deficit' => !$completionUnknown && false,
            'may_assert_energy_surplus' => !$completionUnknown && false,
            'may_assert_fat_loss' => false,
            'may_assert_fat_gain' => false,
            'may_predict_next_day_weight' => false,
            'may_evaluate_weight_trend' => $trendStatus === 'available',
            'may_compute_remaining_to_target' => (bool) ($weightEvidence['can_compute_remaining_to_target'] ?? false),
        ];
    }
}
