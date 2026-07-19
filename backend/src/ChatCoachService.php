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

【正式記録の優先順位】
- 食事名、量、カロリー、登録PFC、体重、日付などの記録事実は、最終ユーザーメッセージ内の authoritative_record_context（JSON）に含まれるDB由来データのみを正とする
- 解決された対象期間（scope_start_date〜scope_end_date）の scope_records を最優先する
- today_detail があっても、対象期間外なら回答の主根拠にしない
- 会話履歴は会話の流れ・希望・好み・制約の理解だけに使い、食品名・カロリー・体重の正式事実として使わない
- 対象期間外の食品を、対象期間内の食事として扱わない
- DB記録に存在しない食品名を推測・補完・置換しない（PFC参考推定のための一般知識利用は後述ルールに従う）
- 過去のassistant回答を正式な記録事実として扱わない
- 記録がない場合（record_status=no_record）は「未記録」とし、食べていないと断定しない
- 【会話文脈のみ・記録事実なし】と刻まれた履歴は意図把握だけに使い、数値や食品名は無視する

【対象期間の厳守】
- 回答の中心に使う食事は、query_scope / scope_records の期間内だけにする
- 先週・昨日など別期間が解決された場合、今日の食事を主根拠にしない
- 層データ（recent_7d / summary_*）は補助比較用であり、質問対象期間の代わりにしない

【プロフィールの扱い】
- プロフィールはユーザーがアプリに登録した正式情報として扱う
- 目標体重・目標ペース・目標摂取カロリー・やりたいダイエット方法は登録値を正とする
- やりたいダイエット方法がある場合、その方針に沿ってアドバイスし、「情報がない」とは言わない
- プロフィールに現在体重項目はない。現在体重は体重記録（WeightRepository由来）のみを使う
- 体重記録がないのに、目標体重との差だけで進捗を計算しない。「あと何kg」は直近体重記録と目標体重の両方が必要
- プロフィールに記載済みの項目を再度聞いたり疑ったりしない。「未設定」と明記された項目だけ必要なら促す

【質問への回答優先】
- 最初の一文でユーザーの質問そのものに答える
- 「記録を確認しました」「まずデータが不足しています」から始めない
- 不足説明が必要でも、先に答えられる範囲で答え、最後に短く補足する
- 質問の種類に応じて必要な記録を使い分ける:
  - 「減量具合」「あと何kg」「順調か」→ 体重・目標体重・目標ペースを主に使う
  - カロリー・栄養バランス・食事内容 → 食事記録を主に使う
- 食事が不要な質問で、meal=no_record だけを理由に記録不足を主題にしない

【食事記録の完全性】
- meal_record_meta.day_completion は原則 unknown（1日分すべて登録済みとは限らない）
- day_completion=unknown のときは「登録された範囲では」「記録から確認できる範囲では」「今日の記録分を見ると」を使う
- 禁止: 「今日の総摂取量は」「今日はこれしか食べていないので」「1日分として完全に」「今日の食事全体では」
- 「未記録」と「記録不完全の可能性」を区別する

【PFC登録値とAI参考推定の区別】
- 登録済みPFCは正式記録として優先する
- pfc_evidence.status=partial の registered_totals は部分合計であり、期間全体のPFCではない
- PFCが不完全または未登録でも、食品名・量・単位・serving・登録カロリーから不足分を参考推定してよい
- 未登録部分だけを一般的な食品知識から推定し、登録値と推定値を混同しない
- 推定値は単一の正確な数値ではなく範囲で示し、「参考推定」「おおよそ」「〜くらい」「〜の範囲」と明示する
- 量や調理方法が不明、外食・惣菜・油・ソース不明の場合は範囲を広げ確度を下げる
- 登録カロリーと大きく矛盾するPFC推定をしない
- 推定PFCを正式な計測値として扱わず、推定値だけで「十分」「不足」「理想的」と断定しない
- 推定不能なら無理に数値を出さない
- PFCを聞かれてもいない／出す意味が薄い質問では、機械的にPFCを毎回表示しない
- 栄養バランスは、一部PFCや単一食品だけで「良い」と断定しない。根拠が足りなければ限定表現を使う

【減量に関する断定禁止】
- 単日の食事だけで「痩せる」「確実に体重が減る」「脂肪が減った」「明日は体重が落ちる」「カロリー赤字だった」「減量に成功している」と断定しない
- 食事内容の評価は「登録された範囲では比較的減量向き」「この1食だけで痩せるとは断定できないが構成としては悪くない」などにとどめる
- answer_permissions.may_assert_fat_loss は常に false
- answer_permissions.may_predict_next_day_weight は常に false
- 登録カロリーと目標の比較は可。ただし食事完了不明なら「登録分は目標内」は言えても「今日は○kcalの赤字」と断定しない
- may_estimate_energy_balance=false のときは確定的なエネルギー収支を述べない

