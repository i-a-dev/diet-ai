import {
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
} from "react";
import { Loader2 } from "lucide-react";
import { BottomSheet } from "./BottomSheet.tsx";
import { FoodSearchStatus } from "./FoodSearchStatus.tsx";
import { FoodResultPreview } from "./FoodResultPreview.tsx";
import { LowConfidenceEstimateCard } from "./LowConfidenceEstimateCard.tsx";
import { ProductConfirmationCard } from "./ProductConfirmationCard.tsx";
import { WebSearchCandidateConfirmation } from "./WebSearchCandidateConfirmation.tsx";
import { ModalStateViewport } from "./ModalStateViewport.tsx";
import { ModalStateLayout } from "./ModalStateLayout.tsx";
import {
  modalLinkActionStyle,
  modalOutlineActionStyle,
  modalPrimaryActionStyle,
  modalSecondaryActionStyle,
  modalTextActionStyle,
} from "./foodModalActionStyles.ts";
import {
  buildResultFromSelectedAlias,
  buildResultFromSelectedCandidate,
  buildResultFromSelectedLocalDb,
  runAiWebSearch,
  searchFoodByText,
} from "../services/foodSearchService.ts";
import { toWebConfirmationCandidates } from "../utils/webSearchConfirmationCandidates.ts";
import type {
  AliasSearchCandidate,
  FoodConfirmationCandidate,
  FoodSearchProgress,
  FoodSearchResult,
  LocalDbSearchCandidate,
  SearchState,
} from "../types/foodSearch.ts";
import {
  estimateCalories,
  fetchMealHistory,
  saveUserFood,
  selectFoodAlias,
  upsertFoodAlias,
  type MealHistoryEntry,
  type MealType,
} from "../api/client.ts";
import {
  resolveAliasSourceForSave,
  shouldSaveFoodAlias,
} from "../utils/foodAliasUtils.ts";
import type { FoodSource, SearchConfidence } from "../types/foodSearch.ts";
import { mealItemToSearchResult } from "../utils/mealFoodResult.ts";
import {
  buildRegistrationMetricsFromSteps,
  type RegistrationMetrics,
} from "../utils/registrationMetrics.ts";
import {
  isWebSearchSource,
  parseCaloriesEdited,
  toPersistedCalorieSource,
} from "../utils/calorieSource.ts";
import {
  AI_WEB_SEARCH_COMPLETE_ANIMATION_MS,
  AI_WEB_SEARCH_LOADING_HINT,
  AI_WEB_SEARCH_LOADING_PHASES,
  resolveAiWebSearchLoadingState,
  type AiWebSearchLoadingPhase,
} from "../utils/aiWebSearchLoadingProgress.ts";
import {
  FOOD_SEARCH_LOADING_HINT,
  resolveFoodSearchLoadingState,
} from "../utils/foodSearchLoadingProgress.ts";

interface AiWebSearchLoadingUi {
  phase: AiWebSearchLoadingPhase;
  progressPercent: number;
  statusMessage: string;
  isFinishing: boolean;
}

function createInitialAiWebSearchLoadingUi(): AiWebSearchLoadingUi {
  const first = AI_WEB_SEARCH_LOADING_PHASES[0];
  return {
    phase: first.phase,
    progressPercent: first.startProgress,
    statusMessage: first.message,
    isFinishing: false,
  };
}

export interface MealItemInput {
  id?: number;
  label: string;
  kcal: string;
  caloriesEdited?: boolean;
  calorieSource?: FoodSource | string | null;
  sourceUrl?: string | null;
  confidence?: SearchConfidence | string | null;
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
  registrationMetrics?: import("../utils/registrationMetrics.ts").RegistrationMetrics | null;
}

interface AddFoodModalProps {
  open: boolean;
  mealType: MealType;
  mealTitle: string;
  currentMealKcal: number;
  currentTotalKcal: number;
  dailyGoalKcal: number | null;
  onClose: () => void;
  onSave: (item: MealItemInput) => Promise<void> | void;
}

interface RegistrationContext {
  searchStartedAt?: number;
  selectedSource?: string;
  candidateCount?: number;
  selectedCandidateRank?: number | null;
  webSearchCountDelta?: number;
}

const INITIAL_STEPS = [
  { key: "regex_extracting", label: "入力内容を解析中", status: "pending" },
  {
    key: "alias_db_searching",
    label: "よく選ばれている候補を検索中",
    status: "pending",
  },
  {
    key: "local_db_searching",
    label: "登録済み食品を検索中",
    status: "pending",
  },
  {
    key: "fatsecret_searching",
    label: "FatSecretで食品データを検索中",
    status: "pending",
  },
  {
    key: "open_food_facts_searching",
    label: "Open Food Factsで商品情報を検索中",
    status: "pending",
  },
  {
    key: "claude_estimating",
    label: "AIでカロリーを推定中",
    status: "pending",
  },
  {
    key: "waiting_user_choice",
    label: "ユーザー選択を待機中",
    status: "pending",
  },
  { key: "ai_web_searching", label: "商品情報を検索中", status: "pending" },
] as const;

function makeInitialProgress(): FoodSearchProgress {
  return {
    state: "idle",
    steps: INITIAL_STEPS.map((step) => ({ ...step })),
    result: null,
  };
}

