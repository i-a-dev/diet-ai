import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { ORANGE } from "../constants.ts";
import { CalorieSourceInfo } from "./CalorieSourceInfo.tsx";
import { parseCaloriesEdited } from "../utils/calorieSource.ts";

interface FoodEstimateCardProps {
  result: FoodSearchResult;
  onEdit: () => void;
  onAdd: () => void;
  onSearchWeb?: () => void;
  variant?: "estimate" | "history" | "detail" | "found";
  caloriesEdited?: boolean;
}

export function FoodEstimateCard({
  result,
  onEdit,
  onAdd,
  onSearchWeb,
  variant = "estimate",
  caloriesEdited,
}: FoodEstimateCardProps) {
  const isHistory = variant === "history";
  const isDetail = variant === "detail";
  const isFound = variant === "found";
  const isEstimate = variant === "estimate";
  const isEdited = parseCaloriesEdited(caloriesEdited ?? result.caloriesEdited);
  const showActions = !isDetail;
  const showApprox = isEstimate && !isEdited;
  const title = isHistory
    ? "過去の記録を選択しました"
    : isFound
      ? "候補が見つかりました"
      : "AIでカロリーを推定しました";

  return (
    <div style={cardStyle}>
      {!isDetail && <div style={titleStyle}>{title}</div>}
      {!isDetail && !isFound && (
        <div style={badgeRowStyle}>
          <span style={badgeStyle}>{isHistory ? "履歴" : "推定"}</span>
        </div>
      )}
      <div style={nameStyle}>{result.displayName}</div>
      <div style={calorieStyle}>
        {showApprox ? "約" : ""}
        {result.calories} kcal
      </div>
      {result.items && result.items.length > 0 && (
        <div style={metaStyle}>
          内訳: {result.items.map((item) => item.name).join(" + ")}
        </div>
      )}
      <CalorieSourceInfo
        caloriesEdited={isEdited}
        source={result.source}
        sourceUrl={result.sourceUrl}
        isEstimated={result.isEstimated}
        confidence={result.confidence}
      />
      {showActions && (
        <div style={actionsStyle}>
          <button type="button" onClick={onAdd} style={primaryButtonStyle}>
            追加する
          </button>
          <button type="button" onClick={onEdit} style={textButtonStyle}>
            編集
          </button>
          {isEstimate && onSearchWeb && (
            <button type="button" onClick={onSearchWeb} style={textButtonStyle}>
              より正確に調べる
            </button>
          )}
        </div>
      )}
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

const actionsStyle: CSSProperties = {
  display: "flex",
  flexDirection: "column",
  gap: 4,
  marginTop: 12,
};

const primaryButtonStyle: CSSProperties = {
  width: "100%",
  border: "none",
  borderRadius: 10,
  background: ORANGE,
  color: "#fff",
  fontWeight: 700,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
};

const textButtonStyle: CSSProperties = {
  width: "100%",
  border: "none",
  borderRadius: 10,
  background: "transparent",
  color: "#6B7280",
  fontWeight: 600,
  fontSize: 13,
  padding: "8px 12px",
  cursor: "pointer",
};
