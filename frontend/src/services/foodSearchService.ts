import { estimateCalories } from "../api/client.ts";
import { consumeWebSearchQuota, getWebSearchQuota } from "./webSearchQuotaService.ts";
import type {
  FoodSearchProgress,
  FoodSearchResult,
  FoodSearchStep,
  SearchConfidence,
  SearchState,
} from "../types/foodSearch.ts";

const FOOD_SEARCH_CACHE_KEY = "dietai.foodSearchCache.v2";
const FATSECRET_ENDPOINT = import.meta.env.VITE_FATSECRET_SEARCH_ENDPOINT as string | undefined;

interface ParsedFoodInput {
  name: string;
  amount: number;
  unit: string;
}

interface FatSecretFood {
  id: string;
  name: string;
  brandName?: string;
  calories: number;
  protein?: number;
  fat?: number;
  carbs?: number;
  amount: number;
  unit: string;
}

const FATSECRET_MOCK: FatSecretFood[] = [
  {
    id: "fatsecret-rice",
    name: "白米",
    calories: 156,
    protein: 2.5,
    fat: 0.3,
    carbs: 35.6,
    amount: 100,
    unit: "g",
  },
  {
    id: "fatsecret-salad-chicken",
    name: "サラダチキン",
    brandName: "セブン-イレブン",
    calories: 114,
    protein: 24.0,
    fat: 1.6,
    carbs: 0.3,
    amount: 100,
    unit: "g",
  },
];

type ProgressListener = (progress: FoodSearchProgress) => void;

function createSteps(): FoodSearchStep[] {
  return [
    { key: "regex_extracting", label: "入力内容を解析中", status: "pending" },
    { key: "fatsecret_searching", label: "FatSecretで食品データを検索中", status: "pending" },
    {
      key: "open_food_facts_searching",
      label: "Open Food Factsで商品情報を検索中",
      status: "pending",
    },
    { key: "claude_estimating", label: "AIでカロリーを推定中", status: "pending" },
    { key: "waiting_user_choice", label: "ユーザー選択を待機中", status: "pending" },
    { key: "ai_web_searching", label: "商品情報を検索中", status: "pending" },
  ];
}

function updateStep(
  steps: FoodSearchStep[],
  key: FoodSearchStep["key"],
  status: FoodSearchStep["status"],
): FoodSearchStep[] {
  return steps.map((step) => (step.key === key ? { ...step, status } : step));
}

function emitProgress(
  listener: ProgressListener | undefined,
  progress: FoodSearchProgress,
): FoodSearchProgress {
  listener?.(progress);
  return progress;
}

function parseFoodInputByRegex(input: string): ParsedFoodInput {
  const trimmed = input.trim();
  const matched = trimmed.match(/^(.+?)\s*(\d+(?:\.\d+)?)\s*(g|ml|個|杯|切れ|袋|本)$/i);
  if (!matched) {
    return {
      name: trimmed,
      amount: 1,
      unit: "食",
    };
  }

  return {
    name: matched[1].trim(),
    amount: Number(matched[2]),
    unit: matched[3],
  };
}

const MIN_FOOD_RELEVANCE_SCORE = 50;

function normalizeFoodText(text: string): string {
  return text.trim().toLowerCase().replace(/\u3000/g, " ").replace(/\s+/g, " ").normalize("NFKC");
}

function scoreFoodRelevance(query: string, candidateName: string): number {
  const q = normalizeFoodText(query);
  const name = normalizeFoodText(candidateName);
  if (q === "" || name === "") return 0;
  if (name === q) return 100;

  if (name.startsWith(q)) {
    const suffix = name.slice(q.length);
    if (suffix === "") return 100;
    if (/^[\d.]+(g|ml|個|杯|切れ|袋|本)?$/i.test(suffix)) return 90;
    if (suffix.length <= 2) return 75;
    return 45;
  }

  if (q.startsWith(name)) return 85;

  const qTokens = q.split(/\s+/);
  const nameTokens = name.split(/\s+/);
  if (qTokens.length === 1) {
    const token = qTokens[0];
    if (nameTokens.includes(token)) {
      const tokenIndex = nameTokens.indexOf(token);
      if (nameTokens.length === 1) return 95;
      if (tokenIndex === 0) return 80;
      return Math.max(20, 45 - (nameTokens.length - 2) * 5);
    }
    if (name.includes(token)) {
      if (name.startsWith(token)) return 45;
      return 15;
    }
  }

  if (qTokens.every((token) => name.includes(token))) return 50;
  return 0;
}

function pickBestRelevantCandidate<T>(
  query: string,
  candidates: T[],
  getName: (item: T) => string,
): T | null {
  let best: T | null = null;
  let bestScore = -1;

  for (const candidate of candidates) {
    const score = scoreFoodRelevance(query, getName(candidate));
    if (score > bestScore) {
      bestScore = score;
      best = candidate;
    }
  }

  return bestScore >= MIN_FOOD_RELEVANCE_SCORE ? best : null;
}

