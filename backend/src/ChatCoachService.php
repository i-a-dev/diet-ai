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

【回答のルール】
- 日本語で、チャットらしい短めの文体で返答する
- 記録データに触れるときは具体的な数値や日付を引用する
- 失敗を責めず、次の一歩を一緒に考える
- 極端な食事制限や医療行為の代替は勧めない
- 不明な点は推測せず、記録の追加を優しく促す
- 改行を使って読みやすくする
TEXT;

    private UserProfileRepository $userProfileRepository;
    private WeightRepository $weightRepository;
    private MealEntryRepository $mealEntryRepository;
    private ActivityRepository $activityRepository;
    private ChatMessageRepository $chatMessageRepository;

    public function __construct(
        ?UserProfileRepository $userProfileRepository = null,
        ?WeightRepository $weightRepository = null,
        ?MealEntryRepository $mealEntryRepository = null,
        ?ActivityRepository $activityRepository = null,
        ?ChatMessageRepository $chatMessageRepository = null,
    ) {
        $this->userProfileRepository = $userProfileRepository ?? new UserProfileRepository();
        $this->weightRepository = $weightRepository ?? new WeightRepository();
        $this->mealEntryRepository = $mealEntryRepository ?? new MealEntryRepository();
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
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{role: string, content: string}
     */
    public function chat(array $messages): array
    {
        if ($messages === []) {
            throw new InvalidArgumentException('messages is required');
        }

        $normalized = $this->normalizeMessages($messages);
        $lastMessage = $normalized[array_key_last($normalized)];

        if (($lastMessage['role'] ?? '') !== 'user') {
            throw new InvalidArgumentException('The last message must be from the user');
        }

        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY が設定されていません。');
        }

        $context = $this->buildUserContext();
        $system = self::SYSTEM_PROMPT . "\n\n【ユーザーの記録データ】\n" . $context;

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => $normalized,
        ];

        $response = $this->postToAnthropic($payload, $apiKey);
        $text = $this->extractText($response);

        if ($text === '') {
            throw new RuntimeException('AIコーチからの応答を取得できませんでした。');
        }

        return [
            'role' => 'assistant',
            'content' => $text,
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

    private function buildUserContext(): string
    {
        $today = WeightRepository::todayDate();
        $timezone = new DateTimeZone('Asia/Tokyo');
        $startDate = (new DateTimeImmutable($today, $timezone))
            ->modify(sprintf('-%d days', self::RECENT_DAYS - 1))
            ->format('Y-m-d');

        $profile = $this->userProfileRepository->get();
        $todayWeight = $this->weightRepository->getSummaryForDate($today);
        $todayMeals = $this->mealEntryRepository->getSectionsForDate($today);
        $todaySteps = $this->activityRepository->getStepsForDate($today);
        $todayExercises = $this->activityRepository->getExercisesForDate($today);
        $weightPoints = $this->weightRepository->getPointsBetween($startDate, $today);
        $mealPoints = $this->mealEntryRepository->getDailyTotalsBetween($startDate, $today);
        $exercisePoints = $this->activityRepository->getDailyExerciseCaloriesBetween($startDate, $today);
        $stepPoints = $this->activityRepository->getDailyStepsBetween($startDate, $today);

        $lines = [];
        $lines[] = '今日の日付: ' . $today;
        $lines[] = '';

        $lines[] = '■ プロフィール';
        $lines[] = '性別: ' . $this->formatGender($profile['gender'] ?? null);
        $lines[] = '生年月日: ' . ($profile['birthDate'] ?? '未設定');
        $lines[] = '身長: ' . $this->formatNullableNumber($profile['heightCm'], 'cm');
        $lines[] = '現在の体重: ' . $this->formatNullableNumber($profile['currentWeightKg'], 'kg');
        $lines[] = '目標体重: ' . $this->formatNullableNumber($profile['targetWeightKg'], 'kg');
        $lines[] = '活動レベル: ' . $this->formatActivityLevel($profile['activityLevel'] ?? null);
        $lines[] = '目標ペース: ' . $this->formatNullableNumber($profile['targetPaceKgPerMonth'], 'kg/月');
        $lines[] = 'ダイエット目的: ' . $this->formatDietGoal($profile['dietGoal'] ?? null);
        $lines[] = '食事制限の仕方: ' . $this->formatDietaryRestrictions($profile['dietaryRestrictions'] ?? []);
        $lines[] = 'アレルギー・苦手食材: ' . ($profile['allergiesDislikes'] ?? '未設定');
        $lines[] = '過去のダイエット経験: ' . ($profile['pastDietExperience'] ?? '未設定');
        $lines[] = '';

        $lines[] = '■ 今日の記録 (' . $today . ')';
        $lines[] = $this->formatTodayWeight($todayWeight);
        $lines[] = $this->formatTodayMeals($todayMeals);
        $lines[] = $this->formatTodaySteps($todaySteps);
        $lines[] = $this->formatTodayExercises($todayExercises);
        $lines[] = '';

        $lines[] = '■ 直近' . self::RECENT_DAYS . '日の推移';
        $lines[] = $this->formatRecentWeight($weightPoints);
        $lines[] = $this->formatRecentMetric('食事カロリー', $mealPoints, 'kcal');
        $lines[] = $this->formatRecentMetric('運動消費カロリー', $exercisePoints, 'kcal');
        $lines[] = $this->formatRecentMetric('歩数', $stepPoints, '歩');

        return implode("\n", $lines);
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
     * @param array<int, array{id: string, name: string, calories: int, items: array<int, array{label: string, calories: int}>}> $sections
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
                static fn (array $item): string => ($item['label'] ?? '') . ' ' . ($item['calories'] ?? 0) . 'kcal',
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

    private function formatGender(?string $gender): string
    {
        return match ($gender) {
            'male' => '男性',
            'female' => '女性',
            'other' => 'その他',
            default => '未設定',
        };
    }

    private function formatActivityLevel(?string $level): string
    {
        return match ($level) {
            'sedentary' => 'ほとんど運動しない',
            'light' => '軽い運動（週1〜2回）',
            'moderate' => '中程度の運動（週3〜5回）',
            'active' => '激しい運動（週6〜7回）',
            'very_active' => '非常に激しい運動',
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
     * @param list<string> $restrictions
     */
    private function formatDietaryRestrictions(array $restrictions): string
    {
        if ($restrictions === []) {
            return '未設定';
        }

        $labels = array_map(
            static fn (string $restriction): string => match ($restriction) {
                'carb' => '糖質制限',
                'fat' => '脂質制限',
                'calorie' => 'カロリー制限',
                default => $restriction,
            },
            $restrictions
        );

        return implode('、', $labels);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postToAnthropic(array $payload, string $apiKey): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl 拡張が有効になっていません。');
        }

        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            throw new RuntimeException('AIコーチサービスへの接続を開始できませんでした。');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException(
                $curlError !== ''
                    ? 'AIコーチサービスへの接続に失敗しました: ' . $curlError
                    : 'AIコーチサービスへの接続に失敗しました。',
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AIコーチサービスの応答を解析できませんでした。');
        }

        if ($httpCode >= 400) {
            $message = is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? 'AIコーチとの会話に失敗しました。')
                : 'AIコーチとの会話に失敗しました。';
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): string
    {
        $content = $response['content'] ?? [];
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }
}
