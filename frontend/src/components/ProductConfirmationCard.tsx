import type { CSSProperties } from "react";
import type { FoodSearchCandidate } from "../types/foodSearch.ts";

interface ProductConfirmationCardProps {
  candidates: FoodSearchCandidate[];
  onSelect: (candidate: FoodSearchCandidate) => void;
  onManualInput: () => void;
}

export function ProductConfirmationCard({
  candidates,
  onSelect,
  onManualInput,
}: ProductConfirmationCardProps) {
  return (
    <div style={cardStyle}>
      <div style={titleStyle}>こちらの商品ですか？</div>
      <div style={descriptionStyle}>
        入力内容から複数の候補が見つかりました。該当する商品を選んでください。
      </div>
      <div style={listStyle}>
        {candidates.map((candidate) => {
          const label = candidate.brand
            ? `${candidate.brand} ${candidate.product_name}`
            : candidate.product_name;

          return (
            <button
              key={`${label}-${candidate.kcal}-${candidate.source_url ?? "no-url"}`}
              type="button"
              onClick={() => onSelect(candidate)}
              style={candidateButtonStyle}
            >
              <span style={candidateNameStyle}>{label}</span>
              <span style={candidateKcalStyle}>{candidate.kcal} kcal</span>
            </button>
          );
        })}
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
