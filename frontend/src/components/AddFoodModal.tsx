import {
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
} from "react";
import { BottomSheet } from "./BottomSheet.tsx";
import { ORANGE } from "../constants.ts";
import { FoodSearchStatus } from "./FoodSearchStatus.tsx";
import { FoodResultPreview } from "./FoodResultPreview.tsx";
import { LowConfidenceEstimateCard } from "./LowConfidenceEstimateCard.tsx";
import {
  runAiWebSearch,
  searchFoodByText,
} from "../services/foodSearchService.ts";
import type {
  FoodSearchProgress,
  FoodSearchResult,
  SearchState,
} from "../types/foodSearch.ts";
import {
  fetchMealHistory,
  saveUserFood,
  type MealType,
} from "../api/client.ts";
import type { FoodSource, SearchConfidence } from "../types/foodSearch.ts";
import { mealItemToSearchResult } from "../utils/mealFoodResult.ts";
import { parseCaloriesEdited } from "../utils/calorieSource.ts";

export interface MealItemInput {
  id?: number;
  label: string;
  kcal: string;
  caloriesEdited?: boolean;
  calorieSource?: FoodSource | string | null;
  sourceUrl?: string | null;
  confidence?: SearchConfidence | string | null;
}

interface AddFoodModalProps {
  open: boolean;
  mealType: MealType;
  mealTitle: string;
  suggestions: MealItemInput[];
  currentMealKcal: number;
  currentTotalKcal: number;
  dailyGoalKcal: number | null;
  onClose: () => void;
  onSave: (item: MealItemInput) => Promise<void> | void;
}

