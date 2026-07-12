import type { CSSProperties } from "react";
import { ORANGE } from "../constants.ts";
import type {
  FoodConfirmationCandidate,
  VariantDimension,
} from "../types/foodSearch.ts";

interface ProductConfirmationCardProps {
  title?: string;
  description?: string;
  productName?: string;
  variantDimension?: VariantDimension | string;
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
  variantDimension = "unknown",
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
    title ?? buildVariantTitle(variantDimension, productName, candidates.length);
  const resolvedDescription =
    description ?? buildVariantDescription(variantDimension);

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

          return (
            <button
              key={candidate.key}
              type="button"
              onClick={() => onSelect(candidate)}
              style={{
                ...candidateButtonStyle,
                borderColor: isSelected ? ORANGE : "#DBEAFE",
                boxShadow: isSelected ? "0 0 0 1px #F97316" : "none",
              }}
            >
              <span style={candidateTextWrapStyle}>
                {candidate.badge && (
                  <span style={candidateBadgeStyle}>{candidate.badge}</span>
                )}
                <span style={candidateNameStyle}>{candidate.label}</span>
              </span>
              <span style={candidateKcalStyle}>{candidate.kcal} kcal</span>
            </button>
          );
        })}
      </div>
      <button type="button" onClick={onManualInput} style={secondaryButtonStyle}>
        その他のサイズ・量
      </button>
      {onUnknown && (
        <button type="button" onClick={onUnknown} style={secondaryButtonStyle}>
          わからない
        </button>
      )}
      {selectedKey && onConfirmSelected && (
        <button type="button" onClick={onConfirmSelected} style={primaryButtonStyle}>
          記録する
        </button>
      )}
      {showWebSearchButton && onSearchWeb && (
        <button
          type="button"
          onClick={onSearchWeb}
          disabled={searchWebDisabled}
          style={{
            ...linkButtonStyle,
            opacity: searchWebDisabled ? 0.45 : 1,
          }}
        >
          サイズ・商品を確認する
        </button>
      )}
    </div>
  );
}

function buildVariantTitle(
  dimension: VariantDimension | string,
  productName?: string,
  candidateCount = 0,
): string {
  switch (dimension) {
    case "named_size":
      return "どのサイズを食べましたか？";
    case "serving_size":
      return "どの盛りサイズを食べましたか？";
    case "weight":
      return "どの内容量の商品ですか？";
    case "volume":
      return "どの容量の商品ですか？";
    case "multiple":
    case "unknown":
      if (candidateCount === 1) {
        return "商品情報の候補がみつかりました";
      }

      return productName
        ? "商品情報が複数見つかりました"
        : "食べたものを選んでください";
    default:
      return "食べたものを選んでください";
  }
}

function buildVariantDescription(dimension: VariantDimension | string): string {
  if (dimension === "multiple" || dimension === "unknown") {
    return "該当するものを選んでください";
  }

  return "";
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
