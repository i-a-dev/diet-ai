import type { CSSProperties } from "react";
import type { FoodConfirmationCandidate } from "../types/foodSearch.ts";
import { CalorieSourceInfo } from "./CalorieSourceInfo.tsx";

interface SingleCandidateConfirmationCardProps {
  heading: string;
  candidate: FoodConfirmationCandidate;
  /** @deprecated 操作はカード外。後方互換のため残す */
  onConfirm?: () => void;
  /** @deprecated 操作はカード外。後方互換のため残す */
  onEdit?: () => void;
  /** @deprecated 操作はカード外。後方互換のため残す */
  isSubmitting?: boolean;
}

/** Web候補1件の情報表示のみ（操作ボタンなし） */
export function SingleCandidateConfirmationCard({
  heading,
  candidate,
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
  color: "#111827",
  fontSize: 14,
  fontWeight: 700,
  margin: 0,
};

const productNameStyle: CSSProperties = {
  marginTop: 12,
  fontSize: 15,
  fontWeight: 700,
  color: "#111827",
  lineHeight: 1.4,
};

const variantLabelStyle: CSSProperties = {
  marginTop: 8,
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
