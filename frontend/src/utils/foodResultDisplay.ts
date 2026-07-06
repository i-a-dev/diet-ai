import type { FoodSearchResult } from "../types/foodSearch.ts";

export type FoodResultDisplayMode = "register" | "detail" | "history";

export function shouldUseEstimateCard(
  result: FoodSearchResult,
  mode: FoodResultDisplayMode,
): boolean {
  if (mode === "history") {
    return (
      result.source === "claude_estimate" || result.source === "user_registered"
    );
  }

  return result.source === "claude_estimate";
}
