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
        $energy = is_array($authoritative['energy_evidence'] ?? null) ? $authoritative['energy_evidence'] : [];
        $weight = is_array($authoritative['weight_evidence'] ?? null) ? $authoritative['weight_evidence'] : [];
        $bmrRef = is_array($authoritative['bmr_reference'] ?? null) ? $authoritative['bmr_reference'] : [];
        $dailyEnergy = is_array($authoritative['daily_energy_evidence'] ?? null)
            ? $authoritative['daily_energy_evidence']
            : [];
        $mealMeta = is_array($authoritative['meal_record_meta'] ?? null)
            ? $authoritative['meal_record_meta']
            : [];
        $perms = is_array($authoritative['answer_permissions'] ?? null)
            ? $authoritative['answer_permissions']
            : [];

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

        $blocks[] = '【正式な食事記録】';
        $blocks[] = '以下の JSON のみが食事・体重・kcal・登録PFCなどの正式事実です。会話履歴の食品名や過去assistantの要約は使わないでください。';
        $blocks[] = '食品明細（food_name / amount / unit / serving_* / calories / registered PFC）はPFC参考推定に必要なので必ず参照してください。';
        if (isset($authoritative['primary_focus'], $authoritative['layer_guidance'])) {
            $blocks[] = 'primary_focus: ' . (string) $authoritative['primary_focus'];
            $blocks[] = 'layer_guidance: ' . (string) $authoritative['layer_guidance'];
        }
        $blocks[] = (string) ($authoritative['json'] ?? '{}');
        $blocks[] = '';

        $blocks[] = '【食事記録の完全性】';
        $blocks[] = sprintf(
            'has_entries=%s / entry_count=%s / day_completion=%s',
            !empty($mealMeta['has_entries']) ? 'true' : 'false',
            (string) ($mealMeta['entry_count'] ?? ($authoritative['meal_count'] ?? 0)),
            (string) ($mealMeta['day_completion'] ?? 'unknown'),
        );
        $blocks[] = 'day_completion=unknown のときは「登録された範囲では」「記録分は」を使い、確定的な赤字・余剰・総摂取量として断定しないでください。';
        $blocks[] = '日別差分を合計して「6日間で○kcalオーバー」などと確定説明しないでください。';
        $blocks[] = '';

        $blocks[] = '【目標摂取カロリーとの比較】';
        $vsGoal = is_array($energy['registered_average_vs_goal'] ?? null)
            ? $energy['registered_average_vs_goal']
            : [];
        $blocks[] = sprintf(
            'registered_intake_average_kcal=%s / daily_intake_goal_kcal=%s / status=%s / difference_kcal=%s',
            $energy['registered_intake_average_kcal'] === null
                ? 'null'
                : (string) $energy['registered_intake_average_kcal'],
            $energy['daily_intake_goal_kcal'] === null
                ? 'null'
                : (string) $energy['daily_intake_goal_kcal'],
            (string) ($vsGoal['status'] ?? 'unavailable'),
            $vsGoal['difference_kcal'] === null ? 'null' : (string) $vsGoal['difference_kcal'],
        );
        $blocks[] = '目標内かどうかの参考。実際のカロリー赤字・余剰の断定ではない。';
        $blocks[] = '';

        $blocks[] = '【推定TDEEとの比較】';
        $vsTdee = is_array($energy['registered_average_vs_estimated_tdee'] ?? null)
            ? $energy['registered_average_vs_estimated_tdee']
            : [];
        $blocks[] = sprintf(
            'estimated_tdee_kcal=%s / tdee_is_estimated=%s / status=%s / difference_kcal=%s',
            $energy['estimated_tdee_kcal'] === null
                ? 'null'
                : (string) $energy['estimated_tdee_kcal'],
            !empty($energy['tdee_is_estimated']) ? 'true' : 'false',
            (string) ($vsTdee['status'] ?? 'unavailable'),
            $vsTdee['difference_kcal'] === null ? 'null' : (string) $vsTdee['difference_kcal'],
        );
        if ($dailyEnergy !== []) {
            $blocks[] = 'daily_energy_evidence(日別・goal/TDEEのみ): '
                . (json_encode($dailyEnergy, JSON_UNESCAPED_UNICODE) ?: '[]');
        }
        $blocks[] = 'エネルギー収支の参考はTDEE比較のみ。日別に「太る」「痩せる」ラベルを付けない。';
        $blocks[] = '';

        $blocks[] = '【BMRの用途制限】';
        $blocks[] = sprintf(
            'bmr_kcal=%s',
            ($bmrRef['bmr_kcal'] ?? null) === null ? 'null' : (string) $bmrRef['bmr_kcal'],
        );
        $blocks[] = 'このBMRは太る／痩せる判定、エネルギー収支判定、日別の増減ラベルには使用禁止です。';
        $blocks[] = '許可用途: 摂取が極端に低すぎる注意、カロリー目標計算の過程。';
        if (isset($bmrRef['prohibited_uses']) && is_array($bmrRef['prohibited_uses'])) {
            $blocks[] = 'prohibited_uses: '
                . (json_encode($bmrRef['prohibited_uses'], JSON_UNESCAPED_UNICODE) ?: '[]');
        }
        $blocks[] = '禁止表: 日付 | 摂取kcal | BMRとの差 | 太る／痩せる';
        $blocks[] = '許可表: 日付 | 登録摂取kcal | 目標との差 / 推定TDEEとの差（太る・痩せるラベルなし）';
        $blocks[] = '';

        $blocks[] = '【体重推移】';
        $blocks[] = sprintf(
            'record_count=%s / trend_status=%s / change_kg=%s / may_assert_fat_change=%s',
            (string) ($weight['record_count'] ?? 0),
            (string) ($weight['trend_status'] ?? 'insufficient_data'),
            $weight['change_kg'] === null ? 'null' : (string) $weight['change_kg'],
            !empty($weight['may_assert_fat_change']) ? 'true' : 'false',
        );
        $blocks[] = '実際に痩せたか太ったかは体重推移を主な根拠にする。短期間の変化を脂肪増減と断定しない。';
        $blocks[] = '';

        $blocks[] = '【回答可能範囲】';
        $blocks[] = $perms !== []
            ? (json_encode($perms, JSON_UNESCAPED_UNICODE) ?: '{}')
            : '{}';
        $blocks[] = 'answer_permissions が false の項目は断定・ラベル付けしないでください。';
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
