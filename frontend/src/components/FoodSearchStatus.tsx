import type { CSSProperties } from "react";
import type { FoodSearchStep } from "../types/foodSearch.ts";

interface FoodSearchStatusProps {
  title: string;
  query: string;
  mode: "food" | "web";
  steps: FoodSearchStep[];
  onCancel: () => void;
  showApiDebug?: boolean;
}

export function FoodSearchStatus({
  title,
  query,
  mode,
  steps,
  onCancel,
  showApiDebug = false,
}: FoodSearchStatusProps) {
  const visibleSteps = steps.filter((step) => step.key !== "waiting_user_choice");
  const progressRatio = calcProgressRatio(visibleSteps);
  const activeApiLabel = detectActiveApiLabel(steps, mode);

  return (
    <div style={cardStyle}>
      <div style={iconWrapStyle} aria-hidden>
        <span style={iconStyle}>{mode === "web" ? "🌐" : "🔍"}</span>
      </div>
      <div style={headlineStyle}>{title}</div>
      <div style={queryStyle}>{query}</div>
      <div style={subTextStyle}>
        {mode === "web" ? "公式サイトや栄養成分を確認しています" : "数秒かかる場合があります"}
      </div>
      <div style={progressTrackStyle}>
        <div style={{ ...progressFillStyle, width: `${Math.max(progressRatio * 100, 12)}%` }} />
      </div>

      {showApiDebug && (
        <div style={debugStyle}>
          実行中検索: <strong>{activeApiLabel}</strong>
        </div>
      )}

      <div style={{ display: "grid", gap: 8 }}>
        {visibleSteps.map((step) => (
          <div key={step.key} style={{ ...stepStyle, color: statusColor(step.status) }}>
            <span style={{ width: 20, textAlign: "center" }}>{statusIcon(step.status)}</span>
            <span>{step.label}</span>
          </div>
        ))}
      </div>
      <button type="button" onClick={onCancel} style={cancelButtonStyle}>
        キャンセル
      </button>
    </div>
  );
}

function statusIcon(status: FoodSearchStep["status"]): string {
  switch (status) {
    case "active":
      return "●";
    case "done":
      return "✓";
    case "skipped":
      return "–";
    default:
      return "○";
  }
}

function statusColor(status: FoodSearchStep["status"]): string {
  switch (status) {
    case "active":
      return "#2E7D32";
    case "done":
      return "#4A5568";
    case "skipped":
      return "#A0AEC0";
    default:
      return "#94A3B8";
  }
}

function calcProgressRatio(steps: FoodSearchStep[]): number {
  if (steps.length === 0) return 0;
  const doneCount = steps.filter((step) => step.status === "done").length;
  const activeCount = steps.filter((step) => step.status === "active").length;
  return (doneCount + activeCount * 0.45) / steps.length;
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

const cardStyle: CSSProperties = {
  border: "1px solid #E5E7EB",
  borderRadius: 12,
  padding: "14px 14px 12px",
  marginTop: 10,
  background: "#fff",
};

const iconWrapStyle: CSSProperties = {
  display: "flex",
  justifyContent: "center",
  marginBottom: 6,
};

const iconStyle: CSSProperties = {
  fontSize: 28,
};

const headlineStyle: CSSProperties = {
  textAlign: "center",
  fontSize: 14,
  fontWeight: 700,
  color: "#111827",
  marginBottom: 4,
};

const queryStyle: CSSProperties = {
  textAlign: "center",
  fontSize: 29,
  fontWeight: 800,
  letterSpacing: 0.4,
  color: "#111827",
};

const subTextStyle: CSSProperties = {
  marginTop: 4,
  textAlign: "center",
  fontSize: 12,
  color: "#6B7280",
  marginBottom: 10,
};

const progressTrackStyle: CSSProperties = {
  width: "100%",
  height: 6,
  borderRadius: 999,
  background: "#E5E7EB",
  overflow: "hidden",
  marginBottom: 12,
};

const progressFillStyle: CSSProperties = {
  height: "100%",
  borderRadius: 999,
  background: "linear-gradient(90deg, #F59E0B 0%, #F97316 100%)",
  transition: "width 260ms ease",
};

const debugStyle: CSSProperties = {
  marginBottom: 10,
  padding: "8px 10px",
  background: "#EEF2FF",
  color: "#3730A3",
  border: "1px solid #C7D2FE",
  borderRadius: 8,
  fontSize: 12,
};

const stepStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  gap: 8,
  fontSize: 13,
  minHeight: 20,
};

const cancelButtonStyle: CSSProperties = {
  marginTop: 12,
  width: "100%",
  border: "1px solid #D1D5DB",
  background: "#fff",
  borderRadius: 10,
  padding: "11px 12px",
  color: "#374151",
  fontWeight: 700,
  fontSize: 14,
  cursor: "pointer",
};
