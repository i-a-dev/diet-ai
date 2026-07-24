import type { CSSProperties } from "react";
import { Globe, Search } from "lucide-react";
import type { FoodSearchStep } from "../types/foodSearch.ts";

interface FoodSearchStatusProps {
  title: string;
  query: string;
  mode: "food" | "web";
  /** 0–100。画面上に数値は出さないが ARIA とバー幅に使う */
  progressPercent: number;
  /** フェーズ／ステップに応じた待機文言 */
  statusMessage: string;
  /** 固定の補足文言 */
  hintMessage: string;
  steps?: FoodSearchStep[];
  showApiDebug?: boolean;
  /** 完了アニメーション中（width トランジションを少し長く） */
  isFinishing?: boolean;
  /** @deprecated キャンセルはカード外。後方互換のため残す（描画しない） */
  onCancel?: () => void;
}

/**
 * 検索中ステータス表示のみ。
 * - food / web とも determinate 風の進捗バー
 * - 文言の読み上げは statusMessage 変更時のみ（aria-live=polite）
 */
export function FoodSearchStatus({
  title,
  query,
  mode,
  progressPercent,
  statusMessage,
  hintMessage,
  steps = [],
  showApiDebug = false,
  isFinishing = false,
}: FoodSearchStatusProps) {
  const clampedPercent = clampProgressPercent(progressPercent);
  const activeApiLabel = detectActiveApiLabel(steps, mode);
  const fillClassName = [
    "food-search-progress-fill",
    mode === "web" ? "food-search-progress-fill--soft" : "",
    isFinishing ? "food-search-progress-fill--finishing" : "",
  ]
    .filter(Boolean)
    .join(" ");

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
      <div
        style={statusMessageStyle}
        aria-live="polite"
        aria-atomic="true"
      >
        {statusMessage}
      </div>
      <div style={hintStyle}>{hintMessage}</div>
      <div
        className="food-search-progress-track"
        role="progressbar"
        aria-label="検索の進捗"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.round(clampedPercent)}
        aria-valuetext={statusMessage}
      >
        <div
          className={fillClassName}
          style={{ width: `${clampedPercent}%` }}
        />
      </div>

      {showApiDebug && (
        <div style={debugStyle}>
          実行中検索: <strong>{activeApiLabel}</strong>
        </div>
      )}
    </div>
  );
}

function clampProgressPercent(value: number): number {
  if (!Number.isFinite(value)) return 0;
  if (value < 0) return 0;
  if (value > 100) return 100;
  return value;
}

function detectActiveApiLabel(
  steps: FoodSearchStep[],
  mode: "food" | "web",
): string {
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
    case "alias_db_searching":
      return "Alias DB";
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

const statusMessageStyle: CSSProperties = {
  marginTop: 8,
  textAlign: "center",
  fontSize: 13,
  fontWeight: 600,
  color: "#374151",
  marginBottom: 4,
};

const hintStyle: CSSProperties = {
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
