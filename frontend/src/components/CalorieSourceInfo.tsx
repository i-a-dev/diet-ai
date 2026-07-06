import type { CSSProperties } from "react";
import type { FoodSource, SearchConfidence } from "../types/foodSearch.ts";
import {
  formatConfidenceText,
  getCalorieSourceLabel,
  shouldShowCalorieSourceUrl,
  shouldShowConfidence,
} from "../utils/calorieSource.ts";

interface CalorieSourceInfoProps {
  caloriesEdited?: boolean;
  source?: FoodSource | string | null;
  sourceUrl?: string | null;
  isEstimated?: boolean;
  confidence?: SearchConfidence | string | null;
  style?: CSSProperties;
}

export function CalorieSourceInfo({
  caloriesEdited,
  source,
  sourceUrl,
  isEstimated,
  confidence,
  style,
}: CalorieSourceInfoProps) {
  const label = getCalorieSourceLabel({
    caloriesEdited,
    source,
    isEstimated,
  });
  const showUrl = shouldShowCalorieSourceUrl({
    caloriesEdited,
    source,
    sourceUrl,
  });
  const showConfidence = shouldShowConfidence({ caloriesEdited, source });
  const confidenceText = showConfidence
    ? formatConfidenceText(confidence)
    : null;

  if (!label && !confidenceText) {
    return null;
  }

  return (
    <div style={{ marginTop: 8, ...style }}>
      {label && <div style={labelStyle}>{label}</div>}
      {confidenceText && (
        <div style={metaStyle}>推定の信頼度: {confidenceText}</div>
      )}
      {showUrl && sourceUrl && (
        <a
          href={sourceUrl}
          target="_blank"
          rel="noreferrer noopener"
          style={sourceLinkStyle}
        >
          参照元を見る
        </a>
      )}
    </div>
  );
}

const labelStyle: CSSProperties = {
  fontSize: 12,
  color: "#047857",
  fontWeight: 600,
  lineHeight: 1.5,
};

const metaStyle: CSSProperties = {
  marginTop: 4,
  fontSize: 12,
  color: "#4B5563",
  lineHeight: 1.5,
};

const sourceLinkStyle: CSSProperties = {
  display: "inline-block",
  marginTop: 6,
  fontSize: 12,
  color: "#047857",
  textDecoration: "underline",
  wordBreak: "break-all",
};
