import type { CSSProperties } from "react";
import { ORANGE } from "../constants.ts";
import type { FoodConfirmationCandidate } from "../types/foodSearch.ts";
import { CalorieSourceInfo } from "./CalorieSourceInfo.tsx";

interface SingleCandidateConfirmationCardProps {
  heading: string;
  candidate: FoodConfirmationCandidate;
  onConfirm: () => void;
  onEdit: () => void;
  isSubmitting?: boolean;
}

export function SingleCandidateConfirmationCard({
  heading,
  candidate,
  onConfirm,
  onEdit,
  isSubmitting = false,
}: SingleCandidateConfirmationCardProps) {
  const webCandidate = candidate.webCandidate;
  const sourceUrl = webCandidate?.source_url?.trim() || null;

  return (
    <div style={cardStyle} aria-labelledby="single-candidate-heading">
      <h3 id="single-candidate-heading" style={titleStyle}>
        {heading}
      </h3>
      <div style={productNameStyle}>{candidate.label}</div>
      {candidate.badge && (
        <div style={variantLabelStyle}>{candidate.badge}</div>
      )}
      <div style={kcalStyle}>{candidate.kcal} kcal</div>
      <CalorieSourceInfo
        source={webCandidate?.source ?? "brave_html"}
        sourceUrl={sourceUrl}
      />
      <button
        type="button"
        onClick={onConfirm}
        disabled={isSubmitting}
        style={{
          ...primaryButtonStyle,
          opacity: isSubmitting ? 0.6 : 1,
        }}
      >
        追加する
      </button>
      <button
        type="button"
        onClick={onEdit}
        disabled={isSubmitting}
        style={textButtonStyle}
      >
        編集
      </button>
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
  color: "#111827",
  fontSize: 14,
  fontWeight: 700,
  margin: 0,
};

const productNameStyle: CSSProperties = {
  marginTop: 10,
  fontSize: 15,
  fontWeight: 700,
  color: "#111827",
  lineHeight: 1.4,
};

const variantLabelStyle: CSSProperties = {
  marginTop: 6,
  fontSize: 14,
  fontWeight: 600,
  color: "#4B5563",
};

const kcalStyle: CSSProperties = {
  marginTop: 8,
  fontSize: 24,
  fontWeight: 800,
  color: "#111827",
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

const textButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 4,
  border: "none",
  borderRadius: 10,
  background: "transparent",
  color: "#6B7280",
  fontWeight: 600,
  fontSize: 13,
  padding: "8px 12px",
  cursor: "pointer",
};
