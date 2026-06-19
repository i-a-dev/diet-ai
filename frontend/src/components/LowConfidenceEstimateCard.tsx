import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { ORANGE } from "../constants.ts";

interface LowConfidenceEstimateCardProps {
  result: FoodSearchResult;
  onSearchWeb: () => void;
  onUseAiEstimate: () => void;
  onEdit: () => void;
}

export function LowConfidenceEstimateCard({
  result,
  onSearchWeb,
  onUseAiEstimate,
  onEdit,
}: LowConfidenceEstimateCardProps) {
  return (
    <div style={cardStyle}>
      <div style={titleStyle}>AI推定の精度が低い可能性があります</div>
      <div style={subTitleStyle}>{result.displayName}</div>
      <div style={calorieStyle}>{result.calories} kcal</div>
      <div style={warnStyle}>
        商品名や量が曖昧なため、実際のカロリーと異なる可能性があります
      </div>
      <button type="button" onClick={onSearchWeb} style={primaryButtonStyle}>
        商品情報を検索する
      </button>
      <button type="button" onClick={onUseAiEstimate} style={secondaryButtonStyle}>
        AI推定で追加する
      </button>
      <button type="button" onClick={onEdit} style={linkButtonStyle}>
        内容を編集する
      </button>
    </div>
  );
}

const cardStyle: CSSProperties = {
  borderRadius: 12,
  border: "1px solid #FECACA",
  background: "#FEF2F2",
  padding: "12px 14px",
  marginTop: 10,
};

const titleStyle: CSSProperties = {
  color: "#B91C1C",
  fontSize: 14,
  fontWeight: 700,
};

const subTitleStyle: CSSProperties = {
  marginTop: 8,
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

const warnStyle: CSSProperties = {
  marginTop: 6,
  fontSize: 12,
  color: "#7F1D1D",
};

const primaryButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 12,
  border: "none",
  borderRadius: 10,
  background: ORANGE,
  color: "#fff",
  fontWeight: 700,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
};

const secondaryButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 8,
  border: "1px solid #E5E7EB",
  borderRadius: 10,
  background: "#fff",
  color: "#4B5563",
  fontWeight: 600,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
};

const linkButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 8,
  border: "none",
  background: "transparent",
  color: "#4B5563",
  fontWeight: 600,
  fontSize: 13,
  cursor: "pointer",
};