function makeCacheKey(input: string): string {
  return input.trim().toLowerCase();
}

function loadCache(): Record<string, FoodSearchResult> {
  const raw = localStorage.getItem(FOOD_SEARCH_CACHE_KEY);
  if (!raw) return {};
  try {
    return JSON.parse(raw) as Record<string, FoodSearchResult>;
  } catch {
    return {};
  }
}

function saveCache(cache: Record<string, FoodSearchResult>): void {
  localStorage.setItem(FOOD_SEARCH_CACHE_KEY, JSON.stringify(cache));
}

function storeResult(result: FoodSearchResult): void {
  const cache = loadCache();
  cache[makeCacheKey(result.rawInput)] = result;
  cache[makeCacheKey(result.name)] = result;
  saveCache(cache);
}

function resultFromEstimate(
  input: string,
  source: "claude_estimate" | "ai_web_search",
  estimate: {
    kcal: number;
    assumed_weight_g: number;
    confidence: SearchConfidence;
    product_name?: string;
  },
): FoodSearchResult {
  const productName = estimate.product_name?.trim();
  const displayBaseName = productName && productName.length > 0 ? productName : input;

  return {
    id: `${source}-${Date.now()}`,
    name: displayBaseName,
    displayName: `${displayBaseName}（${estimate.assumed_weight_g}g 想定）`,
    amount: estimate.assumed_weight_g,
    unit: "g",
    calories: estimate.kcal,
    protein: null,
    fat: null,
    carbs: null,
    source,
    confidence: estimate.confidence,
    isEstimated: true,
    rawInput: input,
  };
}

function resultFromFatSecret(food: FatSecretFood, parsed: ParsedFoodInput, rawInput: string): FoodSearchResult {
  const ratio = parsed.unit.toLowerCase() === food.unit.toLowerCase() ? parsed.amount / food.amount : 1;
  const ratioOrOne = Number.isFinite(ratio) && ratio > 0 ? ratio : 1;

  return {
    id: `fatsecret-${food.id}`,
    name: food.name,
    displayName: `${food.name} ${parsed.amount}${parsed.unit}`,
    amount: parsed.amount,
    unit: parsed.unit,
    calories: Math.round(food.calories * ratioOrOne),
    protein: food.protein == null ? null : Number((food.protein * ratioOrOne).toFixed(1)),
    fat: food.fat == null ? null : Number((food.fat * ratioOrOne).toFixed(1)),
    carbs: food.carbs == null ? null : Number((food.carbs * ratioOrOne).toFixed(1)),
    source: "fatsecret",
    confidence: "high",
    isEstimated: false,
    brandName: food.brandName,
    rawInput,
  };
}

function resultFromOpenFoodFacts(
  product: {
    code?: string;
    product_name?: string;
    brands?: string;
    serving_size?: string;
    serving_quantity?: number;
    serving_quantity_unit?: string;
    nutriments?: {
      "energy-kcal_100g"?: number;
      "energy-kcal_serving"?: number;
      proteins_100g?: number;
      fat_100g?: number;
      carbohydrates_100g?: number;
    };
  },
  parsed: ParsedFoodInput,
  rawInput: string,
): FoodSearchResult | null {
  const kcal100 = product.nutriments?.["energy-kcal_100g"];
  const kcalServing = product.nutriments?.["energy-kcal_serving"];
  const servingQuantity = Number(product.serving_quantity);
  const servingUnit = product.serving_quantity_unit?.trim();
  const hasKcal100 = Number.isFinite(kcal100) && (kcal100 ?? 0) > 0;
  const hasKcalServing = Number.isFinite(kcalServing) && (kcalServing ?? 0) > 0;
  const hasServingQuantity = Number.isFinite(servingQuantity) && servingQuantity > 0;

  if (!hasKcal100 && !hasKcalServing) return null;

  // 優先順位:
  // 1) energy-kcal_serving
  // 2) serving_quantity (+ energy-kcal_100g) で換算
  // 3) energy-kcal_100g（100g基準として表示）
  let calories = 0;
  let amount = 100;
  let unit = "g";
  let ratio = 1;
  let canScaleMacroBy100g = false;
  let displaySuffix = "100g（100g基準）";

  if (hasKcalServing) {
    calories = Math.round(kcalServing ?? 0);
    if (hasServingQuantity && servingUnit) {
      amount = servingQuantity;
      unit = servingUnit;
      ratio = servingUnit.toLowerCase() === "g" && hasKcal100 ? servingQuantity / 100 : 1;
      canScaleMacroBy100g = servingUnit.toLowerCase() === "g" && hasKcal100;
      displaySuffix = product.serving_size?.trim() || `${servingQuantity}${servingUnit}`;
    } else {
      amount = 1;
      unit = "食";
      ratio = 1;
      canScaleMacroBy100g = false;
      displaySuffix = product.serving_size?.trim() || "1食";
    }
  } else if (hasKcal100 && hasServingQuantity && servingUnit && servingUnit.toLowerCase() === "g") {
    ratio = servingQuantity / 100;
    canScaleMacroBy100g = true;
    calories = Math.round((kcal100 ?? 0) * ratio);
    amount = servingQuantity;
    unit = servingUnit;
    displaySuffix = product.serving_size?.trim() || `${servingQuantity}${servingUnit}`;
  } else if (hasKcal100) {
    calories = Math.round(kcal100 ?? 0);
    amount = 100;
    unit = "g";
    ratio = 1;
    canScaleMacroBy100g = true;
    displaySuffix = "100g（100g基準）";
  } else {
    return null;
  }

  return {
    id: `off-${product.code ?? Date.now().toString()}`,
    name: product.product_name ?? parsed.name,
    displayName: `${product.product_name ?? parsed.name} ${displaySuffix}`,
    amount,
    unit,
    calories,
    protein:
      product.nutriments?.proteins_100g == null || !canScaleMacroBy100g
        ? null
        : Number((product.nutriments.proteins_100g * ratio).toFixed(1)),
    fat:
      product.nutriments?.fat_100g == null || !canScaleMacroBy100g
        ? null
        : Number((product.nutriments.fat_100g * ratio).toFixed(1)),
    carbs:
      product.nutriments?.carbohydrates_100g == null || !canScaleMacroBy100g
        ? null
        : Number((product.nutriments.carbohydrates_100g * ratio).toFixed(1)),
    source: "open_food_facts",
    confidence: "high",
    isEstimated: false,
    brandName: product.brands,
    barcode: product.code,
    rawInput,
  };
}

