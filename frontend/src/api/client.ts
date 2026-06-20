const API_BASE = import.meta.env.VITE_API_BASE_URL ?? "/api";

async function request<T>(path: string, options?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE}${path}`, {
    headers: {
      "Content-Type": "application/json",
      ...(options?.headers ?? {}),
    },
    ...options,
  });

  if (!response.ok) {
    const error = (await response.json().catch(() => null)) as {
      message?: string;
    } | null;
    throw new Error(error?.message ?? "リクエストに失敗しました");
  }

  return response.json() as Promise<T>;
}

interface WeightSummary {
  current: number | null;
  diffFromPreviousDay: number | null;
  recordedOn: string;
  dateLabel: string;
}

export type MealType = "breakfast" | "lunch" | "dinner" | "snack";

export interface MealEntrySummary {
  id: number;
  label: string;
  calories: number;
}

export interface MealSectionSummary {
  id: MealType;
  name: string;
  calories: number;
  items: MealEntrySummary[];
}

interface DailyRecordResponse {
  date: string;
  recordedOn: string;
  weight: WeightSummary;
  meals: MealSectionSummary[];
}

interface SaveWeightResponse {
  weight: WeightSummary;
}

interface SaveMealResponse {
  entry: {
    id: number;
    mealType: MealType;
    label: string;
    calories: number;
  };
  meals: MealSectionSummary[];
}

export function fetchDailyRecord(date?: string) {
  const query = date ? `?date=${encodeURIComponent(date)}` : "";
  return request<DailyRecordResponse>(`/records/daily${query}`);
}

export function saveWeight(weight: number, date?: string) {
  return request<SaveWeightResponse>("/records/weight", {
    method: "POST",
    body: JSON.stringify({ weight, date }),
  });
}

export function saveMeal(
  mealType: MealType,
  foodName: string,
  calories: number,
  date?: string,
) {
  return request<SaveMealResponse>("/records/meals", {
    method: "POST",
    body: JSON.stringify({ mealType, foodName, calories, date }),
  });
}

export interface CalorieEstimateResponse {
  kcal: number;
  assumed_weight_g: number;
  confidence: "high" | "medium" | "low";
}

export type CalorieEstimateMode = "auto" | "no_web" | "web";

export function estimateCalories(foodName: string, mode: CalorieEstimateMode = "auto") {
  return request<CalorieEstimateResponse>("/foods/estimate-calories", {
    method: "POST",
    body: JSON.stringify({ foodName, mode }),
  });
}

export interface FoodNormalizeItem {
  name: string;
  amount: number;
  unit: string;
}

export interface FoodNormalizeResponse {
  items: FoodNormalizeItem[];
}

export function normalizeFoodInput(foodName: string) {
  return request<FoodNormalizeResponse>("/foods/normalize", {
    method: "POST",
    body: JSON.stringify({ foodName }),
  });
}
