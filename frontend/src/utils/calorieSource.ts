import type { FoodSource } from "../types/foodSearch.ts";

const WEB_SEARCH_SOURCES = new Set<FoodSource>([
  "ai_web_search",
  "brave_html",
  "claude_web_search",
]);

export function parseCaloriesEdited(value: unknown): boolean {
  return value === true || value === 1 || value === "1" || value === "true";
}

export function isWebSearchSource(
  source: FoodSource | string | null | undefined,
): boolean {
  return source != null && WEB_SEARCH_SOURCES.has(source as FoodSource);
}

export function toPersistedCalorieSource(
  source: FoodSource | string | null | undefined,
): FoodSource | string | null {
  if (source == null) {
    return null;
  }

  if (isWebSearchSource(source)) {
    return "ai_web_search";
  }

  if (source === "alias_db") {
    return "alias_db";
  }

  return source;
}

export function formatConfidenceText(
  confidence: string | null | undefined,
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
  return (
    source === "ai_web_search" ||
    source === "claude_estimate" ||
    isWebSearchSource(source)
  );
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
  if (source === "alias_db") {
    return "よく選ばれている食品";
  }

  if (isWebSearchSource(source)) {
    return "AI Web検索で取得したカロリー";
  }

  if (
    source === "claude_estimate" ||
    (params.isEstimated &&
      source !== "fatsecret" &&
      source !== "open_food_facts" &&
      source !== "local_db" &&
      source !== "alias_db" &&
      !isWebSearchSource(source))
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
    isWebSearchSource(params.source) &&
    typeof params.sourceUrl === "string" &&
    params.sourceUrl.trim() !== ""
  );
}
