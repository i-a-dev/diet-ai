<?php

declare(strict_types=1);

/**
 * ユーザーの記録データを踏まえて AI コーチと会話するサービス。
 *
 * 流れ:
 * ユーザー質問 → 対象期間を start/end へ解決 → DB 正式記録を構造化
 * → 会話履歴から記録事実を除外 → authoritative context を質問直前に注入 → 回答
 */
final class ChatCoachService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const TIMEZONE = 'Asia/Tokyo';
    /** この時刻未満なら、当日の食事・歩数・運動の未記録へ原則言及しない */
    private const TODAY_MISSING_RECORD_MENTION_CUTOFF_HOUR = 18;

    private const SYSTEM_PROMPT = <<<'TEXT'
あなたはダイエット記録アプリの専属AIコーチです。
ユーザーが記録した体重・食事・運動・歩数のデータを踏まえ、温かく具体的にアドバイスしてください。

【記録データの優先順位】
- 食事名、量、カロリー、栄養値、体重、日付などの記録事実は、authoritative_record_context に含まれるDB由来データのみを正とする
- 今回解決された対象期間のDB記録を最優先する（「今日だけ特別」ではなく、解決された start_date〜end_date が常に正）
- 会話履歴は会話の流れ、希望、好み、制約を理解するためだけに使用する
- 会話履歴に登場する食品名、カロリー、体重を現在の記録として使用しない
- 対象期間外の食品を、対象期間内の食事として扱わない
- DB記録に存在しない食品名を推測、補完、置換しない
- 過去のassistant回答を正式な記録事実として扱わない
- 記録がない場合（record_status=no_record / 未記録）は「未記録」と回答し、食べていないと断定しない
- 【会話文脈のみ・記録事実なし】と刻まれた履歴は意図把握だけに使い、数値や食品名は無視する

【プロフィールの扱い（最重要）】
- authoritative_record_context および system 内のプロフィールは、ユーザーがアプリに登録した正式な情報です
- プロフィールに値がある項目は、すべて事実として扱い、返信の前提にしてください
- 会話履歴で以前に未設定と言っていた項目でも、プロフィールに登録済みなら必ずその登録内容を正として使ってください
- 会話履歴の内容より、毎回付与される authoritative_record_context とプロフィール要点を常に優先してください
- プロフィールに記載済みの項目について「〜ですか？」「確認したいこと」「情報がありません」として再度聞かないでください
- プロフィールの数値や設定を疑ったり、変更の有無を確認したりしないでください
- 「未設定」と明記されているプロフィール項目だけ、必要なら設定を促してください
- アレルギー・苦手食材が「なし」などと登録されている場合も、登録内容を正として扱ってください
- 「やりたいダイエット方法」に文章が登録されている場合、必ずその方針に沿ってアドバイスし、「やりたいダイエット方法の情報がない」とは絶対に言わないでください
- 「その他AIコーチに伝えておきたいこと」に登録がある場合も、事実として扱い、再度聞かないでください
- 食事制限の仕方（糖質制限・脂質制限など）ではなく、「やりたいダイエット方法」がプロフィールの正式な項目名です

【回答のルール】
- 日本語で、チャットらしい短めの文体で返答する
- 記録データに触れるときは authoritative_record_context の食品名・数値・日付をそのまま引用する
- プロフィールの目標体重・目標ペース・目標摂取カロリー・やりたいダイエット方法は、登録値をそのまま使う
- 失敗を責めず、次の一歩を一緒に考える
- 極端な食事制限や医療行為の代替は勧めない
- 改行を使って読みやすくする

【質問への回答優先（最重要）】
- まずユーザーの質問そのものに答える。記録不足や記録追加の促しを先に出さない
- 質問への回答に十分な情報がある場合は、「記録がありません」「正確な分析ができません」を主題にしない
- 不足している記録がある場合でも、本文の主題は質問への回答にし、不足情報は最後に一言だけ補足する（例:「なお、今日の食事がまだ未記録です」）
- 今日の食事記録がないことだけを理由に、減量進捗・体重の分析や評価ができないとは言わない
- 質問の種類に応じて、必要な記録種別を使い分ける:
    「減量具合」「あと何kg」「順調か」など体重ベースの質問 → 体重・目標体重・目標ペースを主に使い、食事の有無を判断材料の中心にしない
    - カロリー・栄養バランス・今日の食事内容の質問 → 食事記録を主に使う
