<?php

declare(strict_types=1);

/**
 * ユーザープロフィールの読み書きを担当するクラス。
 * 単一ユーザー想定のため id=1 の1行のみを扱う。
 */
final class UserProfileRepository
{
    private const PROFILE_ID = 1;

    /** @var list<string> */
    private const GENDERS = ['male', 'female', 'other'];

    /** @var list<string> */
    private const ACTIVITY_LEVELS = ['sedentary', 'light', 'moderate', 'active', 'very_active'];

    /** @var list<string> */
    private const DIET_GOALS = ['weight_loss', 'maintenance', 'muscle_gain', 'health'];

    /** @var list<string> */
    private const DIETARY_RESTRICTIONS = ['carb', 'fat', 'calorie'];

    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'gender',
        'birthDate',
        'heightCm',
        'currentWeightKg',
        'targetWeightKg',
        'activityLevel',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
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
     *   dietaryRestrictions: list<string>,
     *   allergiesDislikes: string|null,
     *   pastDietExperience: string|null,
     *   isComplete: bool,
     *   updatedAt: string|null
     * }
     */
    public function get(): array
    {
        $statement = $this->db->prepare(
            'SELECT gender, birth_date, height_cm, current_weight_kg, target_weight_kg,
                    activity_level, target_pace_kg_per_month, diet_goal, dietary_restrictions,
                    allergies_dislikes, past_diet_experience, updated_at
             FROM user_profile
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => self::PROFILE_ID]);
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
     *   dietaryRestrictions: list<string>,
     *   allergiesDislikes: string|null,
     *   pastDietExperience: string|null,
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
            'UPDATE user_profile
             SET gender = :gender,
                 birth_date = :birth_date,
                 height_cm = :height_cm,
                 current_weight_kg = :current_weight_kg,
                 target_weight_kg = :target_weight_kg,
                 activity_level = :activity_level,
                 target_pace_kg_per_month = :target_pace_kg_per_month,
                 diet_goal = :diet_goal,
                 dietary_restrictions = :dietary_restrictions,
                 allergies_dislikes = :allergies_dislikes,
                 past_diet_experience = :past_diet_experience,
                 updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $statement->execute([
            'gender' => $merged['gender'],
            'birth_date' => $merged['birthDate'],
            'height_cm' => $merged['heightCm'],
            'current_weight_kg' => $merged['currentWeightKg'],
            'target_weight_kg' => $merged['targetWeightKg'],
            'activity_level' => $merged['activityLevel'],
            'target_pace_kg_per_month' => $merged['targetPaceKgPerMonth'],
            'diet_goal' => $merged['dietGoal'],
            'dietary_restrictions' => $this->encodeDietaryRestrictions($merged['dietaryRestrictions']),
            'allergies_dislikes' => $merged['allergiesDislikes'],
            'past_diet_experience' => $merged['pastDietExperience'],
            'id' => self::PROFILE_ID,
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
     *   dietaryRestrictions: list<string>,
     *   allergiesDislikes: string|null,
     *   pastDietExperience: string|null,
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
            'dietaryRestrictions' => $this->decodeDietaryRestrictions($row['dietary_restrictions'] ?? null),
            'allergiesDislikes' => $this->nullableTrimmedText($row['allergies_dislikes'] ?? null),
            'pastDietExperience' => $this->nullableTrimmedText($row['past_diet_experience'] ?? null),
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
     *   dietaryRestrictions: list<string>,
     *   allergiesDislikes: string|null,
     *   pastDietExperience: string|null,
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
            'dietaryRestrictions' => [],
            'allergiesDislikes' => null,
            'pastDietExperience' => null,
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
            'dietaryRestrictions' => array_key_exists('dietaryRestrictions', $fields)
                ? $fields['dietaryRestrictions']
                : $current['dietaryRestrictions'],
            'allergiesDislikes' => array_key_exists('allergiesDislikes', $fields)
                ? $fields['allergiesDislikes']
                : $current['allergiesDislikes'],
            'pastDietExperience' => array_key_exists('pastDietExperience', $fields)
                ? $fields['pastDietExperience']
                : $current['pastDietExperience'],
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
        if ($merged['allergiesDislikes'] === '') {
            $merged['allergiesDislikes'] = null;
        }
        if ($merged['pastDietExperience'] === '') {
            $merged['pastDietExperience'] = null;
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

        if (!is_array($profile['dietaryRestrictions'])) {
            throw new InvalidArgumentException('dietaryRestrictions must be an array');
        }

        foreach ($profile['dietaryRestrictions'] as $restriction) {
            if (!is_string($restriction) || !in_array($restriction, self::DIETARY_RESTRICTIONS, true)) {
                throw new InvalidArgumentException('dietaryRestrictions contains an invalid value');
            }
        }

        $this->validateTextLength('allergiesDislikes', $profile['allergiesDislikes']);
        $this->validateTextLength('pastDietExperience', $profile['pastDietExperience']);
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

    private function validateTextLength(string $field, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (!is_string($value) || mb_strlen($value) > 1000) {
            throw new InvalidArgumentException(sprintf('%s must be 1000 characters or less', $field));
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

    /**
     * @return list<string>
     */
    private function decodeDietaryRestrictions(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $restrictions = [];
        foreach ($decoded as $item) {
            if (is_string($item) && in_array($item, self::DIETARY_RESTRICTIONS, true)) {
                $restrictions[] = $item;
            }
        }

        return $restrictions;
    }

    /**
     * @param list<string> $restrictions
     */
    private function encodeDietaryRestrictions(array $restrictions): ?string
    {
        if ($restrictions === []) {
            return null;
        }

        return json_encode(array_values($restrictions), JSON_UNESCAPED_UNICODE);
    }
}
