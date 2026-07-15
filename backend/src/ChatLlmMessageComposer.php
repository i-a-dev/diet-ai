<?php

declare(strict_types=1);

/**
 * LLM へ渡す最終ユーザーメッセージ（対象期間 + 正式記録 + 質問）を組み立てる。
 */
final class ChatLlmMessageComposer
{
    /**
     * @param array<string, mixed> $authoritative AuthoritativeRecordContextBuilder::build の戻り値
     */
    public function composeFinalUserMessage(
        string $userQuestion,
        RecordQueryScope $scope,
        array $authoritative,
        ?string $desiredDietMethod = null,
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
        $blocks[] = '【ユーザーの質問】';
        $blocks[] = $userQuestion;

        return implode("\n", $blocks);
    }
}
