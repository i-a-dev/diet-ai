import { clearAuthToken, getAuthToken } from "../auth/tokenStorage.ts";

const API_BASE = import.meta.env.VITE_API_BASE_URL ?? "/api";

async function request<T>(path: string, options?: RequestInit): Promise<T> {
  const token = getAuthToken();
  const response = await fetch(`${API_BASE}${path}`, {
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options?.headers ?? {}),
    },
    ...options,
  });

  if (!response.ok) {
    if (response.status === 401 && token) {
      clearAuthToken();
    }
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
  caloriesEdited: boolean;
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
  caloriesEdited = false,
) {
  return request<SaveMealResponse>("/records/meals", {
    method: "POST",
    body: JSON.stringify({ mealType, foodName, calories, date, caloriesEdited }),
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
  return request<ExerciseHistoryResponse>(
    `/records/exercises/history${query ? `?${query}` : ""}`,
  );
}

export function fetchMealHistory(options?: {
  mealType?: MealType;
  limit?: number;
}) {
  const params = new URLSearchParams();
  if (options?.mealType) {
    params.set("mealType", options.mealType);
  }
  if (options?.limit) {
    params.set("limit", String(options.limit));
  }
  const query = params.toString();
  return request<MealHistoryResponse>(
    `/records/meals/history${query ? `?${query}` : ""}`,
  );
}

export interface CalorieEstimateResponse {
  kcal: number;
  assumed_weight_g?: number;
  confidence: "high" | "medium" | "low";
  product_name?: string;
}

export type CalorieEstimateMode = "auto" | "no_web" | "web";

export function estimateCalories(
  foodName: string,
  mode: CalorieEstimateMode = "auto",
) {
  return request<CalorieEstimateResponse>("/foods/estimate-calories", {
    method: "POST",
    body: JSON.stringify({ foodName, mode }),
  });
}

export interface UserFoodSummary {
  id: number;
  displayName: string;
  name: string;
  amount: number;
  unit: string;
  calories: number;
  source: string;
  rawInput: string | null;
}

export interface UserFoodSearchResponse {
  food: UserFoodSummary | null;
}

export function searchUserFoods(query: string) {
  const params = new URLSearchParams({ q: query });
  return request<UserFoodSearchResponse>(`/foods/search?${params.toString()}`);
}

export function saveUserFood(input: {
  displayName: string;
  name: string;
  amount: number;
  unit: string;
  calories: number;
  source?: string;
  rawInput?: string;
}) {
  return request<{ food: UserFoodSummary }>("/foods", {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export interface WeightChartPoint {
  label: string;
  value: number | null;
}

export interface WeightTimelinePoint extends WeightChartPoint {
  date: string;
}

export interface WeightTimelineResponse {
  weight: {
    points: WeightTimelinePoint[];
    targetWeightKg: number | null;
    chartMin: number;
    chartMax: number;
    scrollFloor: string;
  };
}

export function fetchWeightTimeline(endDate: string, visibleDays: number) {
  const query = `?endDate=${encodeURIComponent(endDate)}&visibleDays=${visibleDays}`;
  return request<WeightTimelineResponse>(`/reports/weight-timeline${query}`);
}

export type MetricTimelineType = "meals" | "exercise" | "steps";

export interface MetricTimelinePoint {
  label: string;
  value: number;
  date: string;
}

export interface MetricTimelineResponse {
  metric: MetricTimelineType;
  points: MetricTimelinePoint[];
  chartMax: number;
  average: number | null;
}

export function fetchMetricTimeline(
  metric: MetricTimelineType,
  endDate: string,
  visibleDays: number,
) {
  const query = new URLSearchParams({
    metric,
    endDate,
    visibleDays: String(visibleDays),
  });
  return request<MetricTimelineResponse>(`/reports/metric-timeline?${query}`);
}

export type Gender = "male" | "female" | "other";
export type ActivityLevel =
  | "sedentary"
  | "light"
  | "moderate"
  | "active"
  | "very_active";
export type DietGoal = "weight_loss" | "maintenance" | "muscle_gain" | "health";

export interface CalorieGoal {
  ageYears: number | null;
  bmrKcal: number | null;
  tdeeKcal: number | null;
  dailyDeficitKcal: number | null;
  dailyIntakeGoalKcal: number | null;
  isComplete: boolean;
}

export interface UserProfile {
  gender: Gender | null;
  birthDate: string | null;
  heightCm: number | null;
  currentWeightKg: number | null;
  targetWeightKg: number | null;
  activityLevel: ActivityLevel | null;
  targetPaceKgPerMonth: number | null;
  dietGoal: DietGoal | null;
  desiredDietMethod: string | null;
  allergiesDislikes: string | null;
  pastDietExperience: string | null;
  coachNotes: string | null;
  isComplete: boolean;
  updatedAt: string | null;
}

export type UserProfileUpdate = {
  gender?: Gender | null;
  birthDate?: string | null;
  heightCm?: number | null;
  currentWeightKg?: number | null;
  targetWeightKg?: number | null;
  activityLevel?: ActivityLevel | null;
  targetPaceKgPerMonth?: number | null;
  dietGoal?: DietGoal | null;
  desiredDietMethod?: string | null;
  allergiesDislikes?: string | null;
  pastDietExperience?: string | null;
  coachNotes?: string | null;
};

export function fetchUserProfile() {
  return request<{ profile: UserProfile; calorieGoal: CalorieGoal }>(
    "/user/profile",
  );
}

export function updateUserProfile(fields: UserProfileUpdate) {
  return request<{ profile: UserProfile; calorieGoal: CalorieGoal }>(
    "/user/profile",
    {
      method: "PUT",
      body: JSON.stringify(fields),
    },
  );
}

export type ChatRole = "user" | "assistant";

export interface ChatMessage {
  id: number;
  role: ChatRole;
  content: string;
  createdAt: string;
}

export function fetchChatMessages(limit?: number) {
  const query = limit ? `?limit=${limit}` : "";
  return request<{ messages: ChatMessage[] }>(`/chat/messages${query}`);
}

export function sendChatMessage(content: string) {
  return request<{
    userMessage: ChatMessage;
    assistantMessage: ChatMessage;
  }>("/chat", {
    method: "POST",
    body: JSON.stringify({ content }),
  });
}

export interface AuthUser {
  id: number;
  email: string;
  emailVerified?: boolean;
}

export interface RegisterResponse {
  requiresVerification: true;
  email: string;
  message: string;
}

export function login(email: string, password: string) {
  return request<{ token: string; user: AuthUser }>("/auth/login", {
    method: "POST",
    body: JSON.stringify({ email, password }),
  });
}

export function register(email: string, password: string) {
  return request<RegisterResponse>("/auth/register", {
    method: "POST",
    body: JSON.stringify({ email, password }),
  });
}

export function verifyEmail(token: string) {
  return request<{ token: string; user: AuthUser }>(
    `/auth/verify-email?token=${encodeURIComponent(token)}`,
  );
}

export function resendVerificationEmail(email: string) {
  return request<{ message: string }>("/auth/resend-verification", {
    method: "POST",
    body: JSON.stringify({ email }),
  });
}

export function requestPasswordReset(email: string) {
  return request<{ message: string }>("/auth/forgot-password", {
    method: "POST",
    body: JSON.stringify({ email }),
  });
}

export function resetPassword(token: string, password: string) {
  return request<{ message: string }>("/auth/reset-password", {
    method: "POST",
    body: JSON.stringify({ token, password }),
  });
}

export function logout() {
  return request<{ ok: boolean }>("/auth/logout", {
    method: "POST",
  });
}

export function fetchCurrentUser() {
  return request<{ user: AuthUser }>("/auth/me");
}
