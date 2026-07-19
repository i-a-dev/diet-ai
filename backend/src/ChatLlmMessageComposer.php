<?php

declare(strict_types=1);

/**
 * LLM へ渡す最終ユーザーメッセージ（対象期間 + 正式記録 + 質問）を組み立てる。
 */
final class ChatLlmMessageComposer
{
    private const TIMEZONE = 'Asia/Tokyo';

    /**
     * @param array<string, mixed> $authoritative AuthoritativeRecordContextBuilder の戻り値
     */
    public function composeFinalUserMessage(
        string $userQuestion,
        RecordQueryScope $scope,
        array $authoritative,
        ?string $desiredDietMethod = null,
        ?DateTimeImmutable $now = null,
        ?bool $suppressTodayMissingRecordMention = null,
    ): string {
        $blocks = [];
        $blocks[] = '【質問対象期間】';
        $blocks[] = sprintf(
            'scope_start_date=%s / scope_end_date=%s / scope_type=%s / scope_original_expression=%s',
            $scope->startDateString(),
            $scope->endDateString(),
            $scope->type->value,
            $scope->originalExpression,
        );
        $blocks[] = '回答の中心根拠は、この期間の scope_records 内の食事明細のみです。today_detail があっても対象期間外なら主根拠にしないでください。';
        $blocks[] = '';

        $blocks[] = '【正式な記録】';
        $blocks[] = '以下の JSON のみが食事・体重・kcal・登録PFCなどの正式事実です。会話履歴の食品名や過去assistantの要約は使わないでください。';
        $blocks[] = '食品明細（food_name / amount / unit / serving_* / calories / registered PFC）はPFC参考推定に必要なので必ず参照してください。';
        if (isset($authoritative['primary_focus'], $authoritative['layer_guidance'])) {
            $blocks[] = 'primary_focus: ' . (string) $authoritative['primary_focus'];
            $blocks[] = 'layer_guidance: ' . (string) $authoritative['layer_guidance'];
        }
        $blocks[] = (string) ($authoritative['json'] ?? '{}');
        $blocks[] = '';

        $blocks[] = '【記録状態】';
        $mealMeta = is_array($authoritative['meal_record_meta'] ?? null)
            ? $authoritative['meal_record_meta']
            : [];
        $blocks[] = sprintf(
            'has_entries=%s / entry_count=%s / day_completion=%s',
            !empty($mealMeta['has_entries']) ? 'true' : 'false',
            (string) ($mealMeta['entry_count'] ?? ($authoritative['meal_count'] ?? 0)),
            (string) ($mealMeta['day_completion'] ?? 'unknown'),
        );
        $blocks[] = 'day_completion=unknown のときは「登録された範囲では」「記録から確認できる範囲では」を使い、今日の総摂取量として断定しないでください。';
        $blocks[] = '';

        $blocks[] = '【PFCの証拠状態】';
        $pfc = is_array($authoritative['pfc_evidence'] ?? null) ? $authoritative['pfc_evidence'] : [];
        $blocks[] = sprintf(
            'status=%s / meal_entry_count=%s / registered_pfc_entry_count=%s / may_estimate_missing_from_foods=%s',
            (string) ($pfc['status'] ?? 'none'),
            (string) ($pfc['meal_entry_count'] ?? 0),
            (string) ($pfc['registered_pfc_entry_count'] ?? 0),
            !empty($pfc['may_estimate_missing_from_foods']) ? 'true' : 'false',
        );
        if (($pfc['status'] ?? '') === 'partial') {
            $blocks[] = 'registered_totals は部分合計です。1日または期間全体のPFC総量として扱わないでください。';
        }
        $blocks[] = '登録PFCは正式値、不足分の推定は参考推定（範囲表示）として区別してください。';
        $blocks[] = '';

        $blocks[] = '【体重・エネルギーの証拠状態】';
        $energy = is_array($authoritative['energy_evidence'] ?? null) ? $authoritative['energy_evidence'] : [];
        $weight = is_array($authoritative['weight_evidence'] ?? null) ? $authoritative['weight_evidence'] : [];
        $blocks[] = sprintf(
            'registered_intake_kcal=%s / daily_intake_goal_kcal=%s / tdee_status=%s / may_estimate_energy_balance=%s',
            (string) ($energy['registered_intake_kcal'] ?? ($authoritative['registered_intake_kcal'] ?? 0)),
            $energy['daily_intake_goal_kcal'] === null
                ? 'null'
                : (string) $energy['daily_intake_goal_kcal'],
            (string) ($energy['tdee_status'] ?? 'unavailable'),
            !empty($energy['may_estimate_energy_balance']) ? 'true' : 'false',
        );
        $blocks[] = sprintf(
            'weight_record_count=%s / trend_status=%s / can_compute_remaining_to_target=%s',
            (string) ($weight['record_count'] ?? 0),
            (string) ($weight['trend_status'] ?? 'insufficient_data'),
            !empty($weight['can_compute_remaining_to_target']) ? 'true' : 'false',
        );
        $blocks[] = '確定カロリー赤字・脂肪減少・翌日体重予測は断定しないでください。';
        $blocks[] = '';

        $blocks[] = '【回答可能範囲】';
        $perms = is_array($authoritative['answer_permissions'] ?? null)
            ? $authoritative['answer_permissions']
            : [];
        if ($perms !== []) {
            $blocks[] = json_encode($perms, JSON_UNESCAPED_UNICODE) ?: '{}';
        } else {
            $blocks[] = '{}';
        }
        $blocks[] = 'answer_permissions が false の項目は断定・推定しないでください。';
        $blocks[] = '';

        if ($desiredDietMethod !== null && trim($desiredDietMethod) !== '') {
            $blocks[] = '【参照用・プロフィール登録済み】やりたいダイエット方法: ' . trim($desiredDietMethod);
            $blocks[] = '';
        }
        if ($now !== null && $suppressTodayMissingRecordMention !== null) {
            $blocks[] = $this->buildTodayMissingRecordMentionRule($now, $suppressTodayMissingRecordMention);
            $blocks[] = '';
        }
        $blocks[] = '【ユーザーの質問】';
        $blocks[] = $userQuestion;

        return implode("\n", $blocks);
    }

    private function buildTodayMissingRecordMentionRule(
        DateTimeImmutable $now,
        bool $suppressTodayMissingRecordMention,
    ): string {
        $now = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
        $lines = [];
        $lines[] = '【時間帯による未記録言及ルール】';
        $lines[] = sprintf('現在日時: %s（%s）', $now->format('Y-m-d H:i'), self::TIMEZONE);

        if ($suppressTodayMissingRecordMention) {
            $lines[] = '現在は18:00前です。';
            $lines[] = '対象日が今日の場合、今日の食事・歩数・運動が未記録でも、原則として言及しないでください。';
            $lines[] = '「今日の記録がありません」「まだ記録されていません」「正確な分析ができません」などを定型的に出力しないでください。';
            $lines[] = 'ただし、ユーザーが未記録の項目そのものを質問しており、その記録が回答に不可欠な場合のみ、必要な未記録を簡潔に伝えてください。';
        } else {
            $lines[] = '現在は18:00以降です。';
            $lines[] = '時間帯による当日の未記録言及の抑制はありません。';
            $lines[] = 'ただし、未記録へ必ず言及する必要はありません。質問への回答に本当に必要な場合だけ、最後に短く補足してください。';
            $lines[] = '「未記録」と「記録不完全の可能性（day_completion=unknown）」を区別してください。';
        }

        return implode("\n", $lines);
    }
}
