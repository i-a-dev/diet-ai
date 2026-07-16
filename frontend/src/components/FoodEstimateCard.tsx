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
  variant?: "estimate" | "history" | "detail";
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
  const isEdited = parseCaloriesEdited(caloriesEdited ?? result.caloriesEdited);
  const showActions = !isDetail;
  const databaseSourceLabel =
    result.source === "fatsecret"
      ? "データ提供：FatSecret"
      : result.source === "open_food_facts"
        ? "データ提供：Open Food Facts"
        : null;

  return (
    <div style={cardStyle}>
      {!isDetail && (
        <div style={titleStyle}>
          {isHistory
            ? "過去の記録を選択しました"
            : "AIがカロリーを推定しました"}
        </div>
      )}
      {!isDetail && (
        <div style={badgeRowStyle}>
          <span style={badgeStyle}>{isHistory ? "履歴" : "推定"}</span>
        </div>
      )}
      {isDetail && databaseSourceLabel && (
        <div style={databaseSourceStyle}>{databaseSourceLabel}</div>
      )}
      <div style={nameStyle}>{result.displayName}</div>
      <div style={calorieStyle}>{result.calories} kcal</div>
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
        <>
          <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
            <button type="button" onClick={onEdit} style={secondaryButtonStyle}>
              手入力する
            </button>
            <button type="button" onClick={onAdd} style={primaryButtonStyle}>
              {isHistory ? "登録する" : "この内容で追加する"}
            </button>
          </div>
          {variant === "estimate" && onSearchWeb && (
            <button
              type="button"
              onClick={onSearchWeb}
              style={webSearchButtonStyle}
            >
              AI web検索を行う
            </button>
          )}
        </>
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

const databaseSourceStyle: CSSProperties = {
  marginBottom: 6,
  fontSize: 12,
  color: "#6B7280",
  fontWeight: 600,
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

const webSearchButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 8,
  border: "none",
  borderRadius: 10,
  background: ORANGE,
  color: "#fff",
  fontWeight: 700,
  fontSize: 13,
  padding: "10px 8px",
  cursor: "pointer",
};
