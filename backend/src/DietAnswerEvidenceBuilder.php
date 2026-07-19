<?php

declare(strict_types=1);

/**
 * 対象期間の正式記録から、PFC・エネルギー・体重の証拠状態と回答可能範囲を構築する。
 * 食品分類や栄養知識は持たず、データの有無・正式値・断定可否のみを整理する。
 */
final class DietAnswerEvidenceBuilder
{
    /**
     * @param list<array<string, mixed>> $scopeDailyRecords buildDailyRecordsForRange の records
     * @param array<string, float|null> $weightByDate 対象期間（および傾向判定用）の体重
     * @param array<string, mixed> $profileSnapshot
     * @return array{
     *   meal_record_meta: array<string, mixed>,
     *   pfc_evidence: array<string, mixed>,
     *   energy_evidence: array<string, mixed>,
     *   weight_evidence: array<string, mixed>,
     *   answer_permissions: array<string, bool>,
     *   scope_meal_entries: list<array<string, mixed>>,
     *   registered_intake_kcal: int,
     *   meal_entry_count: int
     * }
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

        $pfcEvidence = $this->buildPfcEvidence($mealEntries);
        $weightEvidence = $this->buildWeightEvidence($weightByDate, $profileSnapshot);
        $energyEvidence = $this->buildEnergyEvidence(
            $registeredIntakeKcal,
            $scopeDailyRecords,
            $profileSnapshot,
            $mealRecordMeta['day_completion'],
        );
        $answerPermissions = $this->buildAnswerPermissions(
            $mealEntryCount,
            $namedMealCount,
            $energyEvidence,
            $weightEvidence,
        );

        return [
            'meal_record_meta' => $mealRecordMeta,
            'pfc_evidence' => $pfcEvidence,
            'energy_evidence' => $energyEvidence,
            'numeric_comparisons' => $energyEvidence['comparisons'] ?? [],
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
                'note' => '体重記録がないため、目標体重との差や傾向は計算不能',
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

        $trendStatus = ($recordCount >= 2 && $spanDays >= 2)
            ? 'available'
            : 'insufficient_data';
        $trendDirection = 'unknown';
        if ($trendStatus === 'available') {
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
            'trend_status' => $trendStatus,
            'trend_direction' => $trendDirection,
            'target_weight_kg' => $targetWeight,
            'remaining_to_target_kg' => $remaining,
            'can_compute_remaining_to_target' => $canComputeRemaining,
            'note' => 'change_kg は記録上の差分であり、脂肪増減の断定には使わない',
        ];
    }

    /**
     * @param list<array<string, mixed>> $scopeDailyRecords
     * @param array<string, mixed> $profileSnapshot
     * @return array<string, mixed>
     */
    private function buildEnergyEvidence(
        int $registeredIntakeKcal,
        array $scopeDailyRecords,
        array $profileSnapshot,
        string $dayCompletion,
    ): array {
        $goal = is_numeric($profileSnapshot['daily_intake_goal_kcal'] ?? null)
            ? (int) $profileSnapshot['daily_intake_goal_kcal']
            : null;
        $tdee = is_numeric($profileSnapshot['tdee_kcal'] ?? null)
            ? (int) $profileSnapshot['tdee_kcal']
            : null;
        $bmr = is_numeric($profileSnapshot['bmr_kcal'] ?? null)
            ? (int) $profileSnapshot['bmr_kcal']
            : null;
        $tdeeStatus = $tdee !== null ? 'available' : 'unavailable';

        $daysWithMeals = 0;
        foreach ($scopeDailyRecords as $day) {
            if (($day['record_status'] ?? '') === 'recorded' || (($day['meals'] ?? []) !== [])) {
                $daysWithMeals++;
            }
        }
        $avgOnRecordedDays = $daysWithMeals > 0
            ? (int) round($registeredIntakeKcal / $daysWithMeals)
            : null;

        $comparisons = [
            'registered_avg_vs_bmr' => $this->compareNullableInts($avgOnRecordedDays, $bmr),
            'registered_avg_vs_tdee' => $this->compareNullableInts($avgOnRecordedDays, $tdee),
            'registered_avg_vs_goal' => $this->compareNullableInts($avgOnRecordedDays, $goal),
            'registered_total_vs_goal' => $this->compareNullableInts(
                $registeredIntakeKcal > 0 ? $registeredIntakeKcal : null,
                $goal,
            ),
        ];

        $mayCompareWithGoal = $registeredIntakeKcal > 0 && $goal !== null;
        // 食事完了が不明なため、確定的なエネルギー収支判定は許可しない
        $mayEstimateEnergyBalance = false;

        return [
            'registered_intake_kcal' => $registeredIntakeKcal,
            'days_with_meals' => $daysWithMeals,
            'registered_avg_intake_kcal_on_days_with_meals' => $avgOnRecordedDays,
            'bmr_kcal' => $bmr,
            'daily_intake_goal_kcal' => $goal,
            'estimated_tdee_kcal' => $tdee,
            'tdee_status' => $tdeeStatus,
            'meal_day_completion' => $dayCompletion,
            'may_compare_with_goal' => $mayCompareWithGoal,
            'may_estimate_energy_balance' => $mayEstimateEnergyBalance,
            'may_assert_fat_loss' => false,
            'comparisons' => $comparisons,
            'comparison_labels_ja' => [
                'above' => '上回る',
                'below' => '下回る',
                'equal' => 'ほぼ同じ',
                'unavailable' => '比較不能',
            ],
            'note' => '登録カロリーと目標/BMR/TDEEの大小は comparisons を正とする。'
                . '平均値は「食事記録がある日の登録カロリー平均」であり、実摂取が基礎代謝未満とは断定しない。'
                . '食事完了不明のため確定赤字・脂肪減少の断定は不可',
        ];
    }

    /**
     * @return 'above'|'below'|'equal'|'unavailable'
     */
    private function compareNullableInts(?int $left, ?int $right): string
    {
        if ($left === null || $right === null) {
            return 'unavailable';
        }
        if ($left > $right) {
            return 'above';
        }
        if ($left < $right) {
            return 'below';
        }

        return 'equal';
    }

    /**
     * @param array<string, mixed> $energyEvidence
     * @param array<string, mixed> $weightEvidence
     * @return array<string, bool>
     */
    private function buildAnswerPermissions(
        int $mealEntryCount,
        int $namedMealCount,
        array $energyEvidence,
        array $weightEvidence,
    ): array {
        return [
            'may_estimate_pfc_from_foods' => $mealEntryCount > 0,
            'may_comment_on_meal_composition' => $namedMealCount > 0,
            'may_compare_registered_intake_with_goal' => (bool) ($energyEvidence['may_compare_with_goal'] ?? false),
            'may_estimate_energy_balance' => false,
            'may_evaluate_weight_trend' => ($weightEvidence['trend_status'] ?? '') === 'available',
            'may_assert_fat_loss' => false,
            'may_predict_next_day_weight' => false,
            'may_compute_remaining_to_target' => (bool) ($weightEvidence['can_compute_remaining_to_target'] ?? false),
        ];
    }
}
