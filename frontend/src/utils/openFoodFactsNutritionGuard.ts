/** 純脂肪の上限付近。これを超える kcal/100g は物理的にあり得ない。 */
export const MAX_PLAUSIBLE_KCAL_PER_100G = 900;

/** kcal と kJ の換算係数（1 kcal ≒ 4.184 kJ） */
const KJ_PER_KCAL = 4.184;

/** kcal と kJ がこの相対差を超えたら矛盾とみなす */
const ENERGY_UNIT_RELATIVE_TOLERANCE = 0.25;

/**
 * Atwater係数でのマクロ推定との相対差上限。
 * 食物繊維・アルコール等でずれるため密度チェックより緩め。
 */
const MACRO_RELATIVE_TOLERANCE = 0.4;

/** マクロ推定との絶対差がこれ未満なら相対差が大きくても許容 */
const MACRO_ABSOLUTE_TOLERANCE_KCAL = 50;

export interface OpenFoodFactsNutritionCheckInput {
  calories: number;
  amount: number;
  unit: string;
  energyKcal100g?: number | null;
  energyKcalServing?: number | null;
  energyKj100g?: number | null;
  energyKjServing?: number | null;
  proteinG?: number | null;
  fatG?: number | null;
  carbsG?: number | null;
}

function isPositiveFinite(value: number | null | undefined): value is number {
  return value != null && Number.isFinite(value) && value > 0;
}

function relativeDifference(a: number, b: number): number {
  const denom = Math.max(Math.abs(a), Math.abs(b), 1);
  return Math.abs(a - b) / denom;
}

/** g/ml 換算できるとき、kcal/100g(ml) が物理上限を超えていれば true */
export function hasImpossibleCalorieDensity(
  calories: number,
  amount: number,
  unit: string,
): boolean {
  if (!Number.isFinite(calories) || calories <= 0) return true;
  if (!Number.isFinite(amount) || amount <= 0) return false;

  const normalizedUnit = unit.trim().toLowerCase();
  if (normalizedUnit !== "g" && normalizedUnit !== "ml") return false;

  const kcalPer100 = (calories / amount) * 100;
  return kcalPer100 > MAX_PLAUSIBLE_KCAL_PER_100G;
}

/** kcal と kJ の両方があり、換算が大きく食い違うとき true */
export function hasInconsistentKcalAndKj(
  kcal: number | null | undefined,
  kj: number | null | undefined,
): boolean {
  if (!isPositiveFinite(kcal) || !isPositiveFinite(kj)) return false;
  const expectedKcal = kj / KJ_PER_KCAL;
  return relativeDifference(kcal, expectedKcal) > ENERGY_UNIT_RELATIVE_TOLERANCE;
}

/** P/F/C から推定した kcal と表示 kcal が大きく食い違うとき true */
export function hasInconsistentKcalAndMacros(
  calories: number,
  proteinG: number | null | undefined,
  fatG: number | null | undefined,
  carbsG: number | null | undefined,
): boolean {
  if (!Number.isFinite(calories) || calories <= 0) return false;
  if (
    !isPositiveFinite(proteinG) &&
    !isPositiveFinite(fatG) &&
    !isPositiveFinite(carbsG)
  ) {
    return false;
  }

  const estimated =
    4 * (proteinG ?? 0) + 9 * (fatG ?? 0) + 4 * (carbsG ?? 0);
  if (estimated <= 0) return false;

  const absoluteDiff = Math.abs(calories - estimated);
  if (absoluteDiff <= MACRO_ABSOLUTE_TOLERANCE_KCAL) return false;

  return relativeDifference(calories, estimated) > MACRO_RELATIVE_TOLERANCE;
}

/**
 * OFF 候補の栄養値が信頼できるか。
 * 1つでも矛盾があれば false（呼び出し側は候補を棄却する）。
 */
export function isOpenFoodFactsNutritionPlausible(
  input: OpenFoodFactsNutritionCheckInput,
): boolean {
  if (
    hasImpossibleCalorieDensity(input.calories, input.amount, input.unit)
  ) {
    return false;
  }

  if (
    hasInconsistentKcalAndKj(input.energyKcalServing, input.energyKjServing) ||
    hasInconsistentKcalAndKj(input.energyKcal100g, input.energyKj100g)
  ) {
    return false;
  }

  if (
    hasInconsistentKcalAndMacros(
      input.calories,
      input.proteinG,
      input.fatG,
      input.carbsG,
    )
  ) {
    return false;
  }

  return true;
}
