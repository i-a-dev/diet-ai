import { useEffect, useMemo, useState, type CSSProperties } from "react";
import { BottomSheet } from "./BottomSheet.tsx";
import { ORANGE } from "../constants.ts";
import { FoodSearchStatus } from "./FoodSearchStatus.tsx";
import { FoodSearchResultCard } from "./FoodSearchResultCard.tsx";
import { FoodEstimateCard } from "./FoodEstimateCard.tsx";
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

export interface MealItemInput {
  label: string;
  kcal: string;
}

interface AddFoodModalProps {
  open: boolean;
  mealTitle: string;
  suggestions: MealItemInput[];
  currentMealKcal: number;
  currentTotalKcal: number;
  dailyGoalKcal: number;
  onClose: () => void;
  onSave: (item: MealItemInput) => Promise<void> | void;
}

const INITIAL_STEPS = [
  { key: "regex_extracting", label: "入力内容を解析中", status: "pending" },
  { key: "fatsecret_searching", label: "FatSecretで食品データを検索中", status: "pending" },
  { key: "open_food_facts_searching", label: "Open Food Factsで商品情報を検索中", status: "pending" },
  { key: "claude_estimating", label: "AIでカロリーを推定中", status: "pending" },
  { key: "waiting_user_choice", label: "ユーザー選択を待機中", status: "pending" },
  { key: "ai_web_searching", label: "商品情報を検索中", status: "pending" },
] as const;

function makeInitialProgress(): FoodSearchProgress {
  return {
    state: "idle",
    steps: INITIAL_STEPS.map((step) => ({ ...step })),
    result: null,
    quota: null,
  };
}

export function AddFoodModal({
  open,
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
  const [progress, setProgress] = useState<FoodSearchProgress>(makeInitialProgress);
  const [showManualEdit, setShowManualEdit] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [completedResult, setCompletedResult] = useState<FoodSearchResult | null>(null);

  useEffect(() => {
    if (!open) return;
    setInputValue("");
    setManualKcal("");
    setProgress(makeInitialProgress());
    setShowManualEdit(false);
    setIsSubmitting(false);
    setCompletedResult(null);
  }, [open]);

  const canSearch = inputValue.trim().length >= 2;
  const selectedResult = progress.result;
  const isSearching = progress.state === "searching" || progress.state === "web_searching";
  const isFoodNameLocked =
    progress.state === "estimated" ||
    progress.state === "low_confidence_estimate" ||
    progress.state === "web_searching" ||
    progress.state === "web_found" ||
    progress.state === "completed";

  const completedSummary = useMemo(() => {
    if (!completedResult) return null;
    const nextMealTotal = currentMealKcal + completedResult.calories;
    const nextTotal = currentTotalKcal + completedResult.calories;
    const remaining = Math.max(dailyGoalKcal - nextTotal, 0);
    return { nextMealTotal, nextTotal, remaining };
  }, [completedResult, currentMealKcal, currentTotalKcal, dailyGoalKcal]);

  async function handleSearch() {
    if (!canSearch || isSearching) return;
    setShowManualEdit(false);
    setManualKcal("");
    try {
      // 変更: Regex → FatSecret → Open Food Facts → Claude 推定の順で検索。
      const next = await searchFoodByText(inputValue, setProgress);
      setProgress(next);
    } catch (error) {
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
    try {
      // 変更: 低信頼度時のみ、ユーザー操作で AI Web検索を実行する。
      const next = await runAiWebSearch(inputValue, setProgress);
      setProgress(next);
    } catch (error) {
      setProgress({
        ...progress,
        state: "error",
        message: error instanceof Error ? error.message : "商品情報検索に失敗しました",
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
        const item = {
          label: result.name,
          kcal: `${result.calories}kcal`,
        };
        await onSave(item);
        setCompletedResult(result);
        setProgress({ ...progress, state: "completed" });
        return;
      }

      const parsedKcal = Number(manualKcal);
      if (!Number.isFinite(parsedKcal) || parsedKcal <= 0) return;
      const item = { label, kcal: `${Math.round(parsedKcal)}kcal` };
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
        message: saveError instanceof Error ? saveError.message : "保存に失敗しました",
      });
      setShowManualEdit(true);
    } finally {
      setIsSubmitting(false);
    }
  }

  function renderIdleSection() {
    return (
      <>
        <div style={hintTitleStyle}>よく使う食品</div>
        <div style={chipWrapStyle}>
          {suggestions.map((item) => (
            <button
              key={item.label}
              type="button"
              onClick={() => {
                setInputValue(item.label);
                setManualKcal(item.kcal.replace("kcal", ""));
              }}
              style={chipStyle}
            >
              {item.label}
            </button>
          ))}
        </div>
        <button type="button" style={secondaryBtnStyle}>
          バーコードで読み取る
        </button>
        <button
          type="button"
          onClick={() => void handleSearch()}
          disabled={!canSearch || isSubmitting}
          style={{ ...primaryBtnStyle, opacity: canSearch && !isSubmitting ? 1 : 0.45 }}
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
          title={state === "web_searching" ? "商品情報を検索中..." : "食品データを検索中..."}
          steps={progress.steps}
        />
      );
    }

    if ((state === "found" || state === "web_found") && selectedResult) {
      return <FoodSearchResultCard result={selectedResult} onAdd={() => void saveItem(selectedResult)} />;
    }

    if (state === "estimated" && selectedResult) {
      return (
        <FoodEstimateCard
          result={selectedResult}
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
        />
      );
    }

    if (state === "completed" && completedResult && completedSummary) {
      return (
        <div style={completedCardStyle}>
          <div style={completedTitleStyle}>追加しました</div>
          <div style={completedFoodStyle}>
            {completedResult.name} / {completedResult.calories}kcal
          </div>
          <div style={completedMetaStyle}>
            {mealTitle} 合計: {completedSummary.nextMealTotal}kcal
          </div>
          <div style={completedMetaStyle}>今日の合計: {completedSummary.nextTotal}kcal</div>
          <div style={completedMetaStyle}>今日の残り: {completedSummary.remaining}kcal</div>
          <button type="button" onClick={onClose} style={{ ...primaryBtnStyle, marginTop: 10 }}>
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
          <label style={{ ...fieldLabelStyle, marginTop: 14 }}>カロリー（手入力）</label>
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
  marginBottom: 12,
};

const chipStyle: CSSProperties = {
  padding: "8px 12px",
  borderRadius: 999,
  border: "1px solid #F5E1D2",
  background: "#FFF5EB",
  fontSize: 13,
  color: "#8B5E3C",
  cursor: "pointer",
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
