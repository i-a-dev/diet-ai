import type { FoodSearchResult, FoodSource } from "../types/foodSearch.ts";

export type FoodResultDisplayMode = "register" | "detail" | "history";

export function isExternalApiFoodSource(source: FoodSource | string): boolean {
  return source === "fatsecret" || source === "open_food_facts";
}

export function shouldUseEstimateCard(
  result: FoodSearchResult,
  mode: FoodResultDisplayMode,
): boolean {
  if (mode === "detail") {
    return true;
  }

  if (mode === "history") {
    return (
      result.source === "claude_estimate" || result.source === "user_registered"
    );
  }

  return result.source === "claude_estimate";
}
