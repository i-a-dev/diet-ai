import type { CSSProperties } from "react";
import { Globe, Search } from "lucide-react";
import type { FoodSearchStep } from "../types/foodSearch.ts";

interface FoodSearchStatusProps {
  title: string;
  query: string;
  mode: "food" | "web";
  steps: FoodSearchStep[];
  showApiDebug?: boolean;
  webPhase?: "planning" | "searching_pages" | "extracting_variants";
  /** @deprecated キャンセルはカード外。後方互換のため残す（描画しない） */
  onCancel?: () => void;
}

/**
 * 検索中ステータス表示のみ。
 * - food: ステップ更新に連動した実進捗バー
 * - web: 長時間ほぼ完了付近で止まるため indeterminate
 */
export function FoodSearchStatus({
  title,
  query,
  mode,
  steps,
  showApiDebug = false,
  webPhase,
}: FoodSearchStatusProps) {
  const visibleSteps = steps.filter((step) => step.key !== "waiting_user_choice");
  const progressRatio = calcProgressRatio(visibleSteps);
  const activeApiLabel = detectActiveApiLabel(steps, mode);
  const webSubText = resolveWebSubText(webPhase);
  const useIndeterminateProgress = mode === "web";

  return (
    <div className="food-search-status" style={statusStyle}>
      <div style={iconWrapStyle} aria-hidden>
        {mode === "web" ? (
          <Globe size={28} color="#4B5563" strokeWidth={2} />
        ) : (
          <Search size={28} color="#4B5563" strokeWidth={2} />
        )}
      </div>
      <div style={headlineStyle}>{title}</div>
      <div className="food-search-query">{query}</div>
      <div style={subTextStyle}>
        {mode === "web"
          ? webSubText
          : "数秒かかる場合があります"}
      </div>
      <div
        className="food-search-progress-track"
        role="progressbar"
        aria-label="検索の進捗"
        aria-valuemin={useIndeterminateProgress ? undefined : 0}
        aria-valuemax={useIndeterminateProgress ? undefined : 100}
        aria-valuenow={
          useIndeterminateProgress
            ? undefined
            : Math.round(Math.max(progressRatio * 100, 12))
        }
        aria-valuetext={useIndeterminateProgress ? "検索中" : undefined}
      >
        {useIndeterminateProgress ? (
          <div className="food-search-progress-indeterminate" />
        ) : (
          <div
            className="food-search-progress-fill"
            style={{ width: `${Math.max(progressRatio * 100, 12)}%` }}
          />
        )}
      </div>

      {showApiDebug && (
        <div style={debugStyle}>
          実行中検索: <strong>{activeApiLabel}</strong>
        </div>
      )}
    </div>
  );
}

function calcProgressRatio(steps: FoodSearchStep[]): number {
  if (steps.length === 0) return 0;
  const doneCount = steps.filter((step) => step.status === "done").length;
  const activeCount = steps.filter((step) => step.status === "active").length;
  return (doneCount + activeCount * 0.45) / steps.length;
}

function resolveWebSubText(
  phase?: "planning" | "searching_pages" | "extracting_variants",
): string {
  switch (phase) {
    case "planning":
      return "商品名を確認しています";
    case "searching_pages":
      return "公式サイトや栄養成分を探しています";
    case "extracting_variants":
      return "サイズ・栄養情報を確認しています";
    default:
      return "公式サイトや栄養成分を探しています。少し時間がかかる場合があります";
  }
}

function detectActiveApiLabel(steps: FoodSearchStep[], mode: "food" | "web"): string {
  const activeStep = steps.find((step) => step.status === "active");
  if (!activeStep) {
    return mode === "web" ? "Brave Search / Claude API" : "待機中";
  }

  switch (activeStep.key) {
    case "fatsecret_searching":
      return "FatSecret API";
    case "open_food_facts_searching":
      return "Open Food Facts API";
    case "local_db_searching":
      return "自前食品DB";
    case "claude_estimating":
      return "Claude API（AI推定）";
    case "ai_web_searching":
      return "Brave Search / Claude API";
    case "regex_extracting":
      return "入力解析（ローカル処理）";
    default:
      return "待機中";
  }
}

const statusStyle: CSSProperties = {
  width: "100%",
  maxWidth: 360,
  marginLeft: "auto",
  marginRight: "auto",
};

const iconWrapStyle: CSSProperties = {
  display: "flex",
  justifyContent: "center",
  marginBottom: 8,
};

const headlineStyle: CSSProperties = {
  textAlign: "center",
  fontSize: 14,
  fontWeight: 700,
  color: "#111827",
  marginBottom: 8,
};

const subTextStyle: CSSProperties = {
  marginTop: 8,
  textAlign: "center",
  fontSize: 12,
  color: "#6B7280",
  marginBottom: 12,
};

const debugStyle: CSSProperties = {
  marginTop: 8,
  padding: "8px 10px",
  background: "#EEF2FF",
  color: "#3730A3",
  border: "1px solid #C7D2FE",
  borderRadius: 8,
  fontSize: 12,
};