async function searchFatSecret(query: string, parsed: ParsedFoodInput, rawInput: string): Promise<FoodSearchResult | null> {
  // 変更: FatSecret 失敗時もフロー継続できるよう、呼び出し失敗を上位で握りつぶす設計。
  if (FATSECRET_ENDPOINT) {
    const response = await fetch(`${FATSECRET_ENDPOINT}?q=${encodeURIComponent(query)}`);
    if (!response.ok) {
      throw new Error("FatSecret request failed");
    }
    const json = (await response.json()) as { foods?: FatSecretFood[] };
    const found = pickBestRelevantCandidate(query, json.foods ?? [], (food) => food.name);
    if (found) {
      return resultFromFatSecret(found, parsed, rawInput);
    }
  }

  const fallback = FATSECRET_MOCK.find((item) => query.includes(item.name));
  return fallback ? resultFromFatSecret(fallback, parsed, rawInput) : null;
}

async function searchOpenFoodFacts(
  query: string,
  parsed: ParsedFoodInput,
  rawInput: string,
): Promise<FoodSearchResult | null> {
  const url = `https://world.openfoodfacts.org/cgi/search.pl?search_terms=${encodeURIComponent(
    query,
  )}&search_simple=1&action=process&json=1&page_size=10`;
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error("Open Food Facts request failed");
  }

  const json = (await response.json()) as {
    products?: Array<{
      code?: string;
      product_name?: string;
      brands?: string;
      nutriments?: {
        "energy-kcal_100g"?: number;
        proteins_100g?: number;
        fat_100g?: number;
        carbohydrates_100g?: number;
      };
    }>;
  };
  const products = json.products ?? [];
  let best: { result: FoodSearchResult; score: number } | null = null;

  for (const product of products) {
    const name = product.product_name?.trim() ?? parsed.name;
    const score = scoreFoodRelevance(query, name);
    if (score < MIN_FOOD_RELEVANCE_SCORE) continue;

    const candidate = resultFromOpenFoodFacts(product, parsed, rawInput);
    if (!candidate) continue;
    if (!best || score > best.score) {
      best = { result: candidate, score };
    }
  }

  return best?.result ?? null;
}

async function estimateWithClaude(rawInput: string, parsed: ParsedFoodInput): Promise<FoodSearchResult> {
  try {
    const estimate = await estimateCalories(rawInput, "no_web");
    return resultFromEstimate(rawInput, "claude_estimate", estimate);
  } catch {
    // 変更: どのAPIが失敗しても最後は推定結果を返すため、簡易推定にフォールバック。
    const fallbackCalories = parsed.unit.toLowerCase() === "g" ? Math.max(Math.round(parsed.amount * 1.6), 80) : 220;
    return {
      id: `claude-fallback-${Date.now()}`,
      name: parsed.name,
      displayName: `${parsed.name} ${parsed.amount}${parsed.unit}（推定）`,
      amount: parsed.amount,
      unit: parsed.unit,
      calories: fallbackCalories,
      protein: null,
      fat: null,
      carbs: null,
      source: "claude_estimate",
      confidence: "low",
      isEstimated: true,
      rawInput,
    };
  }
}

