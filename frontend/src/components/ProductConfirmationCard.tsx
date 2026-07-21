import type { CSSProperties } from "react";
import { ORANGE } from "../constants.ts";
import type { FoodConfirmationCandidate } from "../types/foodSearch.ts";
import { getCandidateConfirmationHeading } from "../utils/candidateConfirmationHeading.ts";

interface ProductConfirmationCardProps {
  title?: string;
  description?: string;
  productName?: string;
  candidates: FoodConfirmationCandidate[];
  selectedKey?: string | null;
  onSelect: (candidate: FoodConfirmationCandidate) => void;
  onManualInput: () => void;
  onUnknown?: () => void;
  onConfirmSelected?: () => void;
  showWebSearchButton?: boolean;
  onSearchWeb?: () => void;
  searchWebDisabled?: boolean;
}

export function ProductConfirmationCard({
  title,
  description,
  productName,
  candidates,
  selectedKey = null,
  onSelect,
  onManualInput,
  onUnknown,
  onConfirmSelected,
  showWebSearchButton = false,
  onSearchWeb,
  searchWebDisabled = false,
}: ProductConfirmationCardProps) {
  const resolvedTitle =
    title ?? getCandidateConfirmationHeading("unknown", candidates.length);
  const resolvedDescription = description ?? "";

  return (
    <div style={cardStyle}>
      {productName && <div style={productNameStyle}>{productName}</div>}
      <div style={titleStyle}>{resolvedTitle}</div>
      {resolvedDescription && (
        <div style={descriptionStyle}>{resolvedDescription}</div>
      )}
      <div style={listStyle}>
        {candidates.map((candidate) => {
          const isSelected = selectedKey === candidate.key;
          const sourceUrl =
            candidate.sourceUrl?.trim() ||
            candidate.webCandidate?.source_url?.trim() ||
            null;

          return (
            <div
              key={candidate.key}
              role="button"
              tabIndex={0}
              onClick={() => onSelect(candidate)}
              onKeyDown={(event) => {
                if (event.key === "Enter" || event.key === " ") {
                  event.preventDefault();
                  onSelect(candidate);
                }
              }}
              style={{
                ...candidateButtonStyle,
                borderColor: isSelected ? ORANGE : "#DBEAFE",
                boxShadow: isSelected ? "0 0 0 1px #F97316" : "none",
              }}
            >
              <span style={candidateMainStyle}>
                <span style={candidateTextWrapStyle}>
                  {candidate.badge && (
                    <span style={candidateBadgeStyle}>{candidate.badge}</span>
                  )}
                  <span style={candidateNameStyle}>{candidate.label}</span>
                </span>
                {sourceUrl && (
                  <a
                    href={sourceUrl}
                    target="_blank"
                    rel="noreferrer noopener"
                    onClick={(event) => event.stopPropagation()}
                    style={sourceLinkStyle}
                  >
                    参照元を見る
                  </a>
                )}
              </span>
              <span style={candidateKcalStyle}>{candidate.kcal} kcal</span>
            </div>
          );
        })}
      </div>
      <button type="button" onClick={onManualInput} style={textButtonStyle}>
        編集
      </button>
      {onUnknown && (
        <button type="button" onClick={onUnknown} style={secondaryButtonStyle}>
          わからない
        </button>
      )}
      {selectedKey && onConfirmSelected && (
        <button type="button" onClick={onConfirmSelected} style={primaryButtonStyle}>
          追加する
        </button>
      )}
      {showWebSearchButton && onSearchWeb && (
        <button
          type="button"
          onClick={onSearchWeb}
          disabled={searchWebDisabled}
          style={{
            ...outlineButtonStyle,
            opacity: searchWebDisabled ? 0.45 : 1,
            cursor: searchWebDisabled ? "not-allowed" : "pointer",
          }}
        >
          より正確に調べる
        </button>
      )}
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

const productNameStyle: CSSProperties = {
  fontSize: 15,
  fontWeight: 700,
  color: "#111827",
  marginBottom: 4,
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
  alignItems: "center",
  gap: 8,
  minWidth: 0,
};

const candidateMainStyle: CSSProperties = {
  display: "flex",
  flexDirection: "column",
  alignItems: "flex-start",
  gap: 4,
  minWidth: 0,
  flex: 1,
};

const candidateBadgeStyle: CSSProperties = {
  flexShrink: 0,
  borderRadius: 999,
  background: "#DBEAFE",
  color: "#1D4ED8",
  fontSize: 12,
  fontWeight: 700,
  lineHeight: 1,
  padding: "5px 8px",
};

const candidateNameStyle: CSSProperties = {
  fontSize: 14,
  fontWeight: 600,
  color: "#111827",
  lineHeight: 1.4,
};

const sourceLinkStyle: CSSProperties = {
  fontSize: 12,
  fontWeight: 600,
  color: "#047857",
  textDecoration: "underline",
  wordBreak: "break-all",
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

const outlineButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 8,
  border: "1px solid #FDBA74",
  borderRadius: 10,
  background: "#fff",
  color: "#C2410C",
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

const primaryButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 8,
  border: "none",
  borderRadius: 10,
  background: ORANGE,
  color: "#fff",
  fontWeight: 700,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
};
