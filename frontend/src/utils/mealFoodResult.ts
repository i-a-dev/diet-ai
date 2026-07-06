import type { MealItemInput } from "../components/AddFoodModal.tsx";
import type {
  FoodSearchResult,
  FoodSource,
  SearchConfidence,
} from "../types/foodSearch.ts";
import { parseCaloriesEdited } from "./calorieSource.ts";

export function parseKcalFromString(kcal: string): number {
  const parsed = Number(kcal.replace(/kcal/i, "").trim());
  return Number.isFinite(parsed) ? Math.round(parsed) : 0;
}

export function mealItemToSearchResult(item: MealItemInput): FoodSearchResult {
  const calories = parseKcalFromString(item.kcal);
  const caloriesEdited = parseCaloriesEdited(item.caloriesEdited);
  const source = resolveMealItemSource(item);
  const confidence = resolveMealItemConfidence(item, source);

  return {
    id: `meal-item-${item.id ?? item.label}-${calories}`,
    name: item.label,
    displayName: item.label,
    amount: 1,
    unit: "食",
    calories,
    protein: null,
    fat: null,
    carbs: null,
    source,
    confidence,
    isEstimated:
      source === "claude_estimate" ||
      (source === "user_registered" && parseCaloriesEdited(item.caloriesEdited)),
    barcode: null,
    brandName: null,
    rawInput: item.label,
    sourceUrl: item.sourceUrl ?? null,
    caloriesEdited,
  };
}

function resolveMealItemSource(item: MealItemInput): FoodSource {
  const source = item.calorieSource;
  if (
    source === "regex" ||
    source === "fatsecret" ||
    source === "open_food_facts" ||
    source === "local_db" ||
    source === "claude_estimate" ||
    source === "ai_web_search" ||
    source === "user_registered"
  ) {
    return source;
  }

  if (parseCaloriesEdited(item.caloriesEdited)) {
    return "user_registered";
  }

  return "user_registered";
}

function resolveMealItemConfidence(
  item: MealItemInput,
  source: FoodSource,
): SearchConfidence {
  if (
    item.confidence === "high" ||
    item.confidence === "medium" ||
    item.confidence === "low"
  ) {
    return item.confidence;
  }

  if (source === "claude_estimate") {
    return "medium";
  }

  if (source === "ai_web_search") {
    return "high";
  }

  return "high";
}
