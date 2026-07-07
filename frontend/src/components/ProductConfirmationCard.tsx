import type { CSSProperties } from "react";
import type { FoodConfirmationCandidate } from "../types/foodSearch.ts";

interface ProductConfirmationCardProps {
  title?: string;
  description?: string;
  candidates: FoodConfirmationCandidate[];
  onSelect: (candidate: FoodConfirmationCandidate) => void;
  onManualInput: () => void;
}

export function ProductConfirmationCard({
  title = "こちらの商品ですか？",
  description = "入力内容から複数の候補が見つかりました。該当する商品を選んでください。",
  candidates,
  onSelect,
  onManualInput,
}: ProductConfirmationCardProps) {
  return (
    <div style={cardStyle}>
      <div style={titleStyle}>{title}</div>
      <div style={descriptionStyle}>{description}</div>
      <div style={listStyle}>
        {candidates.map((candidate) => (
          <button
            key={candidate.key}
            type="button"
            onClick={() => onSelect(candidate)}
            style={candidateButtonStyle}
          >
            <span style={candidateTextWrapStyle}>
              <span style={candidateNameStyle}>{candidate.label}</span>
              {candidate.badge && (
                <span style={candidateBadgeStyle}>{candidate.badge}</span>
              )}
            </span>
            <span style={candidateKcalStyle}>{candidate.kcal} kcal</span>
          </button>
        ))}
      </div>
      <button type="button" onClick={onManualInput} style={secondaryButtonStyle}>
        どれでもない / 手入力する
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
};

const descriptionStyle: CSSProperties = {
  marginTop: 6,
  fontSize: 12,
  color: "#1E3A8A",
  lineHeight: 1.5,
};

const listStyle: CSSProperties = {
  display: "grid",
  gap: 8,
  marginTop: 12,
};

const candidateButtonStyle: CSSProperties = {
  width: "100%",
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  gap: 12,
  border: "1px solid #DBEAFE",
  borderRadius: 10,
  background: "#fff",
  padding: "11px 12px",
  cursor: "pointer",
  textAlign: "left",
};

const candidateTextWrapStyle: CSSProperties = {
  display: "flex",
  flexDirection: "column",
  gap: 4,
  minWidth: 0,
};

const candidateNameStyle: CSSProperties = {
  fontSize: 14,
  fontWeight: 600,
  color: "#111827",
  lineHeight: 1.4,
};

const candidateBadgeStyle: CSSProperties = {
  display: "inline-flex",
  alignSelf: "flex-start",
  fontSize: 11,
  fontWeight: 700,
  color: "#1D4ED8",
  background: "#DBEAFE",
  borderRadius: 999,
  padding: "2px 8px",
};

const candidateKcalStyle: CSSProperties = {
  flexShrink: 0,
  fontSize: 14,
  fontWeight: 800,
  color: "#111827",
};

const secondaryButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 10,
  border: "1px solid #E5E7EB",
  borderRadius: 10,
  background: "#fff",
  color: "#4B5563",
  fontWeight: 600,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
};
