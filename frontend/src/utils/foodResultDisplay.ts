import type { FoodSearchResult, FoodSource } from "../types/foodSearch.ts";

export type FoodResultDisplayMode = "register" | "detail" | "history";

export function isExternalApiFoodSource(source: FoodSource | string): boolean {
  return source === "fatsecret" || source === "open_food_facts";
}

export type FoodEstimateCardVariant = "estimate" | "history" | "detail" | "found";

export function getFoodEstimateCardVariant(
  result: FoodSearchResult,
  mode: FoodResultDisplayMode,
): FoodEstimateCardVariant {
  if (mode === "history") {
    return "history";
  }
  if (mode === "detail") {
    return "detail";
  }
  if (result.source === "claude_estimate") {
    return "estimate";
  }
  return "found";
}
