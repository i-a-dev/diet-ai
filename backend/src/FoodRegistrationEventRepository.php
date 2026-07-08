<?php

declare(strict_types=1);

/**
 * food_registration_events テーブルの書き込みを担当する。
 */
final class FoodRegistrationEventRepository
{
    /** @var list<string> */
    private const ALLOWED_SELECTED_SOURCES = [
        'regex',
        'alias_db',
        'local_db',
        'fatsecret',
        'open_food_facts',
        'brave_html',
        'claude_web_search',
        'claude_estimate',
        'ai_web_search',
        'user_registered',
    ];

    private PDO $db;
    private int $userId;

    public function __construct(int $userId, ?PDO $db = null)
    {
        $this->userId = $userId;
        $this->db = $db ?? Database::connection();
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $entryContext
     */
    public function recordFromMetrics(
        int $mealEntryId,
        string $recordedOn,
        string $mealType,
        string $selectedFoodName,
        int $caloriesSaved,
        bool $caloriesEdited,
        ?string $calorieSourceSaved,
        ?int $foodId,
        array $metrics,
    ): void {
        $selectedSource = trim((string) ($metrics['selectedSource'] ?? 'user_registered'));
        if (!in_array($selectedSource, self::ALLOWED_SELECTED_SOURCES, true)) {
            $selectedSource = 'user_registered';
        }

        $rawInput = trim((string) ($metrics['rawInput'] ?? ''));
        $rawInputOrNull = $rawInput === '' ? null : $rawInput;

        $searchStartedAtMs = $metrics['searchStartedAt'] ?? null;
        $savedAtMs = (int) floor(microtime(true) * 1000);

        $durationMs = null;
        if (isset($metrics['durationMs']) && is_numeric($metrics['durationMs'])) {
            $durationMs = max(0, (int) $metrics['durationMs']);
        } elseif (is_numeric($searchStartedAtMs)) {
            $durationMs = max(0, $savedAtMs - (int) $searchStartedAtMs);
        }

        $searchStartedAt = is_numeric($searchStartedAtMs)
            ? (new DateTimeImmutable('@' . (string) intdiv((int) $searchStartedAtMs, 1000)))
                ->setTimezone(new DateTimeZone('Asia/Tokyo'))
                ->format('Y-m-d H:i:s')
            : $this->parseTimestampMs($searchStartedAtMs);

        $savedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

        $candidateCount = isset($metrics['candidateCount']) && is_numeric($metrics['candidateCount'])
            ? max(0, (int) $metrics['candidateCount'])
            : null;
        $selectedRank = isset($metrics['selectedCandidateRank']) && is_numeric($metrics['selectedCandidateRank'])
            ? max(1, (int) $metrics['selectedCandidateRank'])
            : null;
        $caloriesBeforeEdit = isset($metrics['caloriesBeforeEdit']) && is_numeric($metrics['caloriesBeforeEdit'])
            ? (int) round((float) $metrics['caloriesBeforeEdit'])
            : null;

        $errorType = trim((string) ($metrics['errorType'] ?? ''));
        $errorTypeOrNull = $errorType === '' ? null : mb_substr($errorType, 0, 100);

        $statement = $this->db->prepare(
            'INSERT INTO food_registration_events (
                user_id, meal_entry_id, recorded_on, meal_type, raw_input, selected_food_name,
                food_id, selected_source, calorie_source_saved, search_started_at, saved_at,
                duration_ms, candidate_count, selected_candidate_rank, calories_before_edit,
                calories_saved, calories_edited, used_alias, used_local_db, used_brave,
                used_claude_estimate, used_claude_web_search, web_search_count_delta, error_type,
                created_at
             ) VALUES (
                :user_id, :meal_entry_id, :recorded_on, :meal_type, :raw_input, :selected_food_name,
                :food_id, :selected_source, :calorie_source_saved, :search_started_at, :saved_at,
                :duration_ms, :candidate_count, :selected_candidate_rank, :calories_before_edit,
                :calories_saved, :calories_edited, :used_alias, :used_local_db, :used_brave,
                :used_claude_estimate, :used_claude_web_search, :web_search_count_delta, :error_type,
                :created_at
             )'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'meal_entry_id' => $mealEntryId,
            'recorded_on' => $recordedOn,
            'meal_type' => $mealType,
            'raw_input' => $rawInputOrNull,
            'selected_food_name' => mb_substr($selectedFoodName, 0, 255),
            'food_id' => $foodId,
            'selected_source' => $selectedSource,
            'calorie_source_saved' => $calorieSourceSaved,
            'search_started_at' => $searchStartedAt,
            'saved_at' => $savedAt,
            'duration_ms' => $durationMs,
            'candidate_count' => $candidateCount,
            'selected_candidate_rank' => $selectedRank,
            'calories_before_edit' => $caloriesBeforeEdit,
            'calories_saved' => $caloriesSaved,
            'calories_edited' => $caloriesEdited ? 1 : 0,
            'used_alias' => $this->boolFlag($metrics, 'usedAlias') ? 1 : 0,
            'used_local_db' => $this->boolFlag($metrics, 'usedLocalDb') ? 1 : 0,
            'used_brave' => $this->boolFlag($metrics, 'usedBrave') ? 1 : 0,
            'used_claude_estimate' => $this->boolFlag($metrics, 'usedClaudeEstimate') ? 1 : 0,
            'used_claude_web_search' => $this->boolFlag($metrics, 'usedClaudeWebSearch') ? 1 : 0,
            'web_search_count_delta' => isset($metrics['webSearchCountDelta']) && is_numeric($metrics['webSearchCountDelta'])
                ? max(0, (int) $metrics['webSearchCountDelta'])
                : 0,
            'error_type' => $errorTypeOrNull,
            'created_at' => $savedAt,
        ]);
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function boolFlag(array $metrics, string $key): bool
    {
        return filter_var($metrics[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function parseTimestampMs(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $ms = (int) $value;
            $seconds = intdiv($ms, 1000);
            $micro = ($ms % 1000) * 1000;

            return (new DateTimeImmutable('@' . $seconds))
                ->setTimezone(new DateTimeZone('Asia/Tokyo'))
                ->format('Y-m-d H:i:s')
                . '.' . str_pad((string) intdiv($micro, 1000), 3, '0', STR_PAD_LEFT);
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($trimmed, new DateTimeZone('Asia/Tokyo')))
                ->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }
}