【BMR・TDEE・痩せる/太るの正しい使い方（最重要）】
- 基礎代謝(BMR)は安静時の推定消費であり、「BMRを下回れば痩せる／上回れば太る」の判定基準ではない
- 体重増減のざっくりした目安は、登録摂取カロリーと推定消費カロリー(TDEE)の比較である
- ただし TDEE も推定値。食事記録も完了不明なため、「確実に痩せる/太る」「脂肪が増減した」とは言わない
- 許可する言い方の例:「登録平均は推定TDEEを下回っているので、記録の範囲では減量方向の可能性」
- 禁止する言い方の例:
  - 「基礎代謝1257kcalより多い日は太る」
  - 「基礎代謝より少ない日は痩せる」
  - 日別に BMR 差分を出して「太る/痩せる」とラベル付けする表
- 登録摂取とBMRの比較は、極端に低い摂取の注意や「目標よりかなり抑えめ」などの参考には使ってよいが、痩せる/太るの数値判定には使わない
- 順調かどうかの指標としては、目標摂取カロリーとの比較や体重推移の方が優先。BMR比較を主根拠にしない

【数値比較の正確性（最重要）】
- kcal・体重・BMR・TDEE・平均などの数値は、authoritative_record_context にある値だけを使う。存在しない数値を作らない
- 平均摂取・合計・BMR・TDEE・目標などの暗算や大小判定は自分でやり直さず、energy_evidence / numeric_comparisons を正とする
- comparisons の値:
  - above = 左側が右側を上回る
  - below = 左側が右側を下回る
  - equal = 同じ
  - unavailable = 比較不能
- registered_avg_vs_bmr の大小を述べることはできるが、「だから痩せる/太る」とは言わない
- registered_avg_vs_tdee をエネルギー収支の参考にする。above/below を取り違えない
- 目標摂取カロリー・平均摂取・BMR・TDEE を取り違えない
- 数値を引用するときは、コンテキストの数字をそのまま使い、近い別の数字へすり替えない

【体重推移の扱い】
- 体重の正式事実は日々の体重記録のみ。プロフィール現在体重は存在しない
- 単日の前日比だけで脂肪増減と断定しない
- weight_evidence.change_kg は記録上の差分であり、脂肪減少の証明ではない
- trend_status=insufficient_data のときは傾向を断定しない

【回答形式】
- 日本語で、チャットらしい短めの文体。改行で読みやすくする
- 食事評価や「痩せる？」では基本順序: (1)一言で質問へ答える (2)記録食品を根拠に理由 (3)必要な場合のみPFC参考推定 (4)最後に実行しやすいアドバイスを原則1つ
- 短い質問には短く答える。毎回答を長い定型文にしない
- 失敗を責めず、不安を強めない
- 極端な食事制限や医療行為の代替は勧めない

【未記録への言及ルール】
- 18時前の当日未記録言及抑制は、最終ユーザーメッセージの時間帯ルールに従う
- 18時以降でも、未記録を毎回答で機械的に述べない。回答に本当に必要なときだけ最後に短く補足する

