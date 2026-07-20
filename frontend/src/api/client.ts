import { clearAuthToken, getAuthToken } from "../auth/tokenStorage.ts";
import type { SaveMealPayload } from "../utils/mealSavePayload.ts";
import type { SearchConfidence } from "../types/foodSearch.ts";

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
  caloriesEdited?: boolean;
  calorieSource?: string | null;
  sourceUrl?: string | null;
  confidence?: string | null;
  foodId?: number | null;
  rawInput?: string | null;
  amount?: number | null;
  unit?: string | null;
  servingLabel?: string | null;
  servingWeightG?: number | null;
  proteinG?: number | null;
  fatG?: number | null;
  carbsG?: number | null;
  fiberG?: number | null;
  sodiumMg?: number | null;
}

export interface MealSectionSummary {
  id: MealType;
  name: string;
  calories: number;
  items: MealEntrySummary[];
}

export interface DailyNutritionSummary {
  recordedOn: string;
  totalKcal: number;
  totalProteinG: number | null;
  totalFatG: number | null;
  totalCarbsG: number | null;
  totalFiberG: number | null;
  totalSodiumMg: number | null;
  breakfastKcal: number;
  lunchKcal: number;
  dinnerKcal: number;
  snackKcal: number;
  mealEntryCount: number;
  estimatedEntryCount: number;
  editedEntryCount: number;
  lowConfidenceEntryCount: number;
  aiWebSearchEntryCount: number;
  userRegisteredEntryCount: number;
  pfcKnownEntryCount: number;
  topFoods: Array<{ name: string; kcal: number }> | null;
  summaryText: string | null;
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
  nutritionSummary?: DailyNutritionSummary | null;
}

interface SaveWeightResponse {
  weight: WeightSummary;
}

interface SaveMealResponse {
  entry: MealEntrySummary & { mealType?: MealType };
  meals: MealSectionSummary[];
  nutritionSummary?: DailyNutritionSummary;
}