const INITIAL_STEPS = [
  { key: "regex_extracting", label: "入力内容を解析中", status: "pending" },
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
    key: "local_db_searching",
    label: "登録済み食品を検索中",
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
  suggestions,
  currentMealKcal,
  currentTotalKcal,
  dailyGoalKcal,
  onClose,
  onSave,
}: AddFoodModalProps) {
  const [inputValue, setInputValue] = useState("");
  const [manualKcal, setManualKcal] = useState("");
  // 変更: 新しい検索フロー状態をモーダル内で一元管理。
  const [progress, setProgress] =
    useState<FoodSearchProgress>(makeInitialProgress);
  const [showManualEdit, setShowManualEdit] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [completedResult, setCompletedResult] =
    useState<FoodSearchResult | null>(null);
  const [historyTab, setHistoryTab] = useState<"recent" | "meal">("recent");
  const [recentHistory, setRecentHistory] = useState<MealItemInput[]>([]);
  const [mealHistory, setMealHistory] = useState<MealItemInput[]>([]);
  const [selectedHistoryItem, setSelectedHistoryItem] =
    useState<MealItemInput | null>(null);
  const activeSearchTokenRef = useRef(0);
  const historyRequestTokenRef = useRef(0);
  const showSearchApiDebug =
    import.meta.env.VITE_FOOD_SEARCH_DEBUG_MODE === "true";

  useLayoutEffect(() => {
    if (!open) {
      // 次回オープン時の初回描画で前回タブが見えないよう、閉じる時点で戻す。
      setHistoryTab("recent");
      activeSearchTokenRef.current = 0;
      return;
    }
    setInputValue("");
    setManualKcal("");
    setProgress(makeInitialProgress());
    setShowManualEdit(false);
    setIsSubmitting(false);
    setCompletedResult(null);
    setSelectedHistoryItem(null);
    setHistoryTab("recent");
    activeSearchTokenRef.current = 0;
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const token = Date.now();
    historyRequestTokenRef.current = token;
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
      }
    })();
  }, [open, mealType]);

  const canSearch = inputValue.trim().length >= 2;
  const selectedResult = progress.result;
  const isSearching =
    progress.state === "searching" || progress.state === "web_searching";
  const isFoodNameLocked =
    isSearching ||
    progress.state === "found" ||
    progress.state === "estimated" ||
    progress.state === "from_history" ||
    progress.state === "low_confidence_estimate" ||
    progress.state === "web_searching" ||
    progress.state === "web_found" ||
    progress.state === "completed";

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
    setShowManualEdit(false);
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

  async function handleSearch() {
    if (!canSearch || isSearching) return;
    const token = Date.now();
    activeSearchTokenRef.current = token;
    setShowManualEdit(false);
    setManualKcal("");
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
      setShowManualEdit(true);
    }
  }

  async function handleWebSearch() {
    if (isSearching) return;
    const token = Date.now();
    activeSearchTokenRef.current = token;
    try {
      // 変更: 低信頼度時のみ、ユーザー操作で AI Web検索を実行する。
      const next = await runAiWebSearch(inputValue, (nextProgress) => {
        if (activeSearchTokenRef.current !== token) return;
        setProgress(nextProgress);
      });
      if (activeSearchTokenRef.current !== token) return;
      setProgress(next);
    } catch (error) {
      if (activeSearchTokenRef.current !== token) return;
      setProgress({
        ...progress,
        state: "error",
        message:
          error instanceof Error ? error.message : "商品情報検索に失敗しました",
      });
      setShowManualEdit(true);
    }
  }

  function handleAiOnly() {
    if (!selectedResult) return;
    void saveItem(selectedResult);
  }

  async function saveItem(result: FoodSearchResult | null) {
    const label = inputValue.trim();
    if (label === "") return;

    setIsSubmitting(true);
    try {
      if (result) {
        const { calories, caloriesEdited } = resolveEditedCalories(result);
        const item: MealItemInput = {
          label: result.displayName,
          kcal: `${calories}kcal`,
          caloriesEdited,
          calorieSource: result.source,
          sourceUrl: result.sourceUrl ?? null,
          confidence: result.confidence,
        };
        await onSave(item);
        if (result.source === "ai_web_search" && !caloriesEdited) {
          try {
            await saveUserFood({
              displayName: item.label,
              name: result.name,
              amount: result.amount,
              unit: result.unit,
              calories,
              source: "ai_web_search",
              rawInput: result.rawInput,
              sourceUrl: result.sourceUrl ?? null,
            });
          } catch (saveFoodError) {
            console.warn("Failed to save user food", saveFoodError);
          }
        }
        setCompletedResult({ ...result, calories, caloriesEdited });
        setProgress({ ...progress, state: "completed" });
        return;
      }

      const parsedKcal = Number(manualKcal);
      if (!Number.isFinite(parsedKcal) || parsedKcal <= 0) return;
      const item: MealItemInput = {
        label,
        kcal: `${Math.round(parsedKcal)}kcal`,
        caloriesEdited: true,
        calorieSource: "user_registered",
        confidence: "low",
      };
      await onSave(item);
      setCompletedResult({
        id: `user-${Date.now()}`,
        name: label,
        displayName: label,
        amount: 1,
        unit: "食",
        calories: Math.round(parsedKcal),
        protein: null,
        fat: null,
        carbs: null,
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
      setShowManualEdit(true);
    } finally {
      setIsSubmitting(false);
    }
  }

  function renderIdleSection() {
    const activeHistory = historyTab === "recent" ? recentHistory : mealHistory;
    const chipItems = activeHistory.length > 0 ? activeHistory : suggestions;
    return (
      <>
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
        <div style={historyScrollAreaStyle}>
          <div style={chipWrapStyle}>
            {chipItems.map((item) => (
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
            {chipItems.length === 0 && (
              <span style={emptyHistoryStyle}>履歴がありません</span>
            )}
          </div>
        </div>
        <button type="button" style={secondaryBtnStyle}>
          バーコードで読み取る
        </button>
        <button
          type="button"
          onClick={() => void handleSearch()}
          disabled={!canSearch || isSubmitting}
          style={{
            ...primaryBtnStyle,
            opacity: canSearch && !isSubmitting ? 1 : 0.45,
          }}
        >
          検索する
        </button>
      </>
    );
  }

  function renderSearchResult(state: SearchState) {
    if (state === "searching" || state === "web_searching") {
      return (
        <FoodSearchStatus
          title={
            state === "web_searching"
              ? "商品情報を検索しています"
              : "食品情報を探しています"
          }
          query={inputValue.trim()}
          mode={state === "web_searching" ? "web" : "food"}
          steps={progress.steps}
          showApiDebug={showSearchApiDebug}
          onCancel={() => {
            activeSearchTokenRef.current = 0;
            setProgress(makeInitialProgress());
          }}
        />
      );
    }

    if ((state === "found" || state === "web_found") && selectedResult) {
      return (
        <FoodResultPreview
          result={selectedResult}
          mode="register"
          onAdd={() => void saveItem(selectedResult)}
        />
      );
    }

    if (state === "estimated" && selectedResult) {
      return (
        <FoodResultPreview
          result={selectedResult}
          mode="register"
          onEdit={() => {
            setShowManualEdit(true);
            setManualKcal(String(selectedResult.calories));
          }}
          onAdd={() => void saveItem(selectedResult)}
          onSearchWeb={() => void handleWebSearch()}
        />
      );
    }

    if (state === "from_history" && selectedResult) {
      const historyEdited = parseCaloriesEdited(
        selectedHistoryItem?.caloriesEdited ?? selectedResult.caloriesEdited,
      );
      return (
        <FoodResultPreview
          result={selectedResult}
          mode="history"
          caloriesEdited={historyEdited}
          onEdit={() => {
            setShowManualEdit(true);
            setManualKcal(String(selectedResult.calories));
          }}
          onAdd={() => void saveItem(selectedResult)}
        />
      );
    }

    if (state === "low_confidence_estimate" && selectedResult) {
      // 変更: low信頼度は自動確定せず、Web検索 or 低信頼度のまま追加を選択させる。
      return (
        <LowConfidenceEstimateCard
          result={selectedResult}
          onSearchWeb={() => void handleWebSearch()}
          onUseAiEstimate={handleAiOnly}
          onEdit={() => setShowManualEdit(true)}
          showSearchButton={!isWebSearchFallback}
          warningMessage={
            isWebSearchFallback
              ? (progress.message ??
                "Web検索しましたが、うまくヒットしませんでした。AI推定カロリーを表示しています。")
              : undefined
          }
        />
      );
    }

    if (state === "completed" && completedResult && completedSummary) {
      return (
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
          <button
            type="button"
            onClick={onClose}
            style={{ ...primaryBtnStyle, marginTop: 10 }}
          >
            閉じる
          </button>
        </div>
      );
    }

    if (state === "error") {
      return (
        <div style={errorTextStyle}>
          {progress.message ?? "検索に失敗しました。手入力で記録してください。"}
        </div>
      );
    }

    return null;
  }

  return (
    <BottomSheet open={open} title={`${mealTitle}を記録`} onClose={onClose}>
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

      {renderSearchResult(progress.state)}
      {progress.state === "idle" && renderIdleSection()}

      {(showManualEdit || progress.state === "error") && (
        <>
          {/* 変更: 失敗時フォールバックとして手入力欄を明示。 */}
          <label style={{ ...fieldLabelStyle, marginTop: 14 }}>
            カロリー（手入力）
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
            disabled={!inputValue.trim() || !manualKcal.trim() || isSubmitting}
            onClick={() => void saveItem(null)}
            style={{
              ...primaryBtnStyle,
              marginTop: 10,
              opacity: inputValue.trim() && manualKcal.trim() ? 1 : 0.45,
            }}
          >
            手入力で追加する
          </button>
        </>
      )}
    </BottomSheet>
  );
}

function toUniqueMealInputs(
  history: Array<{
    label: string;
    calories: number;
    caloriesEdited?: boolean;
    calorieSource?: string | null;
    sourceUrl?: string | null;
    confidence?: string | null;
  }>,
): MealItemInput[] {
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

const inputStyle: CSSProperties = {
  width: "100%",
  boxSizing: "border-box",
  padding: "12px 14px",
  borderRadius: 10,
  border: "1px solid #E8E8E8",
  fontSize: 15,
  color: "#111",
  outline: "none",
};

const hintTitleStyle: CSSProperties = {
  marginTop: 14,
  marginBottom: 8,
  fontSize: 13,
  fontWeight: 700,
  color: "#6B7280",
};

const chipWrapStyle: CSSProperties = {
  display: "flex",
  flexWrap: "wrap",
  gap: 8,
};

const historyScrollAreaStyle: CSSProperties = {
  maxHeight: 180,
  overflowY: "auto",
  border: "1px solid #F1F5F9",
  borderRadius: 10,
  padding: "10px 8px",
  marginBottom: 12,
  background: "#fff",
};

const tabWrapStyle: CSSProperties = {
  display: "grid",
  gridTemplateColumns: "1fr 1fr",
  gap: 8,
  marginBottom: 10,
  padding: 4,
  borderRadius: 12,
  background: "#F3F4F6",
};

const tabButtonStyle: CSSProperties = {
  border: "1px solid transparent",
  borderRadius: 8,
  fontSize: 13,
  fontWeight: 700,
  padding: "9px 10px",
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
  fontSize: 12,
  color: "#9CA3AF",
};

const secondaryBtnStyle: CSSProperties = {
  width: "100%",
  padding: "12px 0",
  borderRadius: 10,
  border: "1px solid #E5E7EB",
  background: "#fff",
  fontSize: 14,
  fontWeight: 600,
  color: "#4B5563",
  cursor: "pointer",
  marginBottom: 8,
};

const primaryBtnStyle: CSSProperties = {
  width: "100%",
  padding: "12px 0",
  borderRadius: 10,
  border: "none",
  background: ORANGE,
  fontSize: 14,
  fontWeight: 700,
  color: "#fff",
  cursor: "pointer",
};

const errorTextStyle: CSSProperties = {
  marginTop: 10,
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
  marginTop: 10,
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
