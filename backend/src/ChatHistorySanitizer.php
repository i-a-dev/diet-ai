<?php

declare(strict_types=1);

/**
 * 会話履歴から「記録事実」を除外し、会話の流れ・希望・好みだけを残す。
 *
 * 正規表現で食品名を完全判定できる前提にはしない。
 * assistant の食事要約っぽいブロックは丸ごと無効化し、意図だけ残す。
 */
final class ChatHistorySanitizer
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{
     *   messages: array<int, array{role: string, content: string}>,
     *   history_count_before: int,
     *   history_count_after: int,
     *   excluded_count: int
     * }
     */
    public function sanitize(array $messages): array
    {
        $before = count($messages);
        $sanitized = [];
        $excluded = 0;

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                $excluded++;
                continue;
            }

            if ($role === 'assistant') {
                $result = $this->sanitizeAssistantMessage($content);
                if ($result['excluded']) {
                    $excluded++;
                }
                if ($result['content'] === '') {
                    continue;
                }
                $sanitized[] = [
                    'role' => 'assistant',
                    'content' => $result['content'],
                ];
                continue;
            }

            $sanitized[] = [
                'role' => 'user',
                'content' => $this->sanitizeUserMessage($content),
            ];
        }

        // 連続する同ロール、空除去後の整列
        $sanitized = $this->collapseRedundantSanitizedNotes($sanitized);

        return [
            'messages' => $sanitized,
            'history_count_before' => $before,
            'history_count_after' => count($sanitized),
            'excluded_count' => $excluded,
        ];
    }

    /**
     * @return array{content: string, excluded: bool}
     */
    private function sanitizeAssistantMessage(string $content): array
    {
        if (!$this->looksLikeRecordFactContent($content)) {
            return ['content' => $content, 'excluded' => false];
        }

        // 食事要約など記録事実を含む assistant 回答は事実ソースにしない。
        // 会話の流れだけ残す短い置換文に落とす。
        $intentBits = [];
        if (preg_match('/アドバイス|おすすめ|提案|メニュー|調整|血糖|タンパ[クク]質|カロリー目標/u', $content) === 1) {
            $intentBits[] = '栄養・食事に関する一般的なアドバイスや提案をした';
        }
        if (preg_match('/運動|トレーニング|歩数/u', $content) === 1) {
            $intentBits[] = '運動・活動に関する提案をした';
        }
        if (preg_match('/体重|減量|目標/u', $content) === 1) {
            $intentBits[] = '体重・目標に関する話をした';
        }

        $summary = $intentBits === []
            ? '前回は記録内容に触れる回答をしたが、食品名・kcal・体重などの数値事実はこの履歴から参照しないこと。'
            : implode('。', $intentBits)
                . '。食品名・量・kcal・体重などの数値事実はこの履歴から参照せず、authoritative_record_context のみを正とすること。';

        return [
            'content' => '【会話文脈のみ・記録事実なし】' . $summary,
            'excluded' => true,
        ];
    }

    private function sanitizeUserMessage(string $content): string
    {
        // ユーザー発話は意図・希望を残す。末尾のプロフィール注入などは維持。
        // 「昼はパスタ312kcal」のような事実断定行は弱め、質問意図を優先する。
        $lines = preg_split("/\n/u", $content) ?: [$content];
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if ($this->looksLikeStandaloneRecordFactLine($trimmed)) {
                $kept[] = '（ユーザーが過去の食事事実に言及したが、正式記録はDBのみを正とする）';
                continue;
            }
            $kept[] = $trimmed;
        }

        return implode("\n", $kept);
    }

    private function looksLikeRecordFactContent(string $content): bool
    {
        $patterns = [
            '/\d+\s*kcal/iu',
            '/（?\d{4}-\d{2}-\d{2}）?/u',
            '/現在の記録/u',
            '/今日の記録/u',
            '/今日のご飯/u',
            '/昼[：:：]/u',
            '/朝[：:：]/u',
            '/夜[：:：]/u',
            '/間食[：:：]/u',
            '/合計\s*\d+/u',
            '/記録済み/u',
            '/未記録/u',
            '/パスタ|白米|ご飯|トースト|サラダ|おにぎり/u',
        ];

        $hit = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $hit++;
            }
        }

        // 単独の一般文に食品語だけ含まれる場合もあるため、複数指標で判定する
        return $hit >= 2;
    }

    private function looksLikeStandaloneRecordFactLine(string $line): bool
    {
        return preg_match('/^(朝|昼|夜|間食).{0,20}\d+\s*kcal/u', $line) === 1
            || preg_match('/合計\s*\d+\s*kcal/u', $line) === 1
            || preg_match('/^\s*[-*]\s*(朝|昼|夜|間食).*\d+\s*kcal/u', $line) === 1;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function collapseRedundantSanitizedNotes(array $messages): array
    {
        $result = [];
        $previousSanitizedAssistant = false;

        foreach ($messages as $message) {
            $isSanitizedAssistant = $message['role'] === 'assistant'
                && str_starts_with($message['content'], '【会話文脈のみ・記録事実なし】');

            if ($isSanitizedAssistant && $previousSanitizedAssistant) {
                // 連続する置換文は1つにまとめる
                continue;
            }

            $result[] = $message;
            $previousSanitizedAssistant = $isSanitizedAssistant;
        }

        return $result;
    }
}
