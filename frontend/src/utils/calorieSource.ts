import type { SearchConfidence } from "../types/foodSearch.ts";

export function parseCaloriesEdited(value: unknown): boolean {
  return value === true || value === 1 || value === "1" || value === "true";
}

export function formatConfidenceText(
  confidence: SearchConfidence | string | null | undefined,
): string | null {
  switch (confidence) {
    case "high":
      return "高";
    case "medium":
      return "中";
    case "low":
      return "低";
    default:
      return null;
  }
}

export function shouldShowConfidence(params: {
  caloriesEdited?: boolean;
  source?: string | null;
}): boolean {
  if (parseCaloriesEdited(params.caloriesEdited)) {
    return false;
  }

  const source = params.source ?? null;
  return source === "ai_web_search" || source === "claude_estimate";
}

export function getCalorieSourceLabel(params: {
  caloriesEdited?: boolean;
  source?: string | null;
  isEstimated?: boolean;
}): string | null {
  if (parseCaloriesEdited(params.caloriesEdited)) {
    return "手動で修正したカロリー";
  }

  const source = params.source ?? null;
  if (source === "ai_web_search") {
    return "AI Web検索で取得したカロリー";
  }

  if (
    source === "claude_estimate" ||
    (params.isEstimated &&
      source !== "fatsecret" &&
      source !== "open_food_facts" &&
      source !== "local_db" &&
      source !== "ai_web_search")
  ) {
    return "AI推定で取得したカロリー";
  }

  return null;
}

export function shouldShowCalorieSourceUrl(params: {
  caloriesEdited?: boolean;
  source?: string | null;
  sourceUrl?: string | null;
}): boolean {
  return (
    !parseCaloriesEdited(params.caloriesEdited) &&
    params.source === "ai_web_search" &&
    typeof params.sourceUrl === "string" &&
    params.sourceUrl.trim() !== ""
  );
}
