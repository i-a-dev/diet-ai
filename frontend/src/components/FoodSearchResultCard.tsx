import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { ORANGE } from "../constants.ts";

interface FoodSearchResultCardProps {
  result: FoodSearchResult;
  onAdd: () => void;
}

export function FoodSearchResultCard({ result, onAdd }: FoodSearchResultCardProps) {
  const sourceLabel =
    result.source === "fatsecret"
      ? "データ提供：FatSecret"
      : result.source === "open_food_facts"
        ? "データ提供：Open Food Facts"
        : result.source === "ai_web_search"
          ? "データ提供：AI Web検索"
          : null;

  return (
    <div style={cardStyle}>
      <div style={titleStyle}>候補が見つかりました</div>
      {sourceLabel && <div style={sourceStyle}>{sourceLabel}</div>}
      <div style={nameStyle}>{result.displayName}</div>
      <div style={calorieStyle}>{result.calories} kcal</div>
      {result.source === "ai_web_search" && result.sourceUrl && (
        <a href={result.sourceUrl} target="_blank" rel="noreferrer noopener" style={sourceLinkStyle}>
          参照元を見る
        </a>
      )}
      <button type="button" onClick={onAdd} style={primaryButtonStyle}>
        この内容で追加する
      </button>
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

const sourceLinkStyle: CSSProperties = {
  display: "inline-block",
  marginTop: 8,
  fontSize: 12,
  color: "#047857",
  textDecoration: "underline",
  wordBreak: "break-all",
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
