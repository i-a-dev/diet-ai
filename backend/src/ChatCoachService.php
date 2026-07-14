<?php

declare(strict_types=1);

/**
 * ユーザーの記録データを踏まえて AI コーチと会話するサービス。
 */
final class ChatCoachService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const RECENT_DAYS = 7;

    private const SYSTEM_PROMPT = <<<'TEXT'
あなたはダイエット記録アプリの専属AIコーチです。
ユーザーが記録した体重・食事・運動・歩数のデータを踏まえ、温かく具体的にアドバイスしてください。

【プロフィールの扱い（最重要）】
- 【ユーザーの記録データ】内の「■ プロフィール（ユーザー登録・正）」は、ユーザーがアプリに登録した正式な情報です
- プロフィールに値がある項目は、すべて事実として扱い、返信の前提にしてください
- 会話履歴で以前に未設定と言っていた項目でも、プロフィールに登録済みなら必ずその登録内容を正として使ってください
- 会話履歴の内容より、毎回付与される【ユーザーの記録データ】と【今回の返信で必ず参照するプロフィール要点】を常に優先してください
- プロフィールに記載済みの項目について「〜ですか？」「確認したいこと」「情報がありません」として再度聞かないでください
- プロフィールの数値や設定を疑ったり、変更の有無を確認したりしないでください
- 「未設定」と明記されているプロフィール項目だけ、必要なら設定を促してください
- アレルギー・苦手食材が「なし」などと登録されている場合も、登録内容を正として扱ってください
- 「やりたいダイエット方法」に文章が登録されている場合、必ずその方針に沿ってアドバイスし、「やりたいダイエット方法の情報がない」とは絶対に言わないでください
- 「その他AIコーチに伝えておきたいこと」に登録がある場合も、事実として扱い、再度聞かないでください
- 食事制限の仕方（糖質制限・脂質制限など）ではなく、「やりたいダイエット方法」がプロフィールの正式な項目名です

【回答のルール】
- 日本語で、チャットらしい短めの文体で返答する
- 記録データに触れるときは具体的な数値や日付を引用する
- プロフィールの目標体重・目標ペース・目標摂取カロリー・やりたいダイエット方法は、登録値をそのまま使う
- 失敗を責めず、次の一歩を一緒に考える
- 極端な食事制限や医療行為の代替は勧めない
- 日々の記録（今日の食事・運動など）が不足しているときだけ、記録の追加を優しく促す
- 改行を使って読みやすくする

