import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { ORANGE } from "../constants.ts";

interface LowConfidenceEstimateCardProps {
  result: FoodSearchResult;
  onSearchWeb: () => void;
  onUseAiEstimate: () => void;
  onEdit: () => void;
  showSearchButton?: boolean;
  warningMessage?: string;
}

export function LowConfidenceEstimateCard({
  result,
  onSearchWeb,
  onUseAiEstimate,
  onEdit,
  showSearchButton = true,
  warningMessage,
}: LowConfidenceEstimateCardProps) {
  const isWebFailure = !showSearchButton;

  return (
    <div style={cardStyle}>
      {!isWebFailure ? (
        <>
          <div style={subTitleStyle}>{result.displayName}</div>
          <div style={calorieStyle}>約{result.calories} kcal</div>
          <div style={hintStyle}>
            サイズや量が分かると
            <br />
            より正確に記録できます
          </div>
          <button type="button" onClick={onSearchWeb} style={primaryButtonStyle}>
            サイズ・商品を確認する
          </button>
          <button type="button" onClick={onUseAiEstimate} style={secondaryButtonStyle}>
            このまま記録
          </button>
          <button type="button" onClick={onEdit} style={linkButtonStyle}>
            手入力する
          </button>
        </>
      ) : (
        <>
          <div style={titleStyle}>正確な商品情報を確認できませんでした</div>
          <div style={subTitleStyle}>推定：約{result.calories} kcal</div>
          <div style={warnStyle}>
            {warningMessage ??
              "サイズや量が分からないため、カロリーは目安として記録されます"}
          </div>
          <button type="button" onClick={onUseAiEstimate} style={primaryButtonStyle}>
            このまま記録
          </button>
          <button type="button" onClick={onEdit} style={linkButtonStyle}>
            手入力する
          </button>
        </>
      )}
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

const hintStyle: CSSProperties = {
  marginTop: 8,
  fontSize: 12,
  color: "#7F1D1D",
  lineHeight: 1.5,
};

const warnStyle: CSSProperties = {
  marginTop: 6,
  fontSize: 12,
  color: "#7F1D1D",
  lineHeight: 1.5,
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