export function AddFoodModal({
  open,
  mealType,
  mealTitle,
  currentMealKcal,
  currentTotalKcal,
  dailyGoalKcal,
  onClose,
  onSave,
}: AddFoodModalProps) {
  const [inputValue, setInputValue] = useState("");
  const [manualKcal, setManualKcal] = useState("");
  const [manualProtein, setManualProtein] = useState("");
  const [manualFat, setManualFat] = useState("");
  const [manualCarbs, setManualCarbs] = useState("");
  const [showMacroDetails, setShowMacroDetails] = useState(false);
  const [isPreSearchManual, setIsPreSearchManual] = useState(false);
  // 変更: 新しい検索フロー状態をモーダル内で一元管理。
  const [progress, setProgress] =
    useState<FoodSearchProgress>(makeInitialProgress);
  const [showManualEdit, setShowManualEdit] = useState(false);
  const [isManualEditingFromConfirmation, setIsManualEditingFromConfirmation] =
    useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [completedResult, setCompletedResult] =
    useState<FoodSearchResult | null>(null);
  const [historyTab, setHistoryTab] = useState<"recent" | "meal">("recent");
  const [recentHistory, setRecentHistory] = useState<MealItemInput[]>([]);
  const [mealHistory, setMealHistory] = useState<MealItemInput[]>([]);
  const [isHistoryLoading, setIsHistoryLoading] = useState(false);
  const [selectedHistoryItem, setSelectedHistoryItem] =
    useState<MealItemInput | null>(null);
  const [aiWebSearchLoading, setAiWebSearchLoading] = useState(
    createInitialAiWebSearchLoadingUi,
  );
  const activeSearchTokenRef = useRef(0);
  const historyRequestTokenRef = useRef(0);
  const searchStartedAtRef = useRef<number | undefined>(undefined);
  const registrationContextRef = useRef<RegistrationContext>({});
  const aliasCandidateRankRef = useRef<number | null>(null);
  const foodSearchPeakProgressRef = useRef(0);
  const aiWebSearchStartedAtRef = useRef<number | null>(null);
  const aiWebSearchProgressPeakRef = useRef(
    AI_WEB_SEARCH_LOADING_PHASES[0].startProgress,
  );
  const aiWebSearchRafRef = useRef(0);
  const showSearchApiDebug =
    import.meta.env.VITE_FOOD_SEARCH_DEBUG_MODE === "true";

  function resetAiWebSearchLoadingState() {
    aiWebSearchStartedAtRef.current = Date.now();
    aiWebSearchProgressPeakRef.current =
      AI_WEB_SEARCH_LOADING_PHASES[0].startProgress;
    setAiWebSearchLoading(createInitialAiWebSearchLoadingUi());
  }

  function clearAiWebSearchLoadingState() {
    if (aiWebSearchRafRef.current !== 0) {
      cancelAnimationFrame(aiWebSearchRafRef.current);
      aiWebSearchRafRef.current = 0;
    }
    aiWebSearchStartedAtRef.current = null;
    aiWebSearchProgressPeakRef.current =
      AI_WEB_SEARCH_LOADING_PHASES[0].startProgress;
    setAiWebSearchLoading(createInitialAiWebSearchLoadingUi());
  }

  async function finishAiWebSearchLoading(token: number): Promise<boolean> {
    setAiWebSearchLoading((prev) => ({
      ...prev,
      progressPercent: 100,
      isFinishing: true,
    }));
    await new Promise<void>((resolve) => {
      window.setTimeout(resolve, AI_WEB_SEARCH_COMPLETE_ANIMATION_MS);
    });
    return activeSearchTokenRef.current === token;
  }

  useLayoutEffect(() => {
    if (!open) {
      // 次回オープン時の初回描画で前回タブが見えないよう、閉じる時点で戻す。
      setHistoryTab("recent");
      activeSearchTokenRef.current = 0;
      clearAiWebSearchLoadingState();
      foodSearchPeakProgressRef.current = 0;
      return;
    }
    // 初回描画前に loading を立て、サジェスト差し替えや空表示のちらつきを防ぐ
    setIsHistoryLoading(true);
    setInputValue("");
    setManualKcal("");
    setManualProtein("");
    setManualFat("");
    setManualCarbs("");
    setShowMacroDetails(false);
    setIsPreSearchManual(false);
    setProgress(makeInitialProgress());
    setShowManualEdit(false);
    setIsSubmitting(false);
    setCompletedResult(null);
    setSelectedHistoryItem(null);
    setHistoryTab("recent");
    activeSearchTokenRef.current = 0;
    searchStartedAtRef.current = undefined;
    registrationContextRef.current = {};
    aliasCandidateRankRef.current = null;
    clearAiWebSearchLoadingState();
    foodSearchPeakProgressRef.current = 0;
  }, [open]);

  // AI Web検索: 経過時間ベースのソフト進捗（実バックエンド進捗とは非連動）
  useEffect(() => {
    const shouldRunSoftProgress =
      open &&
      progress.state === "web_searching" &&
      !aiWebSearchLoading.isFinishing;

    if (!shouldRunSoftProgress) {
      if (aiWebSearchRafRef.current !== 0) {
        cancelAnimationFrame(aiWebSearchRafRef.current);
        aiWebSearchRafRef.current = 0;
      }
      return;
    }

    if (aiWebSearchStartedAtRef.current == null) {
      aiWebSearchStartedAtRef.current = Date.now();
    }

    let cancelled = false;
    let lastTickAt = 0;

    const updateAiWebSearchLoadingState = (frameTime: number) => {
      if (cancelled) return;

      if (lastTickAt !== 0 && frameTime - lastTickAt < 100) {
        aiWebSearchRafRef.current = requestAnimationFrame(
          updateAiWebSearchLoadingState,
        );
        return;
      }
      lastTickAt = frameTime;

      const aiWebSearchElapsedMs =
        Date.now() - (aiWebSearchStartedAtRef.current ?? Date.now());
      const next = resolveAiWebSearchLoadingState(
        aiWebSearchElapsedMs,
        aiWebSearchProgressPeakRef.current,
      );
      aiWebSearchProgressPeakRef.current = next.progressPercent;

      setAiWebSearchLoading((prev) => {
        if (prev.isFinishing) return prev;
        if (
          prev.phase === next.phase &&
          prev.statusMessage === next.statusMessage &&
          Math.abs(prev.progressPercent - next.progressPercent) < 0.08
        ) {
          return prev;
        }
        return {
          phase: next.phase,
          progressPercent: next.progressPercent,
          statusMessage: next.statusMessage,
          isFinishing: false,
        };
      });

      aiWebSearchRafRef.current = requestAnimationFrame(
        updateAiWebSearchLoadingState,
      );
    };

    aiWebSearchRafRef.current = requestAnimationFrame(
      updateAiWebSearchLoadingState,
    );

    return () => {
      cancelled = true;
      if (aiWebSearchRafRef.current !== 0) {
        cancelAnimationFrame(aiWebSearchRafRef.current);
        aiWebSearchRafRef.current = 0;
      }
    };
  }, [open, progress.state, aiWebSearchLoading.isFinishing]);

  useEffect(() => {
    if (!open) return;
    const token = Date.now();
    historyRequestTokenRef.current = token;
    setIsHistoryLoading(true);
    void (async () => {
      try {
        const [recent, byMeal] = await Promise.all([
          fetchMealHistory({ limit: 40 }),
          fetchMealHistory({ mealType, limit: 40 }),
        ]);
        if (historyRequestTokenRef.current !== token) return;
        setRecentHistory(toUniqueMealInputs(recent.history));
        setMealHistory(toUniqueMealInputs(byMeal.history));
      } catch {
        if (historyRequestTokenRef.current !== token) return;
        setRecentHistory([]);
        setMealHistory([]);
      } finally {
        if (historyRequestTokenRef.current === token) {
          setIsHistoryLoading(false);
        }
      }
    })();
  }, [open, mealType]);

  const canSearch = inputValue.trim().length >= 2;
  const selectedResult = progress.result;
  const isSearching =
    progress.state === "searching" || progress.state === "web_searching";
  const isEditingManually = showManualEdit || progress.state === "error";
  const isFoodNameLocked =
    !isEditingManually &&
    (isSearching ||
      progress.state === "found" ||
      progress.state === "estimated" ||
      progress.state === "from_history" ||
      progress.state === "low_confidence_estimate" ||
      (progress.state === "needs_confirmation" &&
        !isManualEditingFromConfirmation) ||
      progress.state === "needs_alias_confirmation" ||
      progress.state === "needs_local_db_confirmation" ||
      progress.state === "web_searching" ||
      progress.state === "web_found" ||
      progress.state === "completed");

  const completedSummary = useMemo(() => {
    if (!completedResult) return null;
    const nextMealTotal = currentMealKcal + completedResult.calories;
    const nextTotal = currentTotalKcal + completedResult.calories;
    const remaining =
      dailyGoalKcal !== null ? Math.max(dailyGoalKcal - nextTotal, 0) : null;
    return { nextMealTotal, nextTotal, remaining };
  }, [completedResult, currentMealKcal, currentTotalKcal, dailyGoalKcal]);
  const isWebSearchFallback =
    progress.state === "low_confidence_estimate" &&
    progress.steps.some(
      (step) => step.key === "ai_web_searching" && step.status === "done",
    );

  function handleHistorySelect(item: MealItemInput) {
    activeSearchTokenRef.current = 0;
    setInputValue(item.label);
    setManualKcal(item.kcal.replace(/kcal/i, ""));
    setManualProtein(
      item.proteinG != null ? String(item.proteinG) : "",
    );
    setManualFat(item.fatG != null ? String(item.fatG) : "");
    setManualCarbs(item.carbsG != null ? String(item.carbsG) : "");
    setShowMacroDetails(false);
    setShowManualEdit(false);
    setIsPreSearchManual(false);
    setSelectedHistoryItem(item);
    const result = mealItemToSearchResult(item);
    setProgress({
      state: "from_history",
      steps: INITIAL_STEPS.map((step) => ({ ...step, status: "skipped" })),
      result,
    });
  }

  function resolveEditedCalories(result: FoodSearchResult): {
    calories: number;
    caloriesEdited: boolean;
  } {
    const parsedManualKcal = Number(manualKcal);
    const hasValidManualKcal =
      showManualEdit &&
      Number.isFinite(parsedManualKcal) &&
      parsedManualKcal > 0;
    const manualDiffers =
      hasValidManualKcal &&
      Math.round(parsedManualKcal) !== Math.round(result.calories);

    if (manualDiffers) {
      return {
        calories: Math.round(parsedManualKcal),
        caloriesEdited: true,
      };
    }

    return {
      calories: result.calories,
      caloriesEdited: parseCaloriesEdited(result.caloriesEdited),
    };
  }

  function openManualEntry(options?: {
    fromIdle?: boolean;
    fromConfirmation?: boolean;
    initialKcal?: string;
  }) {
    setShowManualEdit(true);
    setIsPreSearchManual(options?.fromIdle === true);
    setIsManualEditingFromConfirmation(options?.fromConfirmation === true);
    setShowMacroDetails(false);
    if (options?.initialKcal != null) {
      setManualKcal(options.initialKcal);
    } else if (!manualKcal && selectedResult) {
      setManualKcal(String(selectedResult.calories));
    }
    if (selectedResult) {
      setManualProtein(
        selectedResult.protein != null ? String(selectedResult.protein) : "",
      );
      setManualFat(selectedResult.fat != null ? String(selectedResult.fat) : "");
      setManualCarbs(
        selectedResult.carbs != null ? String(selectedResult.carbs) : "",
      );
    }
  }

  function parseOptionalMacro(value: string): number | null {
    const trimmed = value.trim();
    if (trimmed === "") return null;
    const parsed = Number(trimmed);
    if (!Number.isFinite(parsed) || parsed < 0) return null;
    return Number(parsed.toFixed(1));
  }

  async function handleSearch() {
    if (!canSearch || isSearching) return;
    const token = Date.now();
    activeSearchTokenRef.current = token;
    searchStartedAtRef.current = Date.now();
    registrationContextRef.current = {};
    aliasCandidateRankRef.current = null;
    foodSearchPeakProgressRef.current = 0;
    setShowManualEdit(false);
    setIsPreSearchManual(false);
    setManualKcal("");
    setManualProtein("");
    setManualFat("");
    setManualCarbs("");
    setShowMacroDetails(false);
    setIsManualEditingFromConfirmation(false);
    try {
      // 変更: Regex → FatSecret → Open Food Facts → Claude 推定の順で検索。
      const next = await searchFoodByText(inputValue, (nextProgress) => {
        if (activeSearchTokenRef.current !== token) return;
        setProgress(nextProgress);
      });
      if (activeSearchTokenRef.current !== token) return;
      setProgress(next);
    } catch (error) {
      if (activeSearchTokenRef.current !== token) return;
      setProgress({
        ...makeInitialProgress(),
        state: "error",
        message: error instanceof Error ? error.message : "検索に失敗しました",
      });
      openManualEntry({ fromIdle: false });
    }
  }

  async function runWebSearchFlow(options?: { deepWeb?: boolean }) {
    if (isSearching) return;
    const token = Date.now();
    activeSearchTokenRef.current = token;
    resetAiWebSearchLoadingState();
    setIsManualEditingFromConfirmation(false);
    setShowManualEdit(false);
    setIsPreSearchManual(false);
    try {
      const next = await runAiWebSearch(
        inputValue,
        (nextProgress) => {
          if (activeSearchTokenRef.current !== token) return;
          // 完了 state は 100% アニメ後に適用（コールバックでは待機中のみ）
          if (nextProgress.state === "web_searching") {
            setProgress(nextProgress);
          }
        },
        options,
      );
      if (activeSearchTokenRef.current !== token) return;
      const stillActive = await finishAiWebSearchLoading(token);
      if (!stillActive) return;
      setProgress(next);
      clearAiWebSearchLoadingState();
      registrationContextRef.current.webSearchCountDelta = 1;
    } catch (error) {
      if (activeSearchTokenRef.current !== token) return;
      clearAiWebSearchLoadingState();
      setProgress({
        ...progress,
        state: "error",
        message:
          error instanceof Error
            ? error.message
            : options?.deepWeb
              ? "詳しく調べ直すのに失敗しました"
              : "調べ直すのに失敗しました",
      });
      openManualEntry({ fromIdle: false });
    }
  }

  async function handleWebSearch() {
    // 変更: 低信頼度時のみ、ユーザー操作で AI Web検索を実行する。
    await runWebSearchFlow();
  }

  async function handleDeepWebSearch() {
    await runWebSearchFlow({ deepWeb: true });
  }

  function handleConfirmSingleWebCandidate(candidate: FoodConfirmationCandidate) {
    if (!candidate.webCandidate) return;

    registrationContextRef.current.candidateCount = 1;
    aliasCandidateRankRef.current = 1;

    const result = buildResultFromSelectedCandidate(
      inputValue.trim(),
      candidate.webCandidate,
    );
    void saveItem(result);
  }

  function handleEditSingleWebCandidate(candidate: FoodConfirmationCandidate) {
    openManualEntry({
      fromConfirmation: true,
      initialKcal:
        Number.isFinite(candidate.kcal) && candidate.kcal > 0
          ? String(candidate.kcal)
          : "",
    });
    setInputValue(candidate.label.trim() || inputValue.trim());
  }

  function handleConfirmSelectedWebCandidate() {
    const selectedKey = progress.selectedCandidateKey;
    if (!selectedKey) return;

    const candidate = toWebConfirmationCandidates(progress.candidates ?? []).find(
      (item) => item.key === selectedKey,
    );
    if (!candidate?.webCandidate) return;

    const webCandidates = progress.candidates ?? [];
    const rank =
      webCandidates.findIndex(
        (item) =>
          item.product_name === candidate.webCandidate?.product_name &&
          item.kcal === candidate.webCandidate?.kcal,
      ) + 1;
    aliasCandidateRankRef.current = rank > 0 ? rank : null;

    const result = buildResultFromSelectedCandidate(
      inputValue.trim(),
      candidate.webCandidate,
    );
    setProgress({
      ...progress,
      state: "web_found",
      result,
      candidates: undefined,
      selectedCandidateKey: null,
      message: "商品情報が見つかりました",
    });
  }

  async function handleCandidateUnknown() {
    try {
      const fallback = await estimateCalories(inputValue.trim(), "no_web");
      const estimateResult: FoodSearchResult = {
        id: `claude-${Date.now()}`,
        name: inputValue.trim(),
        displayName: fallback.product_name?.trim() || inputValue.trim(),
        amount: 1,
        unit: "食",
        calories: fallback.kcal ?? 0,
        protein: null,
        fat: null,
        carbs: null,
        source: "claude_estimate",
        confidence: fallback.confidence ?? "low",
        shouldOfferWebSearch: fallback.should_offer_web_search === true,
        webSearchReason: fallback.web_search_reason?.trim() || null,
        isEstimated: true,
        rawInput: inputValue.trim(),
      };

      setProgress({
        state: "low_confidence_estimate",
        steps: progress.steps,
        result: estimateResult,
        message: "正確なカロリーを特定できませんでした",
      });
    } catch {
      openManualEntry({ fromConfirmation: true });
    }
  }

  function handleAiOnly() {
    if (!selectedResult) return;
    void saveItem(selectedResult);
  }

  function handleCandidateSelect(candidate: FoodConfirmationCandidate) {
    const aliasCandidates = progress.aliasCandidates ?? [];
    const webCandidates = progress.candidates ?? [];
    const localDbCandidates = progress.localDbCandidates ?? [];
    registrationContextRef.current.candidateCount =
      aliasCandidates.length > 0
        ? aliasCandidates.length
        : localDbCandidates.length > 0
          ? localDbCandidates.length
          : webCandidates.length;

    if (candidate.webCandidate) {
      const isVariantAmbiguous =
        progress.confirmationReason === "variant_ambiguous";

      if (isVariantAmbiguous) {
        setProgress({
          ...progress,
          selectedCandidateKey: candidate.key,
        });
        return;
      }

      const rank =
        webCandidates.findIndex(
          (item) =>
            item.product_name === candidate.webCandidate?.product_name &&
            item.kcal === candidate.webCandidate?.kcal,
        ) + 1;
      aliasCandidateRankRef.current = rank > 0 ? rank : null;
      const result = buildResultFromSelectedCandidate(
        inputValue.trim(),
        candidate.webCandidate,
      );
      setProgress({
        ...progress,
        state: "web_found",
        result,
        candidates: undefined,
        selectedCandidateKey: null,
        message: "商品情報が見つかりました",
      });
      return;
    }

    if (candidate.localDbCandidate) {
      const rank =
        localDbCandidates.findIndex(
          (item) => item.foodId === candidate.localDbCandidate?.foodId,
        ) + 1;
      aliasCandidateRankRef.current = rank > 0 ? rank : null;
      const result = buildResultFromSelectedLocalDb(
        inputValue.trim(),
        candidate.localDbCandidate,
      );
      setProgress({
        ...progress,
        state: "found",
        result,
        localDbCandidates: undefined,
        message: "登録済みの食品が見つかりました",
      });
      return;
    }

    const aliasCandidate = aliasCandidates.find(
      (item) => String(item.aliasId) === candidate.key,
    );
    if (!aliasCandidate) return;

    const rank =
      aliasCandidates.findIndex((item) => item.aliasId === aliasCandidate.aliasId) +
      1;
    aliasCandidateRankRef.current = rank > 0 ? rank : null;

    const result = buildResultFromSelectedAlias(inputValue.trim(), aliasCandidate);
    setProgress({
      ...progress,
      state: "found",
      result,
      aliasCandidates: undefined,
      message: "候補が見つかりました",
    });
  }

  function buildRegistrationMetrics(
    caloriesBeforeEdit: number | null,
    selectedSource: string,
  ): RegistrationMetrics {
    return buildRegistrationMetricsFromSteps({
      rawInput: inputValue.trim(),
      selectedSource,
      searchStartedAt: searchStartedAtRef.current,
      steps: progress.steps,
      candidateCount: registrationContextRef.current.candidateCount,
      selectedCandidateRank: aliasCandidateRankRef.current,
      caloriesBeforeEdit,
      webSearchCountDelta: registrationContextRef.current.webSearchCountDelta ?? 0,
    });
  }

  function buildMealItemFromResult(
    result: FoodSearchResult,
    calories: number,
    caloriesEdited: boolean,
    registrationMetrics: RegistrationMetrics,
  ): MealItemInput {
    const servingLabel =
      result.amount != null && result.unit
        ? `${result.amount}${result.unit}`
        : null;
    const servingWeightG =
      result.unit?.toLowerCase() === "g" && result.amount != null
        ? result.amount
        : null;
    const proteinG = parseOptionalMacro(manualProtein) ?? result.protein ?? null;
    const fatG = parseOptionalMacro(manualFat) ?? result.fat ?? null;
    const carbsG = parseOptionalMacro(manualCarbs) ?? result.carbs ?? null;

    return {
      label: inputValue.trim() || result.displayName,
      kcal: `${calories}kcal`,
      caloriesEdited,
      calorieSource: toPersistedCalorieSource(result.source),
      sourceUrl: result.sourceUrl ?? null,
      confidence: result.confidence,
      foodId: result.selectedFoodId ?? null,
      rawInput: result.rawInput || inputValue.trim(),
      amount: result.amount ?? null,
      unit: result.unit ?? null,
      servingLabel,
      servingWeightG,
      proteinG,
      fatG,
      carbsG,
      fiberG: result.fiber ?? null,
      sodiumMg: result.sodium ?? null,
      registrationMetrics,
    };
  }

  async function persistFoodAlias(
    result: FoodSearchResult,
    foodId: number,
    caloriesEdited: boolean,
  ) {
    if (
      !shouldSaveFoodAlias({
        rawQuery: result.rawInput,
        foodName: result.displayName,
        source: result.source,
        caloriesEdited,
      })
    ) {
      return;
    }

    try {
      if (result.aliasId) {
        await selectFoodAlias(result.aliasId);
        return;
      }

      await upsertFoodAlias({
        rawQuery: result.rawInput,
        foodId,
        source: resolveAliasSourceForSave(result.source),
      });
    } catch (aliasError) {
      console.warn("Failed to save food alias", aliasError);
    }
  }

  function handleCandidateManualInput() {
    openManualEntry({ fromConfirmation: true });
    setProgress({
      ...progress,
      message: "候補に該当がない場合は、編集して記録してください。",
    });
  }

  async function saveItem(result: FoodSearchResult | null) {
    const label = inputValue.trim();
    if (label === "") return;

    setIsSubmitting(true);
    try {
      if (result) {
        const { calories, caloriesEdited } = resolveEditedCalories(result);
        const nameEdited =
          inputValue.trim() !== "" &&
          inputValue.trim() !== result.displayName.trim();
        const selectedSource = result.source;
        const registrationMetrics = buildRegistrationMetrics(
          result.calories,
          selectedSource,
        );
        const item = buildMealItemFromResult(
          result,
          calories,
          caloriesEdited || nameEdited,
          registrationMetrics,
        );
        await onSave(item);
        let savedFoodId = result.selectedFoodId ?? null;
        if (isWebSearchSource(result.source) && !caloriesEdited) {
          try {
            const savedFood = await saveUserFood({
              displayName: item.label,
              name: result.selectedProductName ?? result.name,
              amount: result.amount,
              unit: result.unit,
              calories,
              source: "ai_web_search",
              rawInput: result.rawInput,
              sourceUrl: result.sourceUrl ?? null,
            });
            savedFoodId = savedFood.food.id;
          } catch (saveFoodError) {
            console.warn("Failed to save user food", saveFoodError);
          }
        }

        if (savedFoodId != null) {
          await persistFoodAlias(result, savedFoodId, caloriesEdited);
        } else if (
          result.source === "user_registered" &&
          !caloriesEdited &&
          shouldSaveFoodAlias({
            rawQuery: result.rawInput,
            foodName: label,
            source: result.source,
            caloriesEdited,
          })
        ) {
          try {
            const savedFood = await saveUserFood({
              displayName: label,
              name: label,
              amount: 1,
              unit: "食",
              calories,
              source: "user_registered",
              rawInput: result.rawInput,
            });
            await persistFoodAlias(
              { ...result, selectedFoodId: savedFood.food.id },
              savedFood.food.id,
              caloriesEdited,
            );
          } catch (saveFoodError) {
            console.warn("Failed to save user food for alias", saveFoodError);
          }
        }
        setCompletedResult({ ...result, calories, caloriesEdited });
        setProgress({ ...progress, state: "completed" });
        return;
      }

      const parsedKcal = Number(manualKcal);
      if (!Number.isFinite(parsedKcal) || parsedKcal <= 0) return;
      const registrationMetrics = buildRegistrationMetrics(
        null,
        "user_registered",
      );
      const item: MealItemInput = {
        label,
        kcal: `${Math.round(parsedKcal)}kcal`,
        caloriesEdited: true,
        calorieSource: "user_registered",
        confidence: "low",
        rawInput: label,
        amount: 1,
        unit: "食",
        servingLabel: "1食",
        proteinG: parseOptionalMacro(manualProtein),
        fatG: parseOptionalMacro(manualFat),
        carbsG: parseOptionalMacro(manualCarbs),
        registrationMetrics,
      };
      await onSave(item);
      setCompletedResult({
        id: `user-${Date.now()}`,
        name: label,
        displayName: label,
        amount: 1,
        unit: "食",
        calories: Math.round(parsedKcal),
        protein: parseOptionalMacro(manualProtein),
        fat: parseOptionalMacro(manualFat),
        carbs: parseOptionalMacro(manualCarbs),
        source: "user_registered",
        confidence: "low",
        isEstimated: true,
        barcode: null,
        brandName: null,
        rawInput: label,
      });
      setProgress({ ...progress, state: "completed" });
    } catch (saveError) {
      setProgress({
        ...makeInitialProgress(),
        state: "error",
        message:
          saveError instanceof Error ? saveError.message : "保存に失敗しました",
      });
      openManualEntry({ fromIdle: isPreSearchManual });
    } finally {
      setIsSubmitting(false);
    }
  }

  function renderIdleSection() {
    if (isPreSearchManual && showManualEdit) {
      return null;
    }

    const activeHistory = historyTab === "recent" ? recentHistory : mealHistory;
    return (
      <ModalStateLayout
        contentMode="fill"
        content={
          <div style={idleHistoryFillStyle}>
            <div style={idleHistoryHeaderStyle}>
              <div style={hintTitleStyle}>履歴から選ぶ</div>
              <div style={tabWrapStyle}>
                <button
                  type="button"
                  onClick={() => setHistoryTab("recent")}
                  aria-pressed={historyTab === "recent"}
                  style={{
                    ...tabButtonStyle,
                    ...(historyTab === "recent"
                      ? activeTabButtonStyle
                      : inactiveTabButtonStyle),
                  }}
                >
                  最近の履歴
                </button>
                <button
                  type="button"
                  onClick={() => setHistoryTab("meal")}
                  aria-pressed={historyTab === "meal"}
                  style={{
                    ...tabButtonStyle,
                    ...(historyTab === "meal"
                      ? activeTabButtonStyle
                      : inactiveTabButtonStyle),
                  }}
                >
                  {mealTitle}の履歴
                </button>
              </div>
            </div>
            <div style={historyScrollAreaStyle}>
              {isHistoryLoading ? (
                <div
                  style={historyLoadingStyle}
                  aria-busy="true"
                  aria-live="polite"
                >
                  <Loader2 size={20} color="#9CA3AF" className="diet-ai-spin" />
                  <span>読み込み中...</span>
                </div>
              ) : activeHistory.length === 0 ? (
                <div style={emptyHistoryStyle}>履歴がありません</div>
              ) : (
                <div style={chipWrapStyle}>
                  {activeHistory.map((item) => (
                    <button
                      key={`${item.label}-${item.kcal}`}
                      type="button"
                      onClick={() => handleHistorySelect(item)}
                      style={chipStyle}
                    >
                      <span style={chipLabelStyle}>{item.label}</span>
                      <span style={chipKcalStyle}>{item.kcal}</span>
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>
        }
        actions={
          <>
            <button
              type="button"
              onClick={() => void handleSearch()}
              disabled={!canSearch || isSubmitting}
              style={{
                ...modalPrimaryActionStyle,
                opacity: canSearch && !isSubmitting ? 1 : 0.45,
              }}
            >
              検索する
            </button>
            <button
              type="button"
              onClick={() => openManualEntry({ fromIdle: true })}
              style={modalLinkActionStyle}
            >
              手動で入力
            </button>
          </>
        }
      />
    );
  }

  function renderResultActions(options: {
    onAdd: () => void;
    onEdit: () => void;
    onSearchWeb?: () => void;
  }) {
    const hasWebSearch = Boolean(options.onSearchWeb);

    return (
      <div
        className={
          hasWebSearch
            ? "modal-result-actions"
            : "modal-result-actions modal-result-actions--add-edit"
        }
      >
        <button
          type="button"
          onClick={options.onAdd}
          className="modal-primary-action"
          style={modalPrimaryActionStyle}
        >
          追加する
        </button>
        {options.onSearchWeb && (
          <button
            type="button"
            onClick={options.onSearchWeb}
            className="modal-accurate-search-action"
          >
            より正確に調べる
          </button>
        )}
        <button
          type="button"
          onClick={options.onEdit}
          className="modal-edit-action"
        >
          編集
        </button>
      </div>
    );
  }

  function renderSearchResult(state: SearchState) {
    if (state === "searching" || state === "web_searching") {
      const isWebSearching = state === "web_searching";
      const foodLoadingState = resolveFoodSearchLoadingState(
        progress.steps,
        foodSearchPeakProgressRef.current,
      );
      if (!isWebSearching) {
        foodSearchPeakProgressRef.current = foodLoadingState.progressPercent;
      }

      const progressPercent = isWebSearching
        ? aiWebSearchLoading.progressPercent
        : foodLoadingState.progressPercent;
      const statusMessage = isWebSearching
        ? aiWebSearchLoading.statusMessage
        : foodLoadingState.statusMessage;
      const hintMessage = isWebSearching
        ? AI_WEB_SEARCH_LOADING_HINT
        : FOOD_SEARCH_LOADING_HINT;

      return (
        <ModalStateLayout
          contentMode="searching"
          content={
            <FoodSearchStatus
              title={
                isWebSearching
                  ? "より正確な情報を確認しています"
                  : "食品情報を探しています"
              }
              query={inputValue.trim()}
              mode={isWebSearching ? "web" : "food"}
              progressPercent={progressPercent}
              statusMessage={statusMessage}
              hintMessage={hintMessage}
              steps={progress.steps}
              isFinishing={isWebSearching && aiWebSearchLoading.isFinishing}
              showApiDebug={showSearchApiDebug}
            />
          }
          actions={
            <button
              type="button"
              onClick={() => {
                activeSearchTokenRef.current = 0;
                clearAiWebSearchLoadingState();
                foodSearchPeakProgressRef.current = 0;
                setProgress(makeInitialProgress());
              }}
              className="modal-search-cancel-button"
            >
              キャンセル
            </button>
          }
        />
      );
    }

    if ((state === "found" || state === "web_found") && selectedResult) {
      return (
        <ModalStateLayout
          contentMode="top"
          content={
            <FoodResultPreview result={selectedResult} mode="register" />
          }
          actions={renderResultActions({
            onAdd: () => void saveItem(selectedResult),
            onEdit: () =>
              openManualEntry({
                initialKcal: String(selectedResult.calories),
              }),
          })}
        />
      );
    }

    if (state === "estimated" && selectedResult) {
      const canSearchWeb = selectedResult.shouldOfferWebSearch === true;
      return (
        <ModalStateLayout
          contentMode="top"
          content={
            <FoodResultPreview result={selectedResult} mode="register" />
          }
          actions={renderResultActions({
            onAdd: () => void saveItem(selectedResult),
            onEdit: () =>
              openManualEntry({
                initialKcal: String(selectedResult.calories),
              }),
            onSearchWeb: canSearchWeb
              ? () => void handleWebSearch()
              : undefined,
          })}
        />
      );
    }

    if (state === "from_history" && selectedResult) {
      const historyEdited = parseCaloriesEdited(
        selectedHistoryItem?.caloriesEdited ?? selectedResult.caloriesEdited,
      );
      return (
        <ModalStateLayout
          contentMode="top"
          content={
            <FoodResultPreview
              result={selectedResult}
              mode="history"
              caloriesEdited={historyEdited}
            />
          }
          actions={renderResultActions({
            onAdd: () => void saveItem(selectedResult),
            onEdit: () =>
              openManualEntry({
                initialKcal: String(selectedResult.calories),
              }),
          })}
        />
      );
    }

    if (state === "low_confidence_estimate" && selectedResult) {
      const canSearchWeb =
        !isWebSearchFallback && selectedResult.shouldOfferWebSearch === true;
      const showDeepSearchButton =
        isWebSearchFallback && progress.offerDeepWebSearch === true;

      return (
        <ModalStateLayout
          contentMode="scroll"
          stickyActions={false}
          content={
            <LowConfidenceEstimateCard
              result={selectedResult}
              showDeepSearchButton={showDeepSearchButton}
              warningMessage={
                isWebSearchFallback
                  ? (progress.message ??
                    "正確な商品情報を確認できませんでした")
                  : undefined
              }
            />
          }
          actions={
            <>
              <button
                type="button"
                onClick={handleAiOnly}
                style={modalPrimaryActionStyle}
              >
                この内容で追加
              </button>
              {showDeepSearchButton && (
                <button
                  type="button"
                  onClick={() => void handleDeepWebSearch()}
                  style={modalSecondaryActionStyle}
                >
                  さらに詳しく検索
                </button>
              )}
              {canSearchWeb && (
                <button
                  type="button"
                  onClick={() => void handleWebSearch()}
                  style={modalSecondaryActionStyle}
                >
                  より正確に調べる
                </button>
              )}
              <button
                type="button"
                onClick={() =>
                  openManualEntry({
                    initialKcal: String(selectedResult.calories),
                  })
                }
                style={modalTextActionStyle}
              >
                編集
              </button>
            </>
          }
        />
      );
    }

    if (state === "needs_confirmation" && (progress.candidates?.length ?? 0) > 0) {
      if (showManualEdit && isManualEditingFromConfirmation) {
        return null;
      }

      const confirmationCandidates = toWebConfirmationCandidates(
        progress.candidates ?? [],
      );

      if (confirmationCandidates.length === 0) {
        return null;
      }

      return (
        <WebSearchCandidateConfirmation
          variantDimension={progress.variantDimension ?? "unknown"}
          candidates={confirmationCandidates}
          confirmationReason={progress.confirmationReason}
          allowEstimatedAdd={progress.allowEstimatedAdd}
          selectedKey={progress.selectedCandidateKey ?? null}
          onSelect={handleCandidateSelect}
          onConfirmSingle={handleConfirmSingleWebCandidate}
          onEditSingle={handleEditSingleWebCandidate}
          onConfirmSelected={handleConfirmSelectedWebCandidate}
          onManualInput={handleCandidateManualInput}
          onUnknown={
            progress.allowEstimatedAdd === false
              ? undefined
              : handleCandidateUnknown
          }
          isSubmitting={isSubmitting}
        />
      );
    }

    if (
      state === "needs_alias_confirmation" &&
      (progress.aliasCandidates?.length ?? 0) > 0
    ) {
      return (
        <ModalStateLayout
          contentMode="fill"
          content={
            <ProductConfirmationCard
              title="こちらの商品ですか？"
              description="過去によく選ばれている候補です。該当する商品を選んでください。"
              candidates={toAliasConfirmationCandidates(
                progress.aliasCandidates ?? [],
              )}
              onSelect={handleCandidateSelect}
              fillAvailableSpace
            />
          }
          actions={
            <>
              <button
                type="button"
                onClick={handleCandidateManualInput}
                style={modalTextActionStyle}
              >
                編集
              </button>
              <button
                type="button"
                onClick={() => void handleWebSearch()}
                disabled={isSearching}
                style={{
                  ...modalOutlineActionStyle,
                  opacity: isSearching ? 0.45 : 1,
                  cursor: isSearching ? "not-allowed" : "pointer",
                }}
              >
                より正確に調べる
              </button>
            </>
          }
        />
      );
    }

    if (
      state === "needs_local_db_confirmation" &&
      (progress.localDbCandidates?.length ?? 0) > 0
    ) {
      const isVariantAmbiguous =
        progress.confirmationReason === "variant_ambiguous";
      const baseName =
        progress.localDbCandidates?.[0]?.baseProductName ?? inputValue.trim();

      return (
        <ModalStateLayout
          contentMode="fill"
          content={
            <ProductConfirmationCard
              title={
                isVariantAmbiguous
                  ? "サイズを選んでください"
                  : "こちらの商品ですか？"
              }
              description={
                isVariantAmbiguous
                  ? `${baseName} のサイズ・容量が複数見つかりました。該当するものを選んでください。`
                  : "登録済みの候補です。該当する商品を選んでください。"
              }
              candidates={toLocalDbConfirmationCandidates(
                progress.localDbCandidates ?? [],
              )}
              onSelect={handleCandidateSelect}
              fillAvailableSpace
            />
          }
          actions={
            <>
              <button
                type="button"
                onClick={handleCandidateManualInput}
                style={modalTextActionStyle}
              >
                編集
              </button>
              <button
                type="button"
                onClick={() => void handleWebSearch()}
                disabled={isSearching}
                style={{
                  ...modalOutlineActionStyle,
                  opacity: isSearching ? 0.45 : 1,
                  cursor: isSearching ? "not-allowed" : "pointer",
                }}
              >
                より正確に調べる
              </button>
            </>
          }
        />
      );
    }

    if (state === "completed" && completedResult && completedSummary) {
      return (
        <ModalStateLayout
          contentMode="center"
          content={
            <div style={completedCardStyle}>
              <div style={completedTitleStyle}>追加しました</div>
              <div style={completedFoodStyle}>
                {completedResult.displayName} / {completedResult.calories}kcal
              </div>
              <div style={completedMetaStyle}>
                {mealTitle} 合計: {completedSummary.nextMealTotal}kcal
              </div>
              <div style={completedMetaStyle}>
                今日の合計: {completedSummary.nextTotal}kcal
              </div>
              {completedSummary.remaining !== null && (
                <div style={completedMetaStyle}>
                  今日の残り: {completedSummary.remaining}kcal
                </div>
              )}
            </div>
          }
          actions={
            <button type="button" onClick={onClose} style={modalPrimaryActionStyle}>
              閉じる
            </button>
          }
        />
      );
    }

    return null;
  }

  function renderManualEntryForm() {
    if (!isEditingManually) return null;

    const canSubmit =
      inputValue.trim() !== "" &&
      manualKcal.trim() !== "" &&
      !isSubmitting;

    return (
      <ModalStateLayout
        contentMode="scroll"
        content={
          <div style={manualFormStyle}>
            {isPreSearchManual && (
              <div style={manualFormTitleStyle}>手動で入力</div>
            )}
            {!isPreSearchManual && progress.state !== "error" && (
              <div style={manualFormTitleStyle}>編集</div>
            )}
            {progress.state === "error" && (
              <div style={errorTextStyle}>
                {progress.message ??
                  "検索に失敗しました。手動で記録してください。"}
              </div>
            )}
            <label
              style={{
                ...fieldLabelStyle,
                marginTop: isPreSearchManual ? 0 : 4,
              }}
            >
              カロリー
            </label>
            <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
              <input
                type="number"
                placeholder="例：234"
                value={manualKcal}
                onChange={(e) => setManualKcal(e.target.value)}
                style={{ ...inputStyle, marginBottom: 0 }}
              />
              <span style={{ fontSize: 13, color: "#888" }}>kcal</span>
            </div>

            <button
              type="button"
              onClick={() => setShowMacroDetails((prev) => !prev)}
              style={macroToggleStyle}
            >
              {showMacroDetails
                ? "詳細な栄養情報を閉じる"
                : "詳細な栄養情報を入力（任意）"}
            </button>

            {showMacroDetails && (
              <div style={macroFieldsStyle}>
                <label style={fieldLabelStyle}>たんぱく質 (g)</label>
                <input
                  type="number"
                  placeholder="任意"
                  value={manualProtein}
                  onChange={(e) => setManualProtein(e.target.value)}
                  style={inputStyle}
                />
                <label style={fieldLabelStyle}>脂質 (g)</label>
                <input
                  type="number"
                  placeholder="任意"
                  value={manualFat}
                  onChange={(e) => setManualFat(e.target.value)}
                  style={inputStyle}
                />
                <label style={fieldLabelStyle}>炭水化物 (g)</label>
                <input
                  type="number"
                  placeholder="任意"
                  value={manualCarbs}
                  onChange={(e) => setManualCarbs(e.target.value)}
                  style={inputStyle}
                />
              </div>
            )}
          </div>
        }
        actions={
          <>
            <button
              type="button"
              disabled={!canSubmit}
              onClick={() =>
                void saveItem(
                  isPreSearchManual || progress.state === "error"
                    ? null
                    : selectedResult,
                )
              }
              style={{
                ...modalPrimaryActionStyle,
                opacity: canSubmit ? 1 : 0.45,
              }}
            >
              追加する
            </button>
            {isPreSearchManual && (
              <button
                type="button"
                onClick={() => {
                  setShowManualEdit(false);
                  setIsPreSearchManual(false);
                  setManualKcal("");
                  setManualProtein("");
                  setManualFat("");
                  setManualCarbs("");
                  setShowMacroDetails(false);
                }}
                style={modalLinkActionStyle}
              >
                検索に戻る
              </button>
            )}
          </>
        }
      />
    );
  }

  return (
    <BottomSheet
      open={open}
      title={`${mealTitle}を記録`}
      onClose={onClose}
      fillMaxHeight
    >
      <div style={foodNameSectionStyle}>
        <label style={fieldLabelStyle}>食品名</label>
        <input
          type="text"
          placeholder="例：白米150g / 焼き鮭定食"
          value={inputValue}
          onChange={(e) => setInputValue(e.target.value)}
          readOnly={isFoodNameLocked}
          aria-readonly={isFoodNameLocked}
          style={{
            ...inputStyle,
            background: isFoodNameLocked ? "#F3F4F6" : "#fff",
            color: isFoodNameLocked ? "#6B7280" : "#111",
            cursor: isFoodNameLocked ? "not-allowed" : "text",
          }}
        />
      </div>

      <ModalStateViewport>
        {isEditingManually
          ? renderManualEntryForm()
          : progress.state === "idle"
            ? renderIdleSection()
            : renderSearchResult(progress.state)}
      </ModalStateViewport>
    </BottomSheet>
  );
}

function toLocalDbConfirmationCandidates(
  candidates: LocalDbSearchCandidate[],
): FoodConfirmationCandidate[] {
  return candidates.map((candidate) => {
    const variantLabel = candidate.variantLabel.trim();
    const showVariantBadge =
      variantLabel !== "" && variantLabel !== "通常サイズ";

    return {
      key: String(candidate.foodId),
      label: candidate.name,
      kcal: candidate.calories,
      badge: showVariantBadge ? variantLabel : null,
      localDbCandidate: candidate,
    };
  });
}

function toAliasConfirmationCandidates(
  candidates: AliasSearchCandidate[],
): FoodConfirmationCandidate[] {
  const topSelectionCount = candidates[0]?.selectionCount ?? 0;

  return candidates.map((candidate) => ({
    key: String(candidate.aliasId),
    label: candidate.food.displayName,
    kcal: candidate.food.calories,
    badge:
      candidate.food.variantLabel ??
      (candidate.selectionCount === topSelectionCount && topSelectionCount >= 3
        ? "よく選ばれています"
        : null),
    aliasId: candidate.aliasId,
  }));
}

function toUniqueMealInputs(history: MealHistoryEntry[]): MealItemInput[] {
  const seen = new Set<string>();
  const unique: MealItemInput[] = [];

  for (const entry of history) {
    const normalizedLabel = entry.label.trim().toLowerCase();
    const key = `${normalizedLabel}|${entry.calories}`;
    if (normalizedLabel === "" || seen.has(key)) continue;
    seen.add(key);
    unique.push({
      label: entry.label,
      kcal: `${entry.calories}kcal`,
      caloriesEdited: parseCaloriesEdited(entry.caloriesEdited),
      calorieSource:
        (entry.calorieSource as FoodSource | null | undefined) ?? null,
      sourceUrl: entry.sourceUrl ?? null,
      confidence: entry.confidence ?? null,
      foodId: entry.foodId ?? null,
      rawInput: entry.rawInput ?? entry.label,
      amount: entry.amount ?? null,
      unit: entry.unit ?? null,
      servingLabel: entry.servingLabel ?? null,
      servingWeightG: entry.servingWeightG ?? null,
      proteinG: entry.proteinG ?? null,
      fatG: entry.fatG ?? null,
      carbsG: entry.carbsG ?? null,
      fiberG: entry.fiberG ?? null,
      sodiumMg: entry.sodiumMg ?? null,
    });
    if (unique.length >= 12) break;
  }

  return unique;
}

const fieldLabelStyle: CSSProperties = {
  display: "block",
  fontSize: 13,
  fontWeight: 600,
  color: "#666",
  marginBottom: 6,
};

const foodNameSectionStyle: CSSProperties = {
  flexShrink: 0,
};

const inputStyle: CSSProperties = {
  width: "100%",
  boxSizing: "border-box",
  padding: "10px 14px",
  borderRadius: 10,
  border: "1px solid #E8E8E8",
  fontSize: 15,
  color: "#111",
  outline: "none",
};

const hintTitleStyle: CSSProperties = {
  marginBottom: 8,
  fontSize: 13,
  fontWeight: 700,
  color: "#6B7280",
};

const chipWrapStyle: CSSProperties = {
  display: "flex",
  flexWrap: "wrap",
  gap: 8,
  alignContent: "flex-start",
};

const HISTORY_AREA_MIN_HEIGHT = 176;

const idleHistoryFillStyle: CSSProperties = {
  height: "100%",
  minHeight: 0,
  display: "flex",
  flexDirection: "column",
  gap: 12,
};

const idleHistoryHeaderStyle: CSSProperties = {
  flexShrink: 0,
};

const historyScrollAreaStyle: CSSProperties = {
  flex: "1 1 auto",
  minHeight: HISTORY_AREA_MIN_HEIGHT,
  boxSizing: "border-box",
  overflowX: "hidden",
  overflowY: "auto",
  overscrollBehavior: "contain",
  WebkitOverflowScrolling: "touch",
  border: "1px solid #F1F5F9",
  borderRadius: 10,
  padding: "8px 8px 16px",
  background: "#fff",
};

const historyLoadingStyle: CSSProperties = {
  height: "100%",
  minHeight: HISTORY_AREA_MIN_HEIGHT - 16,
  display: "flex",
  alignItems: "center",
  justifyContent: "center",
  gap: 8,
  fontSize: 13,
  color: "#9CA3AF",
};

const tabWrapStyle: CSSProperties = {
  display: "grid",
  gridTemplateColumns: "1fr 1fr",
  gap: 6,
  padding: 3,
  borderRadius: 12,
  background: "#F3F4F6",
};

const tabButtonStyle: CSSProperties = {
  border: "1px solid transparent",
  borderRadius: 8,
  fontSize: 13,
  fontWeight: 700,
  padding: "8px 10px",
  cursor: "pointer",
  transition: "all 160ms ease",
};

const activeTabButtonStyle: CSSProperties = {
  borderColor: "#F97316",
  color: "#fff",
  background: "#F97316",
  boxShadow: "0 1px 2px rgba(0,0,0,0.12)",
};

const inactiveTabButtonStyle: CSSProperties = {
  borderColor: "#E5E7EB",
  color: "#6B7280",
  background: "#fff",
};

const chipStyle: CSSProperties = {
  display: "inline-flex",
  alignItems: "center",
  gap: 8,
  padding: "4px 12px",
  borderRadius: 999,
  border: "1px solid #F5E1D2",
  background: "#FFF5EB",
  fontSize: 13,
  color: "#8B5E3C",
  cursor: "pointer",
  maxWidth: "100%",
};

const chipLabelStyle: CSSProperties = {
  fontWeight: 500,
  lineHeight: 1.3,
  overflow: "hidden",
  textOverflow: "ellipsis",
  whiteSpace: "nowrap",
};

const chipKcalStyle: CSSProperties = {
  flexShrink: 0,
  fontSize: 12,
  fontWeight: 500,
  color: "#C2410C",
  lineHeight: 1.2,
};

const emptyHistoryStyle: CSSProperties = {
  height: "100%",
  minHeight: HISTORY_AREA_MIN_HEIGHT - 16,
  display: "flex",
  alignItems: "center",
  justifyContent: "center",
  fontSize: 12,
  color: "#9CA3AF",
};

const errorTextStyle: CSSProperties = {
  marginBottom: 12,
  fontSize: 13,
  color: "#C0392B",
  background: "#FFF1F0",
  borderRadius: 10,
  padding: "10px 12px",
};

const completedCardStyle: CSSProperties = {
  borderRadius: 12,
  border: "1px solid #D1FAE5",
  background: "#ECFDF5",
  padding: "12px 14px",
  boxSizing: "border-box",
  width: "100%",
};

const completedTitleStyle: CSSProperties = {
  color: "#047857",
  fontSize: 14,
  fontWeight: 700,
  marginBottom: 8,
};

const completedFoodStyle: CSSProperties = {
  fontSize: 14,
  color: "#111827",
  fontWeight: 700,
};

const completedMetaStyle: CSSProperties = {
  marginTop: 4,
  fontSize: 13,
  color: "#065F46",
};

const manualFormStyle: CSSProperties = {
  width: "100%",
  paddingBottom: 8,
};

const manualFormTitleStyle: CSSProperties = {
  fontSize: 14,
  fontWeight: 700,
  color: "#111827",
  marginBottom: 10,
};

const macroToggleStyle: CSSProperties = {
  width: "100%",
  marginTop: 12,
  marginBottom: 4,
  border: "none",
  background: "transparent",
  color: "#6B7280",
  fontSize: 12,
  fontWeight: 600,
  padding: "8px 0",
  cursor: "pointer",
  textAlign: "left",
};

const macroFieldsStyle: CSSProperties = {
  marginTop: 8,
};
