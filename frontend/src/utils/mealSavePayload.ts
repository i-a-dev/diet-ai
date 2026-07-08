import type { FoodSearchResult } from "../types/foodSearch.ts";
import type { MealItemInput } from "../components/AddFoodModal.tsx";
import type { RegistrationMetrics } from "./registrationMetrics.ts";
import { toPersistedCalorieSource } from "./calorieSource.ts";

export interface SaveMealPayload {
  mealType: string;
  foodName: string;
  calories: number;
  date?: string;
  caloriesEdited?: boolean;
  calorieSource?: string | null;
  sourceUrl?: string | null;
  confidence?: string | null;
  foodId?: number | null;
  rawInput?: string | null;
  amount?: number | null;
  unit?: string | null;
  servingLabel?: string | null;
  servingWeightG?: number | null;
  proteinG?: number | null;
  fatG?: number | null;
  carbsG?: number | null;
  fiberG?: number | null;
  sodiumMg?: number | null;
  registrationMetrics?: RegistrationMetrics | null;
}

function buildServingLabel(amount: number | null, unit: string | null): string | null {
  if (amount == null || unit == null || unit === "") {
    return null;
  }
  return `${amount}${unit}`;
}

function resolveServingWeightG(amount: number | null, unit: string | null): number | null {
  if (amount == null || unit == null) {
    return null;
  }
  if (unit.toLowerCase() === "g") {
    return amount;
  }
  return null;
}

export function buildSaveMealPayloadFromResult(
  mealType: string,
  result: FoodSearchResult,
  options: {
    calories: number;
    caloriesEdited: boolean;
    date?: string;
    registrationMetrics?: RegistrationMetrics | null;
  },
): SaveMealPayload {
  return {
    mealType,
    foodName: result.displayName,
    calories: options.calories,
    date: options.date,
    caloriesEdited: options.caloriesEdited,
    calorieSource: toPersistedCalorieSource(result.source),
    sourceUrl: result.sourceUrl ?? null,
    confidence: result.confidence,
    foodId: result.selectedFoodId ?? null,
    rawInput: result.rawInput || null,
    amount: result.amount ?? null,
    unit: result.unit ?? null,
    servingLabel: buildServingLabel(result.amount ?? null, result.unit ?? null),
    servingWeightG: resolveServingWeightG(result.amount ?? null, result.unit ?? null),
    proteinG: result.protein ?? null,
    fatG: result.fat ?? null,
    carbsG: result.carbs ?? null,
    fiberG: result.fiber ?? null,
    sodiumMg: result.sodium ?? null,
    registrationMetrics: options.registrationMetrics ?? null,
  };
}

export function buildSaveMealPayloadFromManualInput(
  mealType: string,
  label: string,
  calories: number,
  options: {
    date?: string;
    rawInput?: string;
    registrationMetrics?: RegistrationMetrics | null;
  },
): SaveMealPayload {
  return {
    mealType,
    foodName: label,
    calories,
    date: options.date,
    caloriesEdited: true,
    calorieSource: "user_registered",
    confidence: "low",
    rawInput: options.rawInput ?? label,
    amount: 1,
    unit: "食",
    servingLabel: "1食",
    registrationMetrics: options.registrationMetrics ?? null,
  };
}

export function buildSaveMealPayloadFromHistoryItem(
  mealType: string,
  item: MealItemInput,
  options: {
    calories: number;
    caloriesEdited: boolean;
    date?: string;
    registrationMetrics?: RegistrationMetrics | null;
  },
): SaveMealPayload {
  return {
    mealType,
    foodName: item.label,
    calories: options.calories,
    date: options.date,
    caloriesEdited: options.caloriesEdited,
    calorieSource: item.calorieSource ?? "user_registered",
    sourceUrl: item.sourceUrl ?? null,
    confidence: item.confidence ?? null,
    foodId: item.foodId ?? null,
    rawInput: item.rawInput ?? item.label,
    amount: item.amount ?? null,
    unit: item.unit ?? null,
    servingLabel: item.servingLabel ?? null,
    servingWeightG: item.servingWeightG ?? null,
    proteinG: item.proteinG ?? null,
    fatG: item.fatG ?? null,
    carbsG: item.carbsG ?? null,
    fiberG: item.fiberG ?? null,
    sodiumMg: item.sodiumMg ?? null,
    registrationMetrics: options.registrationMetrics ?? null,
  };
}
