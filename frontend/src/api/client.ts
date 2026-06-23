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
  referenceWeight?: number | null;
  referenceRecordedOn?: string | null;
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
  steps?: {
    count: number;
    burnedCalories: number;
  };
  exercises?: {
    entries: ExerciseEntrySummary[];
    burnedCalories: number;
  };
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

export interface StepsSummary {
  count: number;
  burnedCalories: number;
}

export interface ExerciseEntrySummary {
  id: number;
  name: string;
  amount: number;
  unit: "min" | "rep";
  minutes: number;
  mets: number;
  source: "local_db" | "llm_estimate";
  confidence: "high" | "medium" | "low";
  isEstimated: boolean;
  note: string | null;
  weightKg: number;
  weightSource: "current" | "reference" | "default";
  burnedCalories: number;
}

interface SaveStepsResponse {
  steps: StepsSummary;
}

interface SaveExerciseResponse {
  entry: ExerciseEntrySummary;
  exercises: {
    entries: ExerciseEntrySummary[];
    burnedCalories: number;
  };
  meta?: {
    weightKg: number;
    weightSource: "current" | "reference" | "default";
    weightRecordedOn: string | null;
    usedDefaultWeight: boolean;
    weightHint: string | null;
  };
}

export interface ExercisePreviewResponse {
  preview: {
    exercise: string;
    estimatedExercise: string;
    minutes: number;
    mets: number;
    confidence: "high" | "medium" | "low";
    note: string;
    source: "local_db" | "llm_estimate";
    isEstimated: boolean;
    caloriesBurned: number;
  };
  weight: {
    kg: number;
    source: "current" | "reference" | "default";
    recordedOn: string | null;
  };
}

export interface ExerciseHistoryEntry extends ExerciseEntrySummary {
  recordedOn: string;
}

interface ExerciseHistoryResponse {
  history: ExerciseHistoryEntry[];
}

export interface MealHistoryEntry {
  id: number;
  mealType: MealType;
  label: string;
  calories: number;
  recordedOn: string;
}

interface MealHistoryResponse {
  history: MealHistoryEntry[];
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

export function saveSteps(count: number, date?: string) {
  return request<SaveStepsResponse>("/records/steps", {
    method: "POST",
    body: JSON.stringify({ count, date }),
  });
}

export function saveExercise(
  exerciseName: string,
  amount: number,
  unit: "min" | "rep",
  date?: string,
) {
  return request<SaveExerciseResponse>("/records/exercises", {
    method: "POST",
    body: JSON.stringify({ exerciseName, amount, unit, date }),
  });
}

export function estimateExercisePreview(
  exerciseName: string,
  amount: number,
  unit: "min" | "rep",
  date?: string,
) {
  return request<ExercisePreviewResponse>("/records/exercises/preview", {
    method: "POST",
    body: JSON.stringify({ exerciseName, amount, unit, date }),
  });
}

export function fetchExerciseHistory(options?: { limit?: number }) {
  const params = new URLSearchParams();
  if (options?.limit) {
    params.set("limit", String(options.limit));
  }
  const query = params.toString();
  return request<ExerciseHistoryResponse>(`/records/exercises/history${query ? `?${query}` : ""}`);
}

export function fetchMealHistory(options?: { mealType?: MealType; limit?: number }) {
  const params = new URLSearchParams();
  if (options?.mealType) {
    params.set("mealType", options.mealType);
  }
  if (options?.limit) {
    params.set("limit", String(options.limit));
  }
  const query = params.toString();
  return request<MealHistoryResponse>(`/records/meals/history${query ? `?${query}` : ""}`);
}

export interface CalorieEstimateResponse {
  kcal: number;
  assumed_weight_g: number;
  confidence: "high" | "medium" | "low";
  product_name?: string;
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
