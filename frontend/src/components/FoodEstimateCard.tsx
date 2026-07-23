import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { CalorieSourceInfo } from "./CalorieSourceInfo.tsx";
import { parseCaloriesEdited } from "../utils/calorieSource.ts";

interface FoodEstimateCardProps {
  result: FoodSearchResult;
  variant?: "estimate" | "history" | "detail" | "found";
  caloriesEdited?: boolean;
  /** @deprecated 操作はカード外へ分離。後方互換のため残す（描画しない） */
  onEdit?: () => void;
  /** @deprecated 操作はカード外へ分離。後方互換のため残す（描画しない） */
  onAdd?: () => void;
  /** @deprecated 操作はカード外へ分離。後方互換のため残す（描画しない） */
  onSearchWeb?: () => void;
}

/** 検索結果・推定・履歴の情報表示のみ（操作ボタンなし） */
export function FoodEstimateCard({
  result,
  variant = "estimate",
  caloriesEdited,
}: FoodEstimateCardProps) {
  const isHistory = variant === "history";
  const isDetail = variant === "detail";
  const isFound = variant === "found";
  const isEstimate = variant === "estimate";
  const isEdited = parseCaloriesEdited(caloriesEdited ?? result.caloriesEdited);
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
    </div>
  );
}

const cardStyle: CSSProperties = {
  borderRadius: 12,
  border: "1px solid #E5E7EB",
  background: "#FFFFFF",
  padding: "12px 14px",
  boxSizing: "border-box",
  width: "100%",
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
  gap: 8,
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
  marginTop: 8,
  fontSize: 24,
  fontWeight: 800,
  color: "#111827",
};

const metaStyle: CSSProperties = {
  marginTop: 8,
  color: "#4B5563",
  fontSize: 12,
};
