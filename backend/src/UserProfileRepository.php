<?php

declare(strict_types=1);

/**
 * ユーザープロフィールの読み書きを担当するクラス。
 */
final class UserProfileRepository
{
    /** @var list<string> */
    private const GENDERS = ['male', 'female', 'other'];

    /** @var list<string> */
    private const ACTIVITY_LEVELS = ['sedentary', 'light', 'moderate', 'active', 'very_active'];

    /** @var list<string> */
    private const DIET_GOALS = ['weight_loss', 'maintenance', 'muscle_gain', 'health'];

    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'gender',
        'birthDate',
        'heightCm',
        'currentWeightKg',
        'targetWeightKg',
    ];

    private PDO $db;
    private int $userId;

    public function __construct(int $userId, ?PDO $db = null)
    {
        $this->userId = $userId;
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array{
     *   gender: string|null,
     *   birthDate: string|null,
     *   heightCm: float|null,
     *   currentWeightKg: float|null,
     *   targetWeightKg: float|null,
     *   activityLevel: string|null,
     *   targetPaceKgPerMonth: float|null,
     *   dietGoal: string|null,
     *   desiredDietMethod: string|null,
     *   allergiesDislikes: string|null,
     *   pastDietExperience: string|null,
     *   coachNotes: string|null,
     *   isComplete: bool,
     *   updatedAt: string|null
     * }
     */
    public function get(): array
    {
        $statement = $this->db->prepare(
            'SELECT gender, birth_date, height_cm, current_weight_kg, target_weight_kg,
                    activity_level, target_pace_kg_per_month, diet_goal, desired_diet_method,
                    allergies_dislikes, past_diet_experience, coach_notes, updated_at
             FROM user_profile
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $this->userId]);
        $row = $statement->fetch();

        if ($row === false) {
            return $this->emptyProfile();
        }

        return $this->mapRow($row);
    }

    /**
     * プロフィールを更新する。未指定の項目は現在値を維持する。
     *
     * @param array<string, mixed> $fields
     * @return array{
     *   gender: string|null,
     *   birthDate: string|null,
     *   heightCm: float|null,
     *   currentWeightKg: float|null,
     *   targetWeightKg: float|null,
     *   activityLevel: string|null,
     *   targetPaceKgPerMonth: float|null,
     *   dietGoal: string|null,
     *   desiredDietMethod: string|null,
     *   allergiesDislikes: string|null,
     *   pastDietExperience: string|null,
     *   coachNotes: string|null,
     *   isComplete: bool,
     *   updatedAt: string|null
     * }
     */
    public function update(array $fields): array
    {
        $current = $this->get();
        $merged = $this->mergeFields($current, $fields);
        $this->validate($merged);

        $statement = $this->db->prepare(
            'INSERT INTO user_profile (
                user_id, gender, birth_date, height_cm, current_weight_kg, target_weight_kg,
                activity_level, target_pace_kg_per_month, diet_goal, desired_diet_method,
                allergies_dislikes, past_diet_experience, coach_notes, updated_at
             )
             VALUES (
                :user_id, :gender, :birth_date, :height_cm, :current_weight_kg, :target_weight_kg,
                :activity_level, :target_pace_kg_per_month, :diet_goal, :desired_diet_method,
                :allergies_dislikes, :past_diet_experience, :coach_notes, datetime(\'now\')
             )
             ON CONFLICT(user_id) DO UPDATE SET
                 gender = excluded.gender,
                 birth_date = excluded.birth_date,
                 height_cm = excluded.height_cm,
                 current_weight_kg = excluded.current_weight_kg,
                 target_weight_kg = excluded.target_weight_kg,
                 activity_level = excluded.activity_level,
                 target_pace_kg_per_month = excluded.target_pace_kg_per_month,
                 diet_goal = excluded.diet_goal,
                 desired_diet_method = excluded.desired_diet_method,
                 allergies_dislikes = excluded.allergies_dislikes,
                 past_diet_experience = excluded.past_diet_experience,
                 coach_notes = excluded.coach_notes,
                 updated_at = datetime(\'now\')'
        );
        $statement->execute([
            'user_id' => $this->userId,
            'gender' => $merged['gender'],
            'birth_date' => $merged['birthDate'],
            'height_cm' => $merged['heightCm'],
            'current_weight_kg' => $merged['currentWeightKg'],
            'target_weight_kg' => $merged['targetWeightKg'],
            'activity_level' => $merged['activityLevel'],
            'target_pace_kg_per_month' => $merged['targetPaceKgPerMonth'],
            'diet_goal' => $merged['dietGoal'],
            'desired_diet_method' => $merged['desiredDietMethod'],
            'allergies_dislikes' => $merged['allergiesDislikes'],
            'past_diet_experience' => $merged['pastDietExperience'],
            'coach_notes' => $merged['coachNotes'],
        ]);

        return $this->get();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   gender: string|null,
     *   birthDate: string|null,
     *   heightCm: float|null,
     *   currentWeightKg: float|null,
     *   targetWeightKg: float|null,
     *   activityLevel: string|null,
     *   targetPaceKgPerMonth: float|null,
     *   dietGoal: string|null,
     *   desiredDietMethod: string|null,
     *   allergiesDislikes: string|null,
     *   pastDietExperience: string|null,
     *   coachNotes: string|null,
     *   isComplete: bool,
     *   updatedAt: string|null
     * }
     */
    private function mapRow(array $row): array
    {
        $profile = [
            'gender' => $this->nullableString($row['gender'] ?? null),
            'birthDate' => $this->nullableString($row['birth_date'] ?? null),
            'heightCm' => $this->nullableFloat($row['height_cm'] ?? null),
            'currentWeightKg' => $this->nullableFloat($row['current_weight_kg'] ?? null),
            'targetWeightKg' => $this->nullableFloat($row['target_weight_kg'] ?? null),
            'activityLevel' => $this->nullableString($row['activity_level'] ?? null),
            'targetPaceKgPerMonth' => $this->nullableFloat($row['target_pace_kg_per_month'] ?? null),
            'dietGoal' => $this->nullableString($row['diet_goal'] ?? null),
            'desiredDietMethod' => $this->nullableTrimmedText($row['desired_diet_method'] ?? null),
            'allergiesDislikes' => $this->nullableTrimmedText($row['allergies_dislikes'] ?? null),
            'pastDietExperience' => $this->nullableTrimmedText($row['past_diet_experience'] ?? null),
            'coachNotes' => $this->nullableTrimmedText($row['coach_notes'] ?? null),
            'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
        $profile['isComplete'] = $this->computeIsComplete($profile);

        return $profile;
    }

    /**
     * @return array{
     *   gender: string|null,
     *   birthDate: string|null,
     *   heightCm: float|null,
     *   currentWeightKg: float|null,
     *   targetWeightKg: float|null,
     *   activityLevel: string|null,
     *   targetPaceKgPerMonth: float|null,
     *   dietGoal: string|null,
     *   desiredDietMethod: string|null,
     *   allergiesDislikes: string|null,
     *   pastDietExperience: string|null,
     *   coachNotes: string|null,
     *   isComplete: bool,
     *   updatedAt: string|null
     * }
     */
    private function emptyProfile(): array
    {
        $profile = [
            'gender' => null,
            'birthDate' => null,
            'heightCm' => null,
            'currentWeightKg' => null,
            'targetWeightKg' => null,
            'activityLevel' => null,
            'targetPaceKgPerMonth' => null,
            'dietGoal' => null,
            'desiredDietMethod' => null,
            'allergiesDislikes' => null,
            'pastDietExperience' => null,
            'coachNotes' => null,
            'updatedAt' => null,
        ];
        $profile['isComplete'] = false;

        return $profile;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function mergeFields(array $current, array $fields): array
    {
        $merged = [
            'gender' => array_key_exists('gender', $fields) ? $fields['gender'] : $current['gender'],
            'birthDate' => array_key_exists('birthDate', $fields) ? $fields['birthDate'] : $current['birthDate'],
            'heightCm' => array_key_exists('heightCm', $fields) ? $fields['heightCm'] : $current['heightCm'],
            'currentWeightKg' => array_key_exists('currentWeightKg', $fields)
                ? $fields['currentWeightKg']
                : $current['currentWeightKg'],
            'targetWeightKg' => array_key_exists('targetWeightKg', $fields)
                ? $fields['targetWeightKg']
                : $current['targetWeightKg'],
            'activityLevel' => array_key_exists('activityLevel', $fields)
                ? $fields['activityLevel']
                : $current['activityLevel'],
            'targetPaceKgPerMonth' => array_key_exists('targetPaceKgPerMonth', $fields)
                ? $fields['targetPaceKgPerMonth']
                : $current['targetPaceKgPerMonth'],
            'dietGoal' => array_key_exists('dietGoal', $fields) ? $fields['dietGoal'] : $current['dietGoal'],
            'desiredDietMethod' => array_key_exists('desiredDietMethod', $fields)
                ? $fields['desiredDietMethod']
                : $current['desiredDietMethod'],
            'allergiesDislikes' => array_key_exists('allergiesDislikes', $fields)
                ? $fields['allergiesDislikes']
                : $current['allergiesDislikes'],
            'pastDietExperience' => array_key_exists('pastDietExperience', $fields)
                ? $fields['pastDietExperience']
                : $current['pastDietExperience'],
            'coachNotes' => array_key_exists('coachNotes', $fields)
                ? $fields['coachNotes']
                : $current['coachNotes'],
        ];

        if ($merged['gender'] === '') {
            $merged['gender'] = null;
        }
        if ($merged['birthDate'] === '') {
            $merged['birthDate'] = null;
        }
        if ($merged['activityLevel'] === '') {
            $merged['activityLevel'] = null;
        }
        if ($merged['dietGoal'] === '') {
            $merged['dietGoal'] = null;
        }
        if ($merged['desiredDietMethod'] === '') {
            $merged['desiredDietMethod'] = null;
        }
        if ($merged['allergiesDislikes'] === '') {
            $merged['allergiesDislikes'] = null;
        }
        if ($merged['pastDietExperience'] === '') {
            $merged['pastDietExperience'] = null;
        }
        if ($merged['coachNotes'] === '') {
            $merged['coachNotes'] = null;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function validate(array $profile): void
    {
        if ($profile['gender'] !== null && !in_array($profile['gender'], self::GENDERS, true)) {
            throw new InvalidArgumentException('gender is invalid');
        }

        if ($profile['birthDate'] !== null && !$this->isValidBirthDate((string) $profile['birthDate'])) {
            throw new InvalidArgumentException('birthDate must be YYYY-MM-DD');
        }

        if ($profile['activityLevel'] !== null && !in_array($profile['activityLevel'], self::ACTIVITY_LEVELS, true)) {
            throw new InvalidArgumentException('activityLevel is invalid');
        }

        if ($profile['dietGoal'] !== null && !in_array($profile['dietGoal'], self::DIET_GOALS, true)) {
            throw new InvalidArgumentException('dietGoal is invalid');
        }

        $this->validateWeight('heightCm', $profile['heightCm']);
        $this->validateWeight('currentWeightKg', $profile['currentWeightKg']);
        $this->validateWeight('targetWeightKg', $profile['targetWeightKg']);

        if (
            $profile['targetPaceKgPerMonth'] !== null
            && ($profile['targetPaceKgPerMonth'] < 0 || $profile['targetPaceKgPerMonth'] > 20)
        ) {
            throw new InvalidArgumentException('targetPaceKgPerMonth must be between 0 and 20');
        }

        $this->validateTextLength('desiredDietMethod', $profile['desiredDietMethod'], 100);
        $this->validateTextLength('allergiesDislikes', $profile['allergiesDislikes'], 100);
        $this->validateTextLength('pastDietExperience', $profile['pastDietExperience'], 100);
        $this->validateTextLength('coachNotes', $profile['coachNotes'], 100);
    }

    private function validateWeight(string $field, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (!is_numeric($value) || $value <= 0 || $value > 300) {
            throw new InvalidArgumentException(sprintf('%s must be between 0 and 300', $field));
        }
    }

    private function validateTextLength(string $field, mixed $value, int $max = 1000): void
    {
        if ($value === null) {
            return;
        }

        if (!is_string($value) || mb_strlen($value) > $max) {
            throw new InvalidArgumentException(sprintf('%s must be %d characters or less', $field, $max));
        }
    }

    private function isValidBirthDate(string $birthDate): bool
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $birthDate);

        return $date !== false && $date->format('Y-m-d') === $birthDate;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function computeIsComplete(array $profile): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $profile[$field] ?? null;
            if ($value === null || $value === '') {
                return false;
            }
        }

        return true;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 1);
    }

    private function nullableTrimmedText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