【安全性】
- 極端な食事制限、医療行為の代替、危険な減量法は勧めない
- ユーザーを責めない
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
        // System は恒久ルール + プロフィール要点中心。巨大な正式記録JSON/textは最終User Messageへ集約する。
        $system = self::SYSTEM_PROMPT
            . "\n\n【今回解決された対象期間】\n"
            . sprintf(
                '%s 〜 %s（scope_type=%s / original=%s）',
                $scope->startDateString(),
                $scope->endDateString(),
                $scope->type->value,
                $scope->originalExpression,
            )
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
        $start14 = $today->modify('-13 days');
        $start14Str = $start14->format('Y-m-d');
        $start6mStr = $today->modify('-6 months')->format('Y-m-d');

        $mealRows6m = $this->mealEntryRepository->findBetween($start6mStr, $todayStr);
        $nutritionRows = $this->dailyNutritionSummaryRepository->getBetween($start14Str, $todayStr);
        $nutritionByDate14 = [];
        foreach ($nutritionRows as $row) {
            $nutritionByDate14[(string) $row['recordedOn']] = $row;
        }

        $weightPoints = $this->weightRepository->getPointsBetween($start6mStr, $todayStr);
        $weightByDate6m = [];
        foreach ($weightPoints as $point) {
            $weightByDate6m[(string) $point['date']] = $point['value'];
        }

        $stepsByDate14 = [];
        $exercisesByDate14 = [];
        $cursor = $start14;
        while ($cursor <= $today) {
            $date = $cursor->format('Y-m-d');
            $stepsByDate14[$date] = $this->activityRepository->getStepsForDate($date);
            $exercisesByDate14[$date] = $this->activityRepository->getExercisesForDate($date);
            $cursor = $cursor->modify('+1 day');
        }

        $stepsCountByDate6m = [];
        foreach ($this->activityRepository->getDailyStepsBetween($start6mStr, $todayStr) as $point) {
            $stepsCountByDate6m[(string) $point['date']] = (int) $point['value'];
        }

        $exerciseKcalByDate6m = [];
        foreach ($this->activityRepository->getDailyExerciseCaloriesBetween($start6mStr, $todayStr) as $point) {
            $exerciseKcalByDate6m[(string) $point['date']] = (int) $point['value'];
        }

        return $this->recordContextBuilder->buildLayered(
            $scope,
            $today,
            $mealRows6m,
            $nutritionByDate14,
            $weightByDate6m,
            $stepsByDate14,
            $exercisesByDate14,
            $stepsCountByDate6m,
            $exerciseKcalByDate6m,
            $this->buildProfileSnapshot(),
            [
                'first_meal_recorded_on' => $this->mealEntryRepository->getEarliestRecordedDate(),
                'first_weight_recorded_on' => $this->weightRepository->getEarliestRecordedDate(),
                'first_steps_recorded_on' => $this->activityRepository->getEarliestStepsRecordedDate(),
                'first_exercise_recorded_on' => $this->activityRepository->getEarliestExerciseRecordedDate(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfileSnapshot(): array
    {
        $profile = $this->userProfileRepository->get();
        $calorieGoal = $this->calculateCalorieGoal($profile);

        return [
            'gender' => $this->formatGender($profile['gender'] ?? null),
            'birth_date' => $profile['birthDate'] ?? null,
            'age_years' => $calorieGoal['ageYears'],
            'height_cm' => $profile['heightCm'] ?? null,
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
        $calorieGoal = $this->calculateCalorieGoal($profile);
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
     * @param array<string, mixed> $profile
     * @return array{
     *   ageYears: int|null,
     *   bmrKcal: int|null,
     *   tdeeKcal: int|null,
     *   dailyDeficitKcal: int|null,
     *   dailyIntakeGoalKcal: int|null,
     *   isComplete: bool
     * }
     */
    private function calculateCalorieGoal(array $profile): array
    {
        return CalorieGoalCalculator::calculate([
            ...$profile,
            'weightKg' => $this->resolveWeightKgForCalorieGoal(),
        ]);
    }

    private function resolveWeightKgForCalorieGoal(): ?float
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
        $summary = $this->weightRepository->getSummaryForDate($today);
        if (is_numeric($summary['current'] ?? null)) {
            return round((float) $summary['current'], 1);
        }
        if (is_numeric($summary['referenceWeight'] ?? null)) {
            return round((float) $summary['referenceWeight'], 1);
        }

        return null;
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
        $pfc = is_array($authoritative['pfc_evidence'] ?? null) ? $authoritative['pfc_evidence'] : [];
        $weight = is_array($authoritative['weight_evidence'] ?? null) ? $authoritative['weight_evidence'] : [];
        $energy = is_array($authoritative['energy_evidence'] ?? null) ? $authoritative['energy_evidence'] : [];
        $perms = is_array($authoritative['answer_permissions'] ?? null)
            ? $authoritative['answer_permissions']
            : [];
        $payload = [
            'event' => 'ai_chat_record_scope_resolved',
            'scope_type' => $scope->type->value,
            'start_date' => $scope->startDateString(),
            'end_date' => $scope->endDateString(),
            'original_expression' => $scope->originalExpression,
            'question_preview' => $questionPreview,
            'meal_count' => (int) ($authoritative['meal_count'] ?? 0),
            'pfc_status' => (string) ($pfc['status'] ?? 'none'),
            'registered_pfc_entry_count' => (int) ($pfc['registered_pfc_entry_count'] ?? 0),
            'weight_record_count' => (int) ($weight['record_count'] ?? 0),
            'tdee_status' => (string) ($energy['tdee_status'] ?? 'unavailable'),
            'may_estimate_pfc_from_foods' => (bool) ($perms['may_estimate_pfc_from_foods'] ?? false),
            'may_estimate_energy_balance' => (bool) ($perms['may_estimate_energy_balance'] ?? false),
            'may_evaluate_weight_trend' => (bool) ($perms['may_evaluate_weight_trend'] ?? false),
            'may_assert_fat_loss' => (bool) ($perms['may_assert_fat_loss'] ?? false),
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
