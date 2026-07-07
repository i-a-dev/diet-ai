import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { ORANGE } from "../constants.ts";
import { CalorieSourceInfo } from "./CalorieSourceInfo.tsx";

interface FoodSearchResultCardProps {
  result: FoodSearchResult;
  onAdd?: () => void;
  mode?: "register" | "detail";
}

export function FoodSearchResultCard({
  result,
  onAdd,
  mode = "register",
}: FoodSearchResultCardProps) {
  const isDetail = mode === "detail";
  const databaseSourceLabel =
    result.source === "fatsecret"
      ? "データ提供：FatSecret"
      : result.source === "open_food_facts"
        ? "データ提供：Open Food Facts"
        : null;

  return (
    <div style={cardStyle}>
      {!isDetail && <div style={titleStyle}>候補が見つかりました</div>}
      {databaseSourceLabel && (
        <div style={sourceStyle}>{databaseSourceLabel}</div>
      )}
      <div style={nameStyle}>{result.displayName}</div>
      <div style={calorieStyle}>{result.calories} kcal</div>
      <CalorieSourceInfo
        caloriesEdited={result.caloriesEdited}
        source={result.source}
        sourceUrl={result.sourceUrl}
        isEstimated={result.isEstimated}
        confidence={result.confidence}
      />
      {!isDetail && onAdd && (
        <button type="button" onClick={onAdd} style={primaryButtonStyle}>
          この内容で追加する
        </button>
      )}
    </div>
  );
}

const cardStyle: CSSProperties = {
  borderRadius: 12,
  border: "1px solid #D1FAE5",
  background: "#F0FDF4",
  padding: "12px 14px",
  marginTop: 10,
};

const titleStyle: CSSProperties = {
  fontSize: 14,
  fontWeight: 700,
  color: "#166534",
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

const sourceStyle: CSSProperties = {
  marginBottom: 6,
  fontSize: 12,
  color: "#047857",
  fontWeight: 600,
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
