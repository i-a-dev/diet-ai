import type { CSSProperties } from "react";
import { ORANGE } from "../constants.ts";
import type { FoodConfirmationCandidate } from "../types/foodSearch.ts";

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
  const summaryParts = [
    candidate.label,
    candidate.badge,
    `${candidate.kcal} kcal`,
  ].filter(Boolean);

  return (
    <div style={cardStyle} aria-labelledby="single-candidate-heading">
      <h3 id="single-candidate-heading" style={titleStyle}>
        {heading}
      </h3>
      <div style={summaryStyle} aria-label={summaryParts.join(" ")}>
        <div style={productNameStyle}>{candidate.label}</div>
        {candidate.badge && (
          <div style={variantLabelStyle}>{candidate.badge}</div>
        )}
        <div style={kcalStyle}>{candidate.kcal} kcal</div>
      </div>
      <button
        type="button"
        onClick={onConfirm}
        disabled={isSubmitting}
        style={{
          ...primaryButtonStyle,
          opacity: isSubmitting ? 0.6 : 1,
        }}
      >
        これを記録
      </button>
      <button
        type="button"
        onClick={onEdit}
        disabled={isSubmitting}
        style={secondaryButtonStyle}
      >
        修正する
      </button>
    </div>
  );
}

const cardStyle: CSSProperties = {
  borderRadius: 12,
  border: "1px solid #BFDBFE",
  background: "#EFF6FF",
  padding: "12px 14px",
  marginTop: 10,
};

const titleStyle: CSSProperties = {
  color: "#1D4ED8",
  fontSize: 14,
  fontWeight: 700,
  margin: 0,
};

const summaryStyle: CSSProperties = {
  marginTop: 12,
  padding: "12px 14px",
  borderRadius: 10,
  border: "1px solid #DBEAFE",
  background: "#fff",
};

const productNameStyle: CSSProperties = {
  fontSize: 15,
  fontWeight: 700,
  color: "#111827",
  lineHeight: 1.4,
};

const variantLabelStyle: CSSProperties = {
  marginTop: 6,
  fontSize: 14,
  fontWeight: 600,
  color: "#1D4ED8",
};

const kcalStyle: CSSProperties = {
  marginTop: 8,
  fontSize: 16,
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
