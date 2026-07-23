import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { CalorieSourceInfo } from "./CalorieSourceInfo.tsx";

interface LowConfidenceEstimateCardProps {
  result: FoodSearchResult;
  showDeepSearchButton?: boolean;
  warningMessage?: string;
  /** @deprecated 操作はカード外。後方互換のため残す */
  onSearchWeb?: () => void;
  /** @deprecated 操作はカード外。後方互換のため残す */
  onDeepWebSearch?: () => void;
  /** @deprecated 操作はカード外。後方互換のため残す */
  onUseAiEstimate?: () => void;
  /** @deprecated 操作はカード外。後方互換のため残す */
  onEdit?: () => void;
  /** @deprecated 操作はカード外。後方互換のため残す */
  showSearchButton?: boolean;
}

/** 低信頼推定の情報・警告のみ（操作ボタンなし） */
export function LowConfidenceEstimateCard({
  result,
  showDeepSearchButton = false,
  warningMessage,
}: LowConfidenceEstimateCardProps) {
  const isWebFailure = warningMessage != null && warningMessage !== "";

  return (
    <div style={cardStyle}>
      <div style={titleStyle}>AIでカロリーを推定しました</div>
      <div style={badgeRowStyle}>
        <span style={badgeStyle}>推定</span>
      </div>
      <div style={subTitleStyle}>{result.displayName}</div>
      <div style={calorieStyle}>約{result.calories} kcal</div>
      <CalorieSourceInfo
        source={result.source}
        sourceUrl={result.sourceUrl}
        isEstimated={result.isEstimated}
        confidence={result.confidence}
      />
      <div style={noticeBoxStyle}>
        <div style={noticeMainStyle}>
          {isWebFailure
            ? warningMessage
            : "正確なカロリーを特定できませんでした"}
        </div>
        {!isWebFailure && (
          <div style={noticeTipStyle}>
            サイズや量が分かると、より正確に記録できます
          </div>
        )}
        {showDeepSearchButton && (
          <div style={noticeTipStyle}>時間と追加処理が必要です</div>
        )}
      </div>
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
  fontSize: 14,
  fontWeight: 700,
  color: "#111827",
  marginBottom: 8,
};

const badgeRowStyle: CSSProperties = {
  display: "flex",
  flexWrap: "wrap",
  alignItems: "center",
  gap: 8,
  marginBottom: 8,
};

const badgeStyle: CSSProperties = {
  display: "inline-block",
  borderRadius: 999,
  background: "#FEF3C7",
  color: "#92400E",
  fontSize: 11,
  padding: "2px 8px",
};

const subTitleStyle: CSSProperties = {
  fontSize: 14,
  color: "#111827",
  fontWeight: 600,
};

const calorieStyle: CSSProperties = {
  marginTop: 8,
  fontSize: 24,
  fontWeight: 800,
  color: "#111827",
};

const noticeBoxStyle: CSSProperties = {
  marginTop: 12,
  borderRadius: 10,
  background: "#FFF7ED",
  border: "1px solid #FED7AA",
  padding: "10px 12px",
};

const noticeMainStyle: CSSProperties = {
  fontSize: 13,
  fontWeight: 600,
  color: "#9A3412",
  lineHeight: 1.5,
};

const noticeTipStyle: CSSProperties = {
  marginTop: 8,
  fontSize: 12,
  color: "#C2410C",
  lineHeight: 1.5,
};