interface DeleteMealResponse {
  recordedOn: string;
  meals: MealSectionSummary[];
  nutritionSummary?: DailyNutritionSummary;
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

export interface MealHistoryEntry extends MealEntrySummary {
  mealType: MealType;
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

export function saveMeal(payload: SaveMealPayload) {
  return request<SaveMealResponse>("/records/meals", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export function deleteMeal(entryId: number) {
  return request<DeleteMealResponse>(`/records/meals/${entryId}`, {
    method: "DELETE",
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
  burnedCalories?: number,
) {
  return request<SaveExerciseResponse>("/records/exercises", {
    method: "POST",
    body: JSON.stringify({
      exerciseName,
      amount,
      unit,
      date,
      ...(burnedCalories != null ? { burnedCalories } : {}),
    }),
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

export interface CalorieEstimateCandidate {
  product_name: string;
  brand?: string;
  kcal: number;
  source_url?: string;
  source_title?: string | null;
  source: "brave_html" | "claude_web_search" | "alias_db";
  identity_confidence: "high" | "medium" | "low";
  base_product_name?: string;
  variant_label?: string;
  variant_confidence?: "high" | "medium" | "low";
  variant_dimension?: string;
  serving_weight_g?: number | null;
  package_size?: string | null;
  alias_id?: number;
  verification_confidence?: "high" | "medium" | "low";
  evidence_text?: string | null;
  source_type?: string;
}

export interface CalorieEstimateResponse {
  kcal?: number;
  assumed_weight_g?: number;
  confidence?: "high" | "medium" | "low";
  product_name?: string;
  source_url?: string;
  source?: "brave_html" | "claude_web_search";
  identity_confidence?: "high" | "medium" | "low";
  base_product_name?: string;
  variant_label?: string;
  variant_confidence?: "high" | "medium" | "low";
  variant_dimension?: string;
  serving_weight_g?: number | null;
  package_size?: string | null;
  needs_confirmation?: boolean;
  reason?: "variant_ambiguous" | "identity_ambiguous";
  candidates?: CalorieEstimateCandidate[];
  web_search_status?:
    | "needs_variant_confirmation"
    | "confirmed"
    | "estimated_fallback"
    | "no_web_search";
  allow_manual_variant?: boolean;
  allow_estimated_add?: boolean;
  message_code?: string;
  allow_retry?: boolean;
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
  sourceUrl: string | null;
  brandName?: string | null;
  baseProductName?: string | null;
  variantLabel?: string | null;
  packageSize?: string | null;
  servingWeightG?: number | null;
}

export interface LocalDbSearchCandidateResponse {
  foodId: number;
  name: string;
  calories: number;
  source: "local_db";
  baseProductName: string;
  variantLabel: string;
  confidence: SearchConfidence;
  amount: number;
  unit: string;
  rawInput?: string | null;
  sourceUrl?: string | null;
  servingWeightG?: number | null;
  packageSize?: string | null;
}

export interface UserFoodSearchResponse {
  food: UserFoodSummary | null;
  candidates?: LocalDbSearchCandidateResponse[];
  needsConfirmation?: boolean;
  reason?: "variant_ambiguous" | "identity_ambiguous" | null;
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
  sourceUrl?: string | null;
}) {
  return request<{ food: UserFoodSummary }>("/foods", {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export interface AliasSearchCandidateResponse {
  aliasId: number;
  selectionCount: number;
  rejectedCount: number;
  confidenceScore: number;
  lastSelectedAt: string | null;
  source: string;
  food: UserFoodSummary;
}

export interface AliasSearchResponse {
  queryNormalized: string;
  candidates: AliasSearchCandidateResponse[];
  needsConfirmation: boolean;
  autoConfirm: boolean;
}

export function searchFoodAliases(query: string) {
  const params = new URLSearchParams({ q: query });
  return request<AliasSearchResponse>(
    `/foods/aliases/search?${params.toString()}`,
  );
}

export function upsertFoodAlias(input: {
  rawQuery: string;
  foodId: number;
  source?: string;
}) {
  return request<{
    alias: {
      id: number;
      queryNormalized: string;
      rawQuerySample: string;
      foodId: number;
      selectionCount: number;
      rejectedCount: number;
      confidenceScore: number;
      source: string;
      lastSelectedAt: string | null;
    };
    created: boolean;
  }>("/foods/aliases", {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export function selectFoodAlias(aliasId: number) {
  return request<{
    alias: {
      id: number;
      queryNormalized: string;
      rawQuerySample: string;
      foodId: number;
      selectionCount: number;
      rejectedCount: number;
      confidenceScore: number;
      source: string;
      lastSelectedAt: string | null;
    };
  }>(`/foods/aliases/${aliasId}/select`, {
    method: "POST",
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

export interface ChatStreamHandlers {
  onUserMessage?: (message: ChatMessage) => void;
  onDelta?: (text: string) => void;
  onAssistantMessage?: (message: ChatMessage) => void;
  onError?: (message: string) => void;
}

/**
 * AIコーチ返信を SSE（Server-Sent Events）で受信する。
 * delta イベントの text は LLM から届いたトークンそのもの。
 */
export async function sendChatMessageStream(
  content: string,
  handlers: ChatStreamHandlers,
): Promise<void> {
  const token = getAuthToken();
  const response = await fetch(`${API_BASE}/chat/stream`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "text/event-stream",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({ content }),
  });

  if (!response.ok) {
    if (response.status === 401 && token) {
      clearAuthToken();
    }
    const error = (await response.json().catch(() => null)) as {
      message?: string;
    } | null;
    throw new Error(error?.message ?? "メッセージの送信に失敗しました");
  }

  if (!response.body) {
    throw new Error("ストリーミング応答を取得できませんでした");
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  let sawError = false;

  const dispatchEvent = (eventName: string, data: string) => {
    if (!data) {
      return;
    }

    let payload: Record<string, unknown>;
    try {
      payload = JSON.parse(data) as Record<string, unknown>;
    } catch {
      return;
    }

    switch (eventName) {
      case "user_message":
        handlers.onUserMessage?.(payload as unknown as ChatMessage);
        break;
      case "delta": {
        const text = typeof payload.text === "string" ? payload.text : "";
        if (text !== "") {
          handlers.onDelta?.(text);
        }
        break;
      }
      case "assistant_message":
        handlers.onAssistantMessage?.(payload as unknown as ChatMessage);
        break;
      case "error": {
        sawError = true;
        const message =
          typeof payload.message === "string"
            ? payload.message
            : "メッセージの送信に失敗しました";
        handlers.onError?.(message);
        break;
      }
      default:
        break;
    }
  };

  while (true) {
    const { done, value } = await reader.read();
    if (done) {
      break;
    }

    buffer += decoder.decode(value, { stream: true });

    // SSE は空行でイベント区切り
    while (true) {
      const separatorIndex = buffer.indexOf("\n\n");
      if (separatorIndex === -1) {
        break;
      }

      const rawEvent = buffer.slice(0, separatorIndex);
      buffer = buffer.slice(separatorIndex + 2);

      let eventName = "message";
      const dataLines: string[] = [];

      for (const line of rawEvent.split("\n")) {
        if (line.startsWith("event:")) {
          eventName = line.slice(6).trim();
        } else if (line.startsWith("data:")) {
          dataLines.push(line.slice(5).trimStart());
        }
      }

      dispatchEvent(eventName, dataLines.join("\n"));
    }
  }

  if (buffer.trim() !== "") {
    let eventName = "message";
    const dataLines: string[] = [];
    for (const line of buffer.split("\n")) {
      if (line.startsWith("event:")) {
        eventName = line.slice(6).trim();
      } else if (line.startsWith("data:")) {
        dataLines.push(line.slice(5).trimStart());
      }
    }
    dispatchEvent(eventName, dataLines.join("\n"));
  }

  if (sawError) {
    return;
  }
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

export interface DeleteAccountPayload {
  password: string;
  confirmation: string;
}

export function deleteAccount(payload: DeleteAccountPayload) {
  return request<{ ok: true; message: string }>("/auth/account", {
    method: "DELETE",
    body: JSON.stringify(payload),
  });
}

export type ContactCategory =
  | "app_usage"
  | "bug"
  | "billing"
  | "account"
  | "ai_coach"
  | "other";

export interface ContactInquiryPayload {
  category: ContactCategory;
  subject: string;
  body: string;
  replyEmail: string;
  /** スパム対策 honeypot。人間は空のまま */
  honeypot?: string;
}

export function submitContactInquiry(payload: ContactInquiryPayload) {
  return request<{ ok: true; message: string }>("/contact", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}
