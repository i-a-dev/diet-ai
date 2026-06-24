<?php

declare(strict_types=1);

/**
 * ユーザープロフィール（目標体重・身長など）の読み書きを担当するクラス。
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

    /**
     * @param array{targetWeightKg?: float|null, heightCm?: float|null} $fields
     *
     * @return array{
     *   targetWeightKg: float|null,
     *   heightCm: float|null,
     *   updatedAt: string|null
     * }
     */
    public function update(array $fields): array
    {
        $current = $this->get();
        $targetWeightKg = array_key_exists('targetWeightKg', $fields)
            ? $fields['targetWeightKg']
            : $current['targetWeightKg'];
        $heightCm = array_key_exists('heightCm', $fields)
            ? $fields['heightCm']
            : $current['heightCm'];

        if ($targetWeightKg !== null && ($targetWeightKg <= 0 || $targetWeightKg > 300)) {
            throw new InvalidArgumentException('targetWeightKg must be between 0 and 300 kg.');
        }

        if ($heightCm !== null && ($heightCm <= 0 || $heightCm > 300)) {
            throw new InvalidArgumentException('heightCm must be between 0 and 300 cm.');
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

        $this->db->prepare(
            'INSERT INTO user_profile (id, target_weight_kg, height_cm, updated_at)
             VALUES (:id, :target_weight_kg, :height_cm, :updated_at)
             ON CONFLICT(id) DO UPDATE SET
               target_weight_kg = excluded.target_weight_kg,
               height_cm = excluded.height_cm,
               updated_at = excluded.updated_at'
        )->execute([
            'id' => self::PROFILE_ID,
            'target_weight_kg' => $targetWeightKg,
            'height_cm' => $heightCm,
            'updated_at' => $now,
        ]);

        return $this->get();
    }
}