- 「対象期間に meal=no_record」だからといって、自動的に記録不足を主題にしたり食事記録を促したりしない。質問に食事が不要なら促さない
- 対象期間の記録が不足していて、かつその不足が質問の回答に本当に必要なときだけ、最後に優しく一言促す

【PFC（タンパク質・脂質・炭水化物）の扱い】
- 栄養サマリーで「PFCデータあり件数 < 食事件数」のときは、表示されている P/F/C は一部の食事だけの部分合計であり、その日の総摂取量ではない
- その場合、「タンパク質は〜gしか取れていません」「総タンパク量が不足」などと断定しない
- PFCが不完全なときは、不足している旨を短く伝え、カロリー（kcal）を中心にアドバイスする
- PFCが全日の食事件数分そろっているときだけ、PFCの合計を総摂取として扱ってよい
TEXT;

    private UserProfileRepository $userProfileRepository;
    private WeightRepository $weightRepository;
    private MealEntryRepository $mealEntryRepository;
    private DailyNutritionSummaryRepository $dailyNutritionSummaryRepository;
    private ActivityRepository $activityRepository;
    private ChatMessageRepository $chatMessageRepository;
    private RecordQueryScopeResolver $scopeResolver;
    private ChatHistorySanitizer $historySanitizer;
    private AuthoritativeRecordContextBuilder $recordContextBuilder;
    private ChatLlmMessageComposer $messageComposer;

    public function __construct(
        ?UserProfileRepository $userProfileRepository = null,
        ?WeightRepository $weightRepository = null,
        ?MealEntryRepository $mealEntryRepository = null,
        ?DailyNutritionSummaryRepository $dailyNutritionSummaryRepository = null,
        ?ActivityRepository $activityRepository = null,
        ?ChatMessageRepository $chatMessageRepository = null,
        ?RecordQueryScopeResolver $scopeResolver = null,
        ?ChatHistorySanitizer $historySanitizer = null,
        ?AuthoritativeRecordContextBuilder $recordContextBuilder = null,
        ?ChatLlmMessageComposer $messageComposer = null,
    ) {
        $this->userProfileRepository = $userProfileRepository ?? new UserProfileRepository();
        $this->weightRepository = $weightRepository ?? new WeightRepository();
        $this->mealEntryRepository = $mealEntryRepository ?? new MealEntryRepository();
        $this->dailyNutritionSummaryRepository = $dailyNutritionSummaryRepository
            ?? new DailyNutritionSummaryRepository(0);
        $this->activityRepository = $activityRepository ?? new ActivityRepository();
        $this->chatMessageRepository = $chatMessageRepository ?? new ChatMessageRepository();
        $this->scopeResolver = $scopeResolver ?? new RecordQueryScopeResolver();
        $this->historySanitizer = $historySanitizer ?? new ChatHistorySanitizer();
        $this->recordContextBuilder = $recordContextBuilder ?? new AuthoritativeRecordContextBuilder();
        $this->messageComposer = $messageComposer ?? new ChatLlmMessageComposer();
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
     * @param array<int, array{role: string, content: string}> $messages
     * @param callable(string): void|null $onDelta
     */
    public function chatStream(array $messages, ?callable $onDelta): string
    {
        $prepared = $this->prepareLlmPayload($messages);

        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY が設定されていません。');
        }

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 1024,
            'system' => $prepared['system'],
            'messages' => $prepared['messages'],
            'stream' => true,
        ];

        $text = $this->streamFromAnthropic($payload, $apiKey, $onDelta);

        if ($text === '') {
            throw new RuntimeException('AIコーチからの応答を取得できませんでした。');
        }

        return $text;
    }

    /**
     * 当日の食事・歩数・運動の未記録言及を時間帯で抑制するか（Asia/Tokyo）。
     * 実時刻に依存するテスト向けに公開。
     */
    public static function shouldSuppressTodayMissingRecordMention(DateTimeImmutable $now): bool
    {
        $now = $now->setTimezone(new DateTimeZone(self::TIMEZONE));

        return (int) $now->format('H') < self::TODAY_MISSING_RECORD_MENTION_CUTOFF_HOUR;
    }

    /**
     * LLM へ渡す system / messages を組み立てる（テスト可能）。
     *
     * @param array<int, array{role?: mixed, content?: mixed}> $messages
     * @return array{
     *   system: string,
     *   messages: array<int, array{role: string, content: string}>,
     *   scope: RecordQueryScope,
     *   authoritative: array<string, mixed>,
     *   history_meta: array<string, int>
     * }
     */
    public function prepareLlmPayload(array $messages, ?DateTimeImmutable $now = null): array
    {
        $normalized = $this->normalizeMessages($messages);
        $lastIndex = array_key_last($normalized);
        if ($lastIndex === null || ($normalized[$lastIndex]['role'] ?? '') !== 'user') {
            throw new InvalidArgumentException('The last message must be from the user');
        }

        $userQuestion = $normalized[$lastIndex]['content'];
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new DateTimeZone(self::TIMEZONE));
        $today = $now->setTime(0, 0);
        $suppressTodayMissingRecordMention = self::shouldSuppressTodayMissingRecordMention($now);
        $scope = $this->scopeResolver->resolve($userQuestion, $today, null);

        $historyWithoutCurrent = array_slice($normalized, 0, -1);
        $sanitized = $this->historySanitizer->sanitize($historyWithoutCurrent);

        $authoritative = $this->buildAuthoritativeContext($scope, $today);
        $system = self::SYSTEM_PROMPT
            . "\n\n【今回解決された対象期間】\n"
            . sprintf(
                '%s 〜 %s（scope_type=%s / original=%s）',
                $scope->startDateString(),
                $scope->endDateString(),
                $scope->type->value,
                $scope->originalExpression,
            )
            . "\n\n"
            . $authoritative['text']
            . $this->buildProfileActionSummary();

        $desiredDietMethod = $this->nullableProfileText(
            $this->userProfileRepository->get()['desiredDietMethod'] ?? null
        );
        $safeMessages = $sanitized['messages'];
        $safeMessages[] = [
            'role' => 'user',
            'content' => $this->messageComposer->composeFinalUserMessage(
                $userQuestion,
                $scope,
                $authoritative,
                $desiredDietMethod,
                $now,
                $suppressTodayMissingRecordMention,
            ),
        ];

        $this->logScopeResolution(
            $userQuestion,
            $scope,
            $authoritative,
            $sanitized,
        );

        return [
            'system' => $system,
            'messages' => $safeMessages,
            'scope' => $scope,
            'authoritative' => $authoritative,
            'history_meta' => [
                'history_count_before' => $sanitized['history_count_before'],
                'history_count_after' => $sanitized['history_count_after'],
                'excluded_count' => $sanitized['excluded_count'],
            ],
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function buildAuthoritativeContext(RecordQueryScope $scope, DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $todayStr = $today->format('Y-m-d');
        $start7 = $today->modify('-6 days');
        $start30 = $today->modify('-29 days');
        $start30Str = $start30->format('Y-m-d');

        $mealRows30 = $this->mealEntryRepository->findBetween($start30Str, $todayStr);
        $nutritionRows = $this->dailyNutritionSummaryRepository->getBetween($start30Str, $todayStr);
        $nutritionByDate = [];
        foreach ($nutritionRows as $row) {
            $nutritionByDate[(string) $row['recordedOn']] = $row;
        }

        $weightPoints = $this->weightRepository->getPointsBetween($start30Str, $todayStr);
        $weightByDate = [];
        foreach ($weightPoints as $point) {
            $weightByDate[(string) $point['date']] = $point['value'];
        }

        $stepsByDate7 = [];
        $exercisesByDate7 = [];
        $cursor = $start7;
        while ($cursor <= $today) {
            $date = $cursor->format('Y-m-d');
            $stepsByDate7[$date] = $this->activityRepository->getStepsForDate($date);
            $exercisesByDate7[$date] = $this->activityRepository->getExercisesForDate($date);
            $cursor = $cursor->modify('+1 day');
        }

        $stepsCountByDate30 = [];
        foreach ($this->activityRepository->getDailyStepsBetween($start30Str, $todayStr) as $point) {
            $stepsCountByDate30[(string) $point['date']] = (int) $point['value'];
        }

        $exerciseKcalByDate30 = [];
        foreach ($this->activityRepository->getDailyExerciseCaloriesBetween($start30Str, $todayStr) as $point) {
            $exerciseKcalByDate30[(string) $point['date']] = (int) $point['value'];
        }

        return $this->recordContextBuilder->buildLayered(
            $scope,
            $today,
            $mealRows30,
            $nutritionByDate,
            $weightByDate,
            $stepsByDate7,
            $exercisesByDate7,
            $stepsCountByDate30,
            $exerciseKcalByDate30,
            $this->buildProfileSnapshot(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfileSnapshot(): array
    {
        $profile = $this->userProfileRepository->get();
        $calorieGoal = CalorieGoalCalculator::calculate($profile);

        return [
            'gender' => $this->formatGender($profile['gender'] ?? null),
            'birth_date' => $profile['birthDate'] ?? null,
            'age_years' => $calorieGoal['ageYears'],
            'height_cm' => $profile['heightCm'] ?? null,
            'current_weight_kg' => $profile['currentWeightKg'] ?? null,
            'target_weight_kg' => $profile['targetWeightKg'] ?? null,
            'target_pace_kg_per_month' => $profile['targetPaceKgPerMonth'] ?? null,
            'diet_goal' => $this->formatDietGoal($profile['dietGoal'] ?? null),
            'desired_diet_method' => $this->nullableProfileText($profile['desiredDietMethod'] ?? null),
            'allergies_dislikes' => $this->nullableProfileText($profile['allergiesDislikes'] ?? null),
            'past_diet_experience' => $this->nullableProfileText($profile['pastDietExperience'] ?? null),
            'coach_notes' => $this->nullableProfileText($profile['coachNotes'] ?? null),
            'bmr_kcal' => $calorieGoal['bmrKcal'],
            'tdee_kcal' => $calorieGoal['tdeeKcal'],
            'daily_intake_goal_kcal' => $calorieGoal['dailyIntakeGoalKcal'],
            'daily_deficit_kcal' => $calorieGoal['dailyDeficitKcal'],
        ];
    }

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

        $lines[] = '目標体重: ' . $this->formatNullableNumber($profile['targetWeightKg'] ?? null, 'kg');
        $lines[] = '目標ペース: ' . $this->formatNullableNumber($profile['targetPaceKgPerMonth'] ?? null, 'kg/月');

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $authoritative
     * @param array{history_count_before: int, history_count_after: int, excluded_count: int} $sanitized
     */
    private function logScopeResolution(
        string $userQuestion,
        RecordQueryScope $scope,
        array $authoritative,
        array $sanitized,
    ): void {
        $questionPreview = mb_substr(preg_replace("/\s+/u", ' ', $userQuestion) ?? $userQuestion, 0, 80);
        $payload = [
            'event' => 'ai_chat_record_scope_resolved',
            'scope_type' => $scope->type->value,
            'start_date' => $scope->startDateString(),
            'end_date' => $scope->endDateString(),
            'original_expression' => $scope->originalExpression,
            'question_preview' => $questionPreview,
            'meal_count' => (int) ($authoritative['meal_count'] ?? 0),
            'history_count_before' => $sanitized['history_count_before'],
            'history_count_after' => $sanitized['history_count_after'],
            'excluded_count' => $sanitized['excluded_count'],
            'daily_record_days' => count($authoritative['daily_records'] ?? []),
            'authoritative_json_bytes' => strlen((string) ($authoritative['json'] ?? '')),
        ];

        error_log('[ai_chat_record_scope] ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function formatNullableNumber(?float $value, string $unit): string
    {
        return $value === null ? '未設定' : $value . $unit;
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
                'anthropic-version: ' . '2023-06-01',
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
