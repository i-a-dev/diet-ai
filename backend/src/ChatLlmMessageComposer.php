<?php

declare(strict_types=1);

/**
 * LLM へ渡す最終ユーザーメッセージ（対象期間 + 正式記録 + 質問）を組み立てる。
 */
final class ChatLlmMessageComposer
{
    private const TIMEZONE = 'Asia/Tokyo';

    /**
     * @param array<string, mixed> $authoritative AuthoritativeRecordContextBuilder::build の戻り値
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
        $blocks[] = '【今回の質問対象】';
        $blocks[] = $scope->startDateString() . ' 〜 ' . $scope->endDateString()
            . '（' . $scope->type->value . ' / ' . $scope->originalExpression . '）';
        $blocks[] = '';
        $blocks[] = '【正式な記録データ authoritative_record_context】';
        $blocks[] = '以下の JSON のみが食事・体重・kcal などの正式事実です。会話履歴の食品名や過去assistantの要約は使わないでください。';
        $blocks[] = (string) ($authoritative['json'] ?? '{}');
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
        $lines[] = '【当日の未記録に関する時間帯ルール】';
        $lines[] = sprintf('現在日時: %s（%s）', $now->format('Y-m-d H:i'), self::TIMEZONE);

        if ($suppressTodayMissingRecordMention) {
            $lines[] = '現在は18:00前です。';
            $lines[] = '対象日が今日の場合、今日の食事・歩数・運動が未記録でも、原則として言及しないでください。';
            $lines[] = '「今日の記録がありません」「まだ記録されていません」「正確な分析ができません」などを定型的に出力しないでください。';
            $lines[] = 'ただし、ユーザーが未記録の項目そのものを質問しており、その記録が回答に不可欠な場合のみ、必要な未記録を簡潔に伝えてください。';
        } else {
            $lines[] = '現在は18:00以降です。';
            $lines[] = '時間帯による当日の未記録言及の抑制はありません。';
            $lines[] = 'ただし、未記録へ必ず言及する必要はありません。';
        }

        return implode("\n", $lines);
    }
}
