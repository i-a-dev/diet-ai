<?php

declare(strict_types=1);

/**
 * ユーザープロフィール（目標体重・身長など）の読み取りを担当するクラス。
 * 単一ユーザー想定のため id=1 の1行のみを扱う。
 */
final class UserProfileRepository
{
    private const PROFILE_ID = 1;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array{
     *   targetWeightKg: float|null,
     *   heightCm: float|null,
     *   updatedAt: string|null
     * }
     */
    public function get(): array
    {
        $statement = $this->db->prepare(
            'SELECT target_weight_kg, height_cm, updated_at
             FROM user_profile
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => self::PROFILE_ID]);
        $row = $statement->fetch();

        if ($row === false) {
            return [
                'targetWeightKg' => null,
                'heightCm' => null,
                'updatedAt' => null,
            ];
        }

        return [
            'targetWeightKg' => is_numeric($row['target_weight_kg'] ?? null)
                ? round((float) $row['target_weight_kg'], 1)
                : null,
            'heightCm' => is_numeric($row['height_cm'] ?? null)
                ? round((float) $row['height_cm'], 1)
                : null,
            'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }
}