function buildProgress(
  state: SearchState,
  steps: FoodSearchStep[],
  result: FoodSearchResult | null,
  message: string,
): FoodSearchProgress {
  return {
    state,
    steps,
    result,
    message,
  };
}

export async function searchFoodByText(
  rawInput: string,
  onProgress?: ProgressListener,
): Promise<FoodSearchProgress> {
  const input = rawInput.trim();
  let steps = createSteps();

  if (input === "") {
    return buildProgress("idle", steps, null, "食品名を入力してください");
  }

  steps = updateStep(steps, "regex_extracting", "active");
  emitProgress(onProgress, buildProgress("searching", steps, null, "食品データを検索中..."));
  const parsed = parseFoodInputByRegex(input);
  const query = parsed.name || input;
  steps = updateStep(steps, "regex_extracting", "done");

  try {
    steps = updateStep(steps, "fatsecret_searching", "active");
    emitProgress(onProgress, buildProgress("searching", steps, null, "食品データを検索中..."));
    const fatSecretResult = await searchFatSecret(query, parsed, input);
    steps = updateStep(steps, "fatsecret_searching", "done");
    if (fatSecretResult) {
      storeResult(fatSecretResult);
      return emitProgress(
        onProgress,
        buildProgress("found", steps, fatSecretResult, "候補が見つかりました"),
      );
    }
  } catch (error) {
    console.warn("FatSecret failed, fallback to Open Food Facts", error);
    steps = updateStep(steps, "fatsecret_searching", "done");
  }

  try {
    steps = updateStep(steps, "open_food_facts_searching", "active");
    emitProgress(onProgress, buildProgress("searching", steps, null, "食品データを検索中..."));
    const offResult = await searchOpenFoodFacts(query, parsed, input);
    steps = updateStep(steps, "open_food_facts_searching", "done");
    if (offResult) {
      storeResult(offResult);
      return emitProgress(onProgress, buildProgress("found", steps, offResult, "候補が見つかりました"));
    }
  } catch (error) {
    console.warn("Open Food Facts failed, fallback to Claude", error);
    steps = updateStep(steps, "open_food_facts_searching", "done");
  }

  steps = updateStep(steps, "claude_estimating", "active");
  emitProgress(onProgress, buildProgress("searching", steps, null, "食品データを検索中..."));
  const claudeResult = await estimateWithClaude(input, parsed);
  steps = updateStep(steps, "claude_estimating", "done");
  storeResult(claudeResult);

  if (claudeResult.confidence === "low") {
    steps = updateStep(steps, "waiting_user_choice", "active");
    return emitProgress(
      onProgress,
      buildProgress(
        "low_confidence_estimate",
        steps,
        claudeResult,
        "AI推定の精度が低い可能性があります",
      ),
    );
  }

  return emitProgress(onProgress, buildProgress("estimated", steps, claudeResult, "AIがカロリーを推定しました"));
}

export async function runAiWebSearch(
  rawInput: string,
  onProgress?: ProgressListener,
): Promise<FoodSearchProgress> {
  const input = rawInput.trim();
  let steps = createSteps();
  steps = updateStep(steps, "regex_extracting", "done");
  steps = updateStep(steps, "fatsecret_searching", "done");
  steps = updateStep(steps, "open_food_facts_searching", "done");
  steps = updateStep(steps, "claude_estimating", "done");
  steps = updateStep(steps, "waiting_user_choice", "done");
  steps = updateStep(steps, "ai_web_searching", "active");

  emitProgress(onProgress, buildProgress("web_searching", steps, null, "商品情報を検索中..."));
  const quota = getWebSearchQuota();

  try {
    if (quota.remainingCount > 0) {
      const webEstimate = await estimateCalories(input, "web");
      const result = resultFromEstimate(input, "ai_web_search", webEstimate);
      storeResult(result);
      consumeWebSearchQuota();
      return emitProgress(onProgress, {
        state: "web_found",
        steps: updateStep(steps, "ai_web_searching", "done"),
        result,
        message: "候補が見つかりました",
      });
    }
  } catch (error) {
    console.warn("AI web search failed, fallback to low confidence estimate", error);
  }

  const fallbackEstimate = await estimateWithClaude(input, parseFoodInputByRegex(input));
  return emitProgress(onProgress, {
    state: "low_confidence_estimate",
    steps: updateStep(steps, "ai_web_searching", "done"),
    result: fallbackEstimate,
    message: "Web検索しましたが、うまくヒットしませんでした。AI推定カロリーを表示しています。",
  });
}
