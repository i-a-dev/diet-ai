import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { ORANGE } from "../constants.ts";

interface FoodEstimateCardProps {
  result: FoodSearchResult;
  onEdit: () => void;
  onAdd: () => void;
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

export function FoodEstimateCard({ result, onEdit, onAdd }: FoodEstimateCardProps) {
  return (
    <div style={cardStyle}>
      <div style={titleStyle}>AIがカロリーを推定しました</div>
      <div style={badgeStyle}>推定</div>
      <div style={nameStyle}>{result.displayName}</div>
      <div style={calorieStyle}>{result.calories} kcal</div>
      {result.items && result.items.length > 0 && (
        <div style={metaStyle}>内訳: {result.items.map((item) => item.name).join(" + ")}</div>
      )}
      <div style={metaStyle}>推定の信頼度: {confidenceText(result.confidence)}</div>
      <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
        <button type="button" onClick={onEdit} style={secondaryButtonStyle}>
          内容を編集する
        </button>
        <button type="button" onClick={onAdd} style={primaryButtonStyle}>
          この内容で追加する
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

const badgeStyle: CSSProperties = {
  display: "inline-block",
  borderRadius: 999,
  background: "#FEF3C7",
  color: "#92400E",
  fontSize: 11,
  padding: "2px 8px",
  marginBottom: 8,
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
