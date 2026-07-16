import type { FoodSearchResult, FoodSource } from "../types/foodSearch.ts";

export type FoodResultDisplayMode = "register" | "detail" | "history";

export function isExternalApiFoodSource(source: FoodSource | string): boolean {
  return source === "fatsecret" || source === "open_food_facts";
}

export function shouldUseEstimateCard(
  result: FoodSearchResult,
  mode: FoodResultDisplayMode,
): boolean {
  // 詳細・履歴は source に関わらず推定カード（手入力 / 登録）を出す。
  // 履歴で FatSecret 等だけ FoodSearchResultCard(detail) に落ちるとボタンが消えるため。
  if (mode === "detail" || mode === "history") {
    return true;
  }

  return result.source === "claude_estimate";
}
