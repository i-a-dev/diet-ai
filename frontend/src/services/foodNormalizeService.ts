import { normalizeFoodInput, type FoodNormalizeItem } from "../api/client.ts";
import type { NormalizedFoodItem } from "../types/foodSearch.ts";

function fallbackNormalize(input: string): NormalizedFoodItem[] {
  const trimmed = input.trim();
  const matched = trimmed.match(/^(.+?)\s*(\d+(?:\.\d+)?)\s*(g|ml|個|杯|切れ)$/i);
  if (matched) {
    return [
      {
        name: matched[1].trim(),
        amount: Number(matched[2]),
        unit: matched[3],
      },
    ];
  }

  return [
    {
      name: trimmed,
      amount: 1,
      unit: "食",
    },
  ];
}

function sanitize(items: FoodNormalizeItem[]): NormalizedFoodItem[] {
  return items
    .filter((item) => item.name.trim() !== "" && Number.isFinite(item.amount) && item.amount > 0)
    .map((item) => ({
      name: item.name.trim(),
      amount: item.amount,
      unit: item.unit.trim() || "食",
    }));
}

export async function normalizeFoodItems(input: string): Promise<NormalizedFoodItem[]> {
  const trimmed = input.trim();
  if (trimmed === "") return [];

  try {
    const response = await normalizeFoodInput(trimmed);
    const sanitized = sanitize(response.items);
    if (sanitized.length > 0) {
      return sanitized;
    }
  } catch {
    // フォールバック: API 失敗時は簡易パーサーで継続。
  }

  return fallbackNormalize(trimmed);
}
