import type { CSSProperties } from "react";
import type { WebSearchQuota } from "../types/foodSearch.ts";
import { ORANGE } from "../constants.ts";

interface WebSearchQuotaCardProps {
  quota: WebSearchQuota;
  limitReached?: boolean;
  onSearchWeb?: () => void;
  onUseAi: () => void;
}

export function WebSearchQuotaCard({
  quota,
  limitReached = false,
  onSearchWeb,
  onUseAi,
}: WebSearchQuotaCardProps) {
  return (
    <div style={cardStyle}>
      <div style={titleStyle}>
        {limitReached
          ? "今月の無料検索回数を使い切りました"
          : "この商品は商品情報検索が必要です"}
      </div>
      <div style={quotaStyle}>
        今月のWeb検索: {quota.usedCount} / {quota.monthlyLimit} 回
      </div>

      {!limitReached && onSearchWeb && (
        <button type="button" onClick={onSearchWeb} style={primaryButtonStyle}>
          商品情報を検索する
        </button>
      )}

      <button type="button" onClick={onUseAi} style={secondaryButtonStyle}>
        AI推定で記録する
      </button>

      {limitReached && (
        <button type="button" style={linkButtonStyle}>
          プレミアムプランを見る
        </button>
      )}
    </div>
  );
}

const cardStyle: CSSProperties = {
  borderRadius: 12,
  border: "1px solid #FDE68A",
  background: "#FFFBEB",
  padding: "12px 14px",
  marginTop: 10,
};

const titleStyle: CSSProperties = {
  fontSize: 14,
  fontWeight: 700,
  color: "#92400E",
};

const quotaStyle: CSSProperties = {
  marginTop: 8,
  fontSize: 13,
  color: "#78350F",
  marginBottom: 10,
};

const primaryButtonStyle: CSSProperties = {
  width: "100%",
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
  border: "1px solid #E5E7EB",
  borderRadius: 10,
  background: "#fff",
  color: "#4B5563",
  fontWeight: 600,
  fontSize: 14,
  padding: "11px 12px",
  marginTop: 8,
  cursor: "pointer",
};

const linkButtonStyle: CSSProperties = {
  width: "100%",
  border: "none",
  background: "transparent",
  color: ORANGE,
  fontWeight: 700,
  fontSize: 13,
  marginTop: 8,
  cursor: "pointer",
};
