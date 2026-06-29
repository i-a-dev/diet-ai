import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { ORANGE } from "../constants.ts";

interface FoodEstimateCardProps {
  result: FoodSearchResult;
  onEdit: () => void;
  onAdd: () => void;
  variant?: "estimate" | "history";
  caloriesEdited?: boolean;
}

function parseCaloriesEdited(value: unknown): boolean {
  return value === true || value === 1 || value === "1" || value === "true";
}

function confidenceText(confidence: FoodSearchResult["confidence"]): string {
  switch (confidence) {
    case "high":
      return "高";
    case "medium":
      return "中";
    case "low":
      return "低";
  }
}

export function FoodEstimateCard({
  result,
  onEdit,
  onAdd,
  variant = "estimate",
  caloriesEdited,
}: FoodEstimateCardProps) {
  const isHistory = variant === "history";
  const isEdited = parseCaloriesEdited(caloriesEdited ?? result.caloriesEdited);

  return (
    <div style={cardStyle}>
      <div style={titleStyle}>
        {isHistory ? "過去の記録を選択しました" : "AIがカロリーを推定しました"}
      </div>
      <div style={badgeRowStyle}>
        <span style={badgeStyle}>{isHistory ? "履歴" : "推定"}</span>
        {isHistory && isEdited && (
          <span style={badgeStyle}>前回カロリー編集済み</span>
        )}
      </div>
      <div style={nameStyle}>{result.displayName}</div>
      <div style={calorieStyle}>{result.calories} kcal</div>
      {result.items && result.items.length > 0 && (
        <div style={metaStyle}>内訳: {result.items.map((item) => item.name).join(" + ")}</div>
      )}
      {!isHistory && (
        <div style={metaStyle}>推定の信頼度: {confidenceText(result.confidence)}</div>
      )}
      {isHistory && (
        <div style={metaStyle}>
          {isEdited
            ? "前回、カロリーを手入力・編集して登録した記録です"
            : "前回登録時のカロリーです"}
        </div>
      )}
      <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
        <button type="button" onClick={onEdit} style={secondaryButtonStyle}>
          内容を編集する
        </button>
        <button type="button" onClick={onAdd} style={primaryButtonStyle}>
          {isHistory ? "登録する" : "この内容で追加する"}
        </button>
      </div>
    </div>
  );
}

const cardStyle: CSSProperties = {
  borderRadius: 12,
  border: "1px solid #E5E7EB",
  background: "#FFFFFF",
  padding: "12px 14px",
  marginTop: 10,
};

const titleStyle: CSSProperties = {
  fontSize: 14,
  fontWeight: 700,
  color: "#111827",
  marginBottom: 8,
};

const badgeRowStyle: CSSProperties = {
  display: "flex",
  flexWrap: "wrap",
  alignItems: "center",
  gap: 6,
  marginBottom: 8,
};

const badgeStyle: CSSProperties = {
  display: "inline-block",
  borderRadius: 999,
  background: "#FEF3C7",
  color: "#92400E",
  fontSize: 11,
  padding: "2px 8px",
};

const nameStyle: CSSProperties = {
  fontSize: 14,
  color: "#111827",
  fontWeight: 600,
};

const calorieStyle: CSSProperties = {
  marginTop: 6,
  fontSize: 24,
  fontWeight: 800,
  color: "#111827",
};

const metaStyle: CSSProperties = {
  marginTop: 4,
  color: "#4B5563",
  fontSize: 12,
};

const secondaryButtonStyle: CSSProperties = {
  flex: 1,
  border: "1px solid #E5E7EB",
  borderRadius: 10,
  background: "#fff",
  color: "#4B5563",
  fontWeight: 600,
  fontSize: 13,
  padding: "10px 8px",
  cursor: "pointer",
};

const primaryButtonStyle: CSSProperties = {
  flex: 1,
  border: "none",
  borderRadius: 10,
  background: ORANGE,
  color: "#fff",
  fontWeight: 700,
  fontSize: 13,
  padding: "10px 8px",
  cursor: "pointer",
};