【PFC（タンパク質・脂質・炭水化物）の扱い】
- 栄養サマリーで「PFCデータあり件数 < 食事件数」のときは、表示されている P/F/C は一部の食事だけの部分合計であり、その日の総摂取量ではない
- その場合、「今日のタンパク質は〜gしか取れていません」「総タンパク量が不足」などと断定しない
- PFCが不完全なときは、不足している旨を短く伝え、カロリー（kcal）を中心にアドバイスする
- PFCが全日の食事件数分そろっているときだけ、PFCの合計を総摂取として扱ってよい
TEXT;

    private UserProfileRepository $userProfileRepository;
    private WeightRepository $weightRepository;
    private MealEntryRepository $mealEntryRepository;
    private DailyNutritionSummaryRepository $dailyNutritionSummaryRepository;
    private ActivityRepository $activityRepository;
    private ChatMessageRepository $chatMessageRepository;

    public function __construct(
        ?UserProfileRepository $userProfileRepository = null,
        ?WeightRepository $weightRepository = null,
        ?MealEntryRepository $mealEntryRepository = null,
        ?DailyNutritionSummaryRepository $dailyNutritionSummaryRepository = null,
        ?ActivityRepository $activityRepository = null,
        ?ChatMessageRepository $chatMessageRepository = null,
    ) {
        $this->userProfileRepository = $userProfileRepository ?? new UserProfileRepository();
        $this->weightRepository = $weightRepository ?? new WeightRepository();
        $this->mealEntryRepository = $mealEntryRepository ?? new MealEntryRepository();
        $this->dailyNutritionSummaryRepository = $dailyNutritionSummaryRepository
            ?? new DailyNutritionSummaryRepository(0);
        $this->activityRepository = $activityRepository ?? new ActivityRepository();
        $this->chatMessageRepository = $chatMessageRepository ?? new ChatMessageRepository();
    }

    /**
     * ユーザーメッセージを保存し、履歴を踏まえて AI が返答する。
     *
     * @return array{
     *   userMessage: array{id: int, role: string, content: string, createdAt: string},
     *   assistantMessage: array{id: int, role: string, content: string, createdAt: string}
     * }
     */
    public function sendUserMessage(string $content): array
    {
        $userMessage = $this->chatMessageRepository->add('user', $content);
        $history = $this->chatMessageRepository->listForApiContext();
        $assistant = $this->chat($history);
        $assistantMessage = $this->chatMessageRepository->add('assistant', $assistant['content']);

        return [
            'userMessage' => $userMessage,
            'assistantMessage' => $assistantMessage,
        ];
    }

    /**
     * ユーザーメッセージを保存し、AI 応答をトークン単位でストリーミングする。
     *
     * $onEvent には (eventName, payload) が渡される。
     * eventName: user_message | delta | assistant_message | error | done
     *
     * @param callable(string, array<string, mixed>): void $onEvent
     */
    public function sendUserMessageStream(string $content, callable $onEvent): void
    {
        $userMessage = $this->chatMessageRepository->add('user', $content);
        $onEvent('user_message', $userMessage);

        $history = $this->chatMessageRepository->listForApiContext();

        try {
            $assistantText = $this->chatStream(
                $history,
                static function (string $delta) use ($onEvent): void {
                    if ($delta === '') {
                        return;
                    }
                    $onEvent('delta', ['text' => $delta]);
                }
            );
        } catch (Throwable $exception) {
            $onEvent('error', ['message' => $exception->getMessage()]);
            $onEvent('done', []);
            return;
        }

        $assistantMessage = $this->chatMessageRepository->add('assistant', $assistantText);
        $onEvent('assistant_message', $assistantMessage);
        $onEvent('done', []);
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{role: string, content: string}
     */
    public function chat(array $messages): array
    {
        $text = $this->chatStream($messages, null);

        return [
            'role' => 'assistant',
            'content' => $text,
        ];
    }

    /**
     * Anthropic へストリーミングリクエストを送り、テキストを組み立てる。
     * $onDelta が渡されていれば、受信トークンごとにコールバックする。
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param callable(string): void|null $onDelta
     */
    public function chatStream(array $messages, ?callable $onDelta): string
    {
        if ($messages === []) {
            throw new InvalidArgumentException('messages is required');
        }

        $normalized = $this->normalizeMessages($messages);
        $normalized = $this->injectProfileReminderIntoMessages($normalized);
        $lastMessage = $normalized[array_key_last($normalized)];

        if (($lastMessage['role'] ?? '') !== 'user') {
            throw new InvalidArgumentException('The last message must be from the user');
        }

        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY が設定されていません。');
        }

        $context = $this->buildUserContext();
        $system = self::SYSTEM_PROMPT
            . "\n\n【ユーザーの記録データ】\n"
            . $context
            . $this->buildProfileActionSummary();

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => $normalized,
            'stream' => true,
        ];

        $text = $this->streamFromAnthropic($payload, $apiKey, $onDelta);

        if ($text === '') {
            throw new RuntimeException('AIコーチからの応答を取得できませんでした。');
        }

        return $text;
    }

    /**
     * @param array<int, array{role?: mixed, content?: mixed}> $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function normalizeMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = trim((string) ($message['role'] ?? ''));
            $content = trim((string) ($message['content'] ?? ''));

            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('messages must include at least one valid message');
        }

        if (count($normalized) > ChatMessageRepository::API_CONTEXT_LIMIT) {
            $normalized = array_slice($normalized, -ChatMessageRepository::API_CONTEXT_LIMIT);
        }

        return $normalized;
    }

    private function buildUserContext(): string
    {
        $today = WeightRepository::todayDate();
        $timezone = new DateTimeZone('Asia/Tokyo');
        $startDate = (new DateTimeImmutable($today, $timezone))
            ->modify(sprintf('-%d days', self::RECENT_DAYS - 1))
            ->format('Y-m-d');

        $profile = $this->userProfileRepository->get();
        $calorieGoal = CalorieGoalCalculator::calculate($profile);
        $todayWeight = $this->weightRepository->getSummaryForDate($today);
        $todayMeals = $this->mealEntryRepository->getSectionsForDate($today);
        $todayNutritionSummary = $this->dailyNutritionSummaryRepository->getForDate($today);
        if ($todayNutritionSummary === null) {
            $todayNutritionSummary = $this->dailyNutritionSummaryRepository->recalculateForDate($today);
        }
        $recentNutritionSummaries = $this->dailyNutritionSummaryRepository->getBetween($startDate, $today);
        $todaySteps = $this->activityRepository->getStepsForDate($today);
        $todayExercises = $this->activityRepository->getExercisesForDate($today);
        $weightPoints = $this->weightRepository->getPointsBetween($startDate, $today);
        $mealPoints = $this->mealEntryRepository->getDailyTotalsBetween($startDate, $today);
        $exercisePoints = $this->activityRepository->getDailyExerciseCaloriesBetween($startDate, $today);
        $stepPoints = $this->activityRepository->getDailyStepsBetween($startDate, $today);

        $lines = [];
        $lines[] = '今日の日付: ' . $today;
        $lines[] = '';

        $lines[] = '■ プロフィール（ユーザー登録・正）';
        $lines[] = '※以下はユーザーがプロフィール画面で登録した正式な情報です。事実として扱い、再度確認しないでください。';
        $lines[] = '性別: ' . $this->formatGender($profile['gender'] ?? null);
        $lines[] = '生年月日: ' . ($profile['birthDate'] ?? '未設定');
        if ($calorieGoal['ageYears'] !== null) {
            $lines[] = '年齢: ' . $calorieGoal['ageYears'] . '歳';
        }
        $lines[] = '身長: ' . $this->formatNullableNumber($profile['heightCm'], 'cm');
        $lines[] = '現在の体重: ' . $this->formatNullableNumber($profile['currentWeightKg'], 'kg');
        $lines[] = '目標体重: ' . $this->formatNullableNumber($profile['targetWeightKg'], 'kg');
        $lines[] = '目標ペース: ' . $this->formatNullableNumber($profile['targetPaceKgPerMonth'], 'kg/月');
        $lines[] = 'ダイエット目的: ' . $this->formatDietGoal($profile['dietGoal'] ?? null);
        $desiredDietMethod = $this->nullableProfileText($profile['desiredDietMethod'] ?? null);
        if ($desiredDietMethod !== null) {
            $lines[] = '';
            $lines[] = '【やりたいダイエット方法（登録済み・必ずこの方針に沿う）】';
            $lines[] = $desiredDietMethod;
            $lines[] = '';
        } else {
            $lines[] = 'やりたいダイエット方法: 未設定';
        }
        $lines[] = 'アレルギー・苦手食材: ' . $this->formatProfileText($profile['allergiesDislikes'] ?? null);
        $lines[] = '過去のダイエット経験: ' . $this->formatProfileText($profile['pastDietExperience'] ?? null);
        $lines[] = 'その他AIコーチに伝えておきたいこと: ' . $this->formatProfileText($profile['coachNotes'] ?? null);
        if ($calorieGoal['bmrKcal'] !== null) {
            $lines[] = '推定基礎代謝: ' . $calorieGoal['bmrKcal'] . 'kcal/日';
        }
        if ($calorieGoal['tdeeKcal'] !== null) {
            $lines[] = '推定消費カロリー: ' . $calorieGoal['tdeeKcal'] . 'kcal/日';
        }
        if ($calorieGoal['dailyIntakeGoalKcal'] !== null) {
            $lines[] = '目標摂取カロリー: ' . $calorieGoal['dailyIntakeGoalKcal'] . 'kcal/日';
            if ($calorieGoal['dailyDeficitKcal'] !== null) {
                $lines[] = '（目標ペースに基づく1日あたりの不足カロリー: 約'
                    . $calorieGoal['dailyDeficitKcal'] . 'kcal）';
            }
        }
        $lines[] = '';

        $lines[] = '■ 今日の記録 (' . $today . ')';
        $lines[] = $this->formatTodayWeight($todayWeight);
        $lines[] = $this->formatTodayMeals($todayMeals);
        $lines[] = $this->formatNutritionSummary($todayNutritionSummary, '今日の栄養サマリー');
        $lines[] = $this->formatTodaySteps($todaySteps);
        $lines[] = $this->formatTodayExercises($todayExercises);
        $lines[] = '';

        $lines[] = '■ 直近' . self::RECENT_DAYS . '日の推移';
        $lines[] = $this->formatRecentWeight($weightPoints);
        $lines[] = $this->formatRecentMetric('食事カロリー', $mealPoints, 'kcal');
        $lines[] = $this->formatRecentNutritionSummaries($recentNutritionSummaries);
        $lines[] = $this->formatRecentMetric('運動消費カロリー', $exercisePoints, 'kcal');
        $lines[] = $this->formatRecentMetric('歩数', $stepPoints, '歩');

        return implode("\n", $lines);
    }

    /**
     * 会話履歴に埋もれないよう、返信直前に参照すべき要点を再度明示する。
     */
    private function buildProfileActionSummary(): string
    {
        $profile = $this->userProfileRepository->get();
        $calorieGoal = CalorieGoalCalculator::calculate($profile);
        $desiredDietMethod = $this->nullableProfileText($profile['desiredDietMethod'] ?? null);

        $lines = [];
        $lines[] = '';
        $lines[] = '【今回の返信で必ず参照するプロフィール要点（会話履歴より優先）】';

        if ($desiredDietMethod !== null) {
            $lines[] = 'やりたいダイエット方法: ' . $desiredDietMethod;
        } else {
            $lines[] = 'やりたいダイエット方法: 未設定';
        }

        if ($calorieGoal['dailyIntakeGoalKcal'] !== null) {
            $lines[] = '目標摂取カロリー: ' . $calorieGoal['dailyIntakeGoalKcal'] . 'kcal/日';
        }

        $lines[] = '目標体重: ' . $this->formatNullableNumber($profile['targetWeightKg'], 'kg');
        $lines[] = '目標ペース: ' . $this->formatNullableNumber($profile['targetPaceKgPerMonth'], 'kg/月');

        return implode("\n", $lines);
    }

    /**
     * 直近のユーザー発言にもプロフィール要点を付与し、モデルの注意を引く。
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function injectProfileReminderIntoMessages(array $messages): array
    {
        $profile = $this->userProfileRepository->get();
        $desiredDietMethod = $this->nullableProfileText($profile['desiredDietMethod'] ?? null);
        if ($desiredDietMethod === null) {
            return $messages;
        }

        $lastIndex = array_key_last($messages);
        if ($lastIndex === null || ($messages[$lastIndex]['role'] ?? '') !== 'user') {
            return $messages;
        }

        $messages[$lastIndex]['content'] .= "\n\n"
            . '【参照用・プロフィール登録済み】やりたいダイエット方法: '
            . $desiredDietMethod;

        return $messages;
    }

    /**
     * @param array{
     *   current: float|null,
     *   diffFromPreviousDay: float|null,
     *   recordedOn: string,
     *   referenceWeight?: float|null,
     *   referenceRecordedOn?: string|null
     * } $summary
     */
    private function formatTodayWeight(array $summary): string
    {
        if ($summary['current'] !== null) {
            $line = '- 体重: ' . $summary['current'] . 'kg';
            if ($summary['diffFromPreviousDay'] !== null) {
                $diff = $summary['diffFromPreviousDay'];
                $sign = $diff > 0 ? '+' : '';
                $line .= '（前日比 ' . $sign . $diff . 'kg）';
            }

            return $line;
        }

        if (($summary['referenceWeight'] ?? null) !== null) {
            return '- 体重: 今日は未記録（参考: ' . $summary['referenceWeight'] . 'kg / '
                . ($summary['referenceRecordedOn'] ?? '不明') . '）';
        }

        return '- 体重: 未記録';
    }

    /**
     * @param array<int, array{id: string, name: string, calories: int, items: array<int, array<string, mixed>>}> $sections
     */
    private function formatTodayMeals(array $sections): string
    {
        $lines = ['- 食事:'];
        $totalCalories = 0;
        $hasItems = false;

        foreach ($sections as $section) {
            $items = $section['items'] ?? [];
            if ($items === []) {
                continue;
            }

            $hasItems = true;
            $sectionCalories = (int) ($section['calories'] ?? 0);
            $totalCalories += $sectionCalories;
            $itemLabels = array_map(
                function (array $item): string {
                    $label = ($item['label'] ?? '') . ' ' . ($item['calories'] ?? 0) . 'kcal';
                    $parts = [];
                    if (isset($item['amount'], $item['unit']) && $item['amount'] !== null && $item['unit'] !== null) {
                        $parts[] = $item['amount'] . $item['unit'];
                    }
                    if (($item['proteinG'] ?? null) !== null) {
                        $parts[] = 'P' . $item['proteinG'] . 'g';
                    }
                    if (($item['confidence'] ?? null) === 'low') {
                        $parts[] = '低信頼度';
                    }
                    if (($item['caloriesEdited'] ?? false) === true) {
                        $parts[] = '手修正';
                    }

                    return $parts === [] ? $label : $label . '（' . implode('、', $parts) . '）';
                },
                $items
            );
            $lines[] = '  - ' . ($section['name'] ?? $section['id']) . ': ' . $sectionCalories . 'kcal（'
                . implode('、', $itemLabels) . '）';
        }

        if (!$hasItems) {
            return '- 食事: 未記録';
        }

        $lines[] = '  - 合計: ' . $totalCalories . 'kcal';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed>|null $summary
     */
    private function formatNutritionSummary(?array $summary, string $title): string
    {
        if ($summary === null || (int) ($summary['mealEntryCount'] ?? 0) === 0) {
            return '- ' . $title . ': 未記録';
        }

        $lines = ['- ' . $title . ':'];
        $lines[] = '  - 合計: ' . ($summary['totalKcal'] ?? 0) . 'kcal（朝'
            . ($summary['breakfastKcal'] ?? 0) . ' / 昼' . ($summary['lunchKcal'] ?? 0)
            . ' / 夜' . ($summary['dinnerKcal'] ?? 0) . ' / 間食' . ($summary['snackKcal'] ?? 0) . '）';

        $pfcParts = [];
        if (($summary['totalProteinG'] ?? null) !== null) {
            $pfcParts[] = 'P' . $summary['totalProteinG'] . 'g';
        }
        if (($summary['totalFatG'] ?? null) !== null) {
            $pfcParts[] = 'F' . $summary['totalFatG'] . 'g';
        }
        if (($summary['totalCarbsG'] ?? null) !== null) {
            $pfcParts[] = 'C' . $summary['totalCarbsG'] . 'g';
        }
        if ($pfcParts !== []) {
            $known = (int) ($summary['pfcKnownEntryCount'] ?? 0);
            $total = (int) ($summary['mealEntryCount'] ?? 0);
            if ($known < $total) {
                $lines[] = '  - PFC（部分合計のみ・総摂取ではない・' . $known . '/' . $total
                    . '件にデータあり）: ' . implode(' / ', $pfcParts);
                $lines[] = '  - 注意: PFC不完全のため総タンパク量などと断定禁止。不足を伝えkcal中心で話すこと';
            } else {
                $lines[] = '  - PFC（' . $known . '/' . $total . '件にデータあり・全日分）: '
                    . implode(' / ', $pfcParts);
            }
        } else {
            $lines[] = '  - PFC: データ不足（null は0扱いしない）。総摂取と断定せずkcal中心で話すこと';
        }

        if (($summary['summaryText'] ?? null) !== null && trim((string) $summary['summaryText']) !== '') {
            $lines[] = '  - メモ: ' . trim((string) $summary['summaryText']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $summaries
     */
    private function formatRecentNutritionSummaries(array $summaries): string
    {
        if ($summaries === []) {
            return '- 食事サマリー: データなし';
        }

        $lines = ['- 食事サマリー（日次）:'];
        foreach ($summaries as $summary) {
            $date = (string) ($summary['recordedOn'] ?? '');
            $kcal = (int) ($summary['totalKcal'] ?? 0);
            $snack = (int) ($summary['snackKcal'] ?? 0);
            $pfcKnown = (int) ($summary['pfcKnownEntryCount'] ?? 0);
            $entryCount = (int) ($summary['mealEntryCount'] ?? 0);
            $line = '  - ' . $date . ': ' . $kcal . 'kcal';
            if ($kcal > 0 && $snack > 0) {
                $line .= '（間食' . $snack . 'kcal）';
            }
            if ($entryCount > 0) {
                $line .= ' / PFCデータ' . $pfcKnown . '件';
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{count: int, burnedCalories: int} $steps
     */
    private function formatTodaySteps(array $steps): string
    {
        if (($steps['count'] ?? 0) <= 0) {
            return '- 歩数: 未記録';
        }

        return '- 歩数: ' . $steps['count'] . '歩（推定消費 ' . $steps['burnedCalories'] . 'kcal）';
    }

    /**
     * @param array{entries: array<int, array{name: string, amount: int, unit: string, burnedCalories: int}>, burnedCalories: int} $exercises
     */
    private function formatTodayExercises(array $exercises): string
    {
        $entries = $exercises['entries'] ?? [];
        if ($entries === []) {
            return '- 運動: 未記録';
        }

        $lines = ['- 運動:'];
        foreach ($entries as $entry) {
            $unitLabel = ($entry['unit'] ?? '') === 'rep' ? '回' : '分';
            $lines[] = '  - ' . ($entry['name'] ?? '運動') . ' ' . ($entry['amount'] ?? 0) . $unitLabel
                . ' / 約' . ($entry['burnedCalories'] ?? 0) . 'kcal';
        }
        $lines[] = '  - 合計消費: ' . ($exercises['burnedCalories'] ?? 0) . 'kcal';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{label: string, value: float|null, date?: string}> $points
     */
    private function formatRecentWeight(array $points): string
    {
        $recorded = array_values(array_filter(
            $points,
            static fn (array $point): bool => ($point['value'] ?? null) !== null
        ));

        if ($recorded === []) {
            return '- 体重推移: 記録なし';
        }

        $parts = array_map(
            static fn (array $point): string => ($point['label'] ?? '') . ' ' . $point['value'] . 'kg',
            $recorded
        );

        return '- 体重推移: ' . implode(' → ', $parts);
    }

    /**
     * @param array<int, array{label: string, value: int, date?: string}> $points
     */
    private function formatRecentMetric(string $label, array $points, string $unit): string
    {
        $nonZero = array_values(array_filter(
            $points,
            static fn (array $point): bool => ($point['value'] ?? 0) > 0
        ));

        if ($nonZero === []) {
            return '- ' . $label . ': 記録なし';
        }

        $parts = array_map(
            static fn (array $point): string => ($point['label'] ?? '') . ' ' . $point['value'] . $unit,
            $nonZero
        );

        $values = array_column($nonZero, 'value');
        $average = (int) round(array_sum($values) / count($values));

        return '- ' . $label . ': ' . implode(' / ', $parts) . '（平均 ' . $average . $unit . '）';
    }

    private function formatNullableNumber(?float $value, string $unit): string
    {
        return $value === null ? '未設定' : $value . $unit;
    }

    private function formatProfileText(?string $value): string
    {
        if ($value === null || $value === '') {
            return '未設定';
        }

        return $value . '（ユーザー登録）';
    }

    private function nullableProfileText(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function formatGender(?string $gender): string
    {
        return match ($gender) {
            'male' => '男性',
            'female' => '女性',
            'other' => 'その他',
            default => '未設定',
        };
    }

    private function formatDietGoal(?string $goal): string
    {
        return match ($goal) {
            'weight_loss' => '減量',
            'maintenance' => '体型維持',
            'muscle_gain' => '筋肉増量',
            'health' => '健康維持',
            default => '未設定',
        };
    }

    /**
     * Anthropic Messages API へストリームリクエストを送り、テキストデルタを処理する。
     *
     * @param array<string, mixed> $payload
     * @param callable(string): void|null $onDelta
     */
    private function streamFromAnthropic(array $payload, string $apiKey, ?callable $onDelta): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl 拡張が有効になっていません。');
        }

        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            throw new RuntimeException('AIコーチサービスへの接続を開始できませんでした。');
        }

        $lineBuffer = '';
        $fullText = '';
        $rawFallback = '';
        $streamError = null;

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_WRITEFUNCTION => static function (
                $curl,
                string $chunk
            ) use (
                &$lineBuffer,
                &$fullText,
                &$rawFallback,
                &$streamError,
                $onDelta
            ): int {
                if ($streamError !== null) {
                    return strlen($chunk);
                }

                $lineBuffer .= $chunk;
                $rawFallback .= $chunk;

                while (($newlinePos = strpos($lineBuffer, "\n")) !== false) {
                    $line = substr($lineBuffer, 0, $newlinePos);
                    $lineBuffer = substr($lineBuffer, $newlinePos + 1);
                    $line = rtrim($line, "\r");

                    if ($line === '' || str_starts_with($line, ':') || str_starts_with($line, 'event:')) {
                        continue;
                    }

                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $json = trim(substr($line, 5));
                    if ($json === '' || $json === '[DONE]') {
                        continue;
                    }

                    $decoded = json_decode($json, true);
                    if (!is_array($decoded)) {
                        continue;
                    }

                    $type = (string) ($decoded['type'] ?? '');

                    if ($type === 'error') {
                        $message = is_array($decoded['error'] ?? null)
                            ? (string) ($decoded['error']['message'] ?? 'AIコーチとの会話に失敗しました。')
                            : 'AIコーチとの会話に失敗しました。';
                        $streamError = $message;
                        break;
                    }

                    if ($type !== 'content_block_delta') {
                        continue;
                    }

                    $delta = $decoded['delta'] ?? null;
                    if (!is_array($delta) || ($delta['type'] ?? '') !== 'text_delta') {
                        continue;
                    }

                    $text = (string) ($delta['text'] ?? '');
                    if ($text === '') {
                        continue;
                    }

                    $fullText .= $text;
                    if ($onDelta !== null) {
                        $onDelta($text);
                    }
                }

                return strlen($chunk);
            },
        ]);

        $executed = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($executed === false) {
            throw new RuntimeException(
                $curlError !== ''
                    ? 'AIコーチサービスへの接続に失敗しました: ' . $curlError
                    : 'AIコーチサービスへの接続に失敗しました。',
            );
        }

        if ($streamError !== null) {
            throw new RuntimeException($streamError);
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($rawFallback, true);
            $message = is_array($decoded) && is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? 'AIコーチとの会話に失敗しました。')
                : 'AIコーチとの会話に失敗しました。';
            throw new RuntimeException($message);
        }

        return trim($fullText);
    }
}
