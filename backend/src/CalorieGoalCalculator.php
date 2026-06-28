<?php

declare(strict_types=1);

/**
 * プロフィールから基礎代謝・消費カロリー・目標摂取カロリーを算出する。
 */
final class CalorieGoalCalculator
{
  private const KCAL_PER_KG_FAT = 7700;
  private const DAYS_PER_MONTH = 30;

  /** @var array<string, float> */
  private const ACTIVITY_MULTIPLIERS = [
    'sedentary' => 1.2,
    'light' => 1.375,
    'moderate' => 1.55,
    'active' => 1.725,
    'very_active' => 1.9,
  ];

  /**
   * @param array{
   *   gender?: string|null,
   *   birthDate?: string|null,
   *   heightCm?: float|null,
   *   currentWeightKg?: float|null,
   *   activityLevel?: string|null,
   *   targetPaceKgPerMonth?: float|null
   * } $profile
   * @return array{
   *   ageYears: int|null,
   *   bmrKcal: int|null,
   *   tdeeKcal: int|null,
   *   dailyDeficitKcal: int|null,
   *   dailyIntakeGoalKcal: int|null,
   *   isComplete: bool
   * }
   */
  public static function calculate(array $profile): array
  {
    $ageYears = self::calculateAgeYears($profile['birthDate'] ?? null);
    $bmrKcal = self::calculateBmr(
      $profile['gender'] ?? null,
      $ageYears,
      $profile['heightCm'] ?? null,
      $profile['currentWeightKg'] ?? null,
    );
    $tdeeKcal = self::calculateTdee($bmrKcal, $profile['activityLevel'] ?? null);
    $dailyDeficitKcal = self::calculateDailyDeficit($profile['targetPaceKgPerMonth'] ?? null);
    $dailyIntakeGoalKcal = $tdeeKcal !== null && $dailyDeficitKcal !== null
      ? max(1200, (int) round($tdeeKcal - $dailyDeficitKcal))
      : null;

    return [
      'ageYears' => $ageYears,
      'bmrKcal' => $bmrKcal,
      'tdeeKcal' => $tdeeKcal,
      'dailyDeficitKcal' => $dailyDeficitKcal,
      'dailyIntakeGoalKcal' => $dailyIntakeGoalKcal,
      'isComplete' => $bmrKcal !== null && $tdeeKcal !== null && $dailyIntakeGoalKcal !== null,
    ];
  }

  private static function calculateAgeYears(?string $birthDate): ?int
  {
    if ($birthDate === null || $birthDate === '') {
      return null;
    }

    $birth = DateTimeImmutable::createFromFormat('Y-m-d', $birthDate);
    if ($birth === false) {
      return null;
    }

    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));

    return max(0, $birth->diff($today)->y);
  }

  private static function calculateBmr(
    ?string $gender,
    ?int $ageYears,
    ?float $heightCm,
    ?float $weightKg,
  ): ?int {
    if (
      $gender === null
      || $ageYears === null
      || $heightCm === null
      || $weightKg === null
      || !in_array($gender, ['male', 'female', 'other'], true)
    ) {
      return null;
    }

    $base = 10 * $weightKg + 6.25 * $heightCm - 5 * $ageYears;
    $bmr = match ($gender) {
      'male' => $base + 5,
      'female' => $base - 161,
      'other' => $base - 78,
    };

    return (int) round($bmr);
  }

  private static function calculateTdee(?int $bmrKcal, ?string $activityLevel): ?int
  {
    if ($bmrKcal === null || $activityLevel === null) {
      return null;
    }

    $multiplier = self::ACTIVITY_MULTIPLIERS[$activityLevel] ?? null;
    if ($multiplier === null) {
      return null;
    }

    return (int) round($bmrKcal * $multiplier);
  }

  private static function calculateDailyDeficit(?float $targetPaceKgPerMonth): ?int
  {
    if ($targetPaceKgPerMonth === null) {
      return null;
    }

    return (int) round($targetPaceKgPerMonth * self::KCAL_PER_KG_FAT / self::DAYS_PER_MONTH);
  }
}
