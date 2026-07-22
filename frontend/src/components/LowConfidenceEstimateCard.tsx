import type { CSSProperties } from "react";
import type { FoodSearchResult } from "../types/foodSearch.ts";
import { ORANGE } from "../constants.ts";
import { CalorieSourceInfo } from "./CalorieSourceInfo.tsx";

interface LowConfidenceEstimateCardProps {
  result: FoodSearchResult;
  onSearchWeb?: () => void;
  onDeepWebSearch?: () => void;
  onUseAiEstimate: () => void;
  onEdit: () => void;
  showSearchButton?: boolean;
  showDeepSearchButton?: boolean;
  warningMessage?: string;
}

export function LowConfidenceEstimateCard({
  result,
  onSearchWeb,
  onDeepWebSearch,
  onUseAiEstimate,
  onEdit,
  showSearchButton = true,
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
      <div style={actionsStyle}>
        <button type="button" onClick={onUseAiEstimate} style={primaryButtonStyle}>
          この内容で追加
        </button>
        {showDeepSearchButton && onDeepWebSearch && (
          <button
            type="button"
            onClick={onDeepWebSearch}
            style={secondaryButtonStyle}
          >
            さらに詳しく検索
          </button>
        )}
        {showSearchButton && onSearchWeb && (
          <button
            type="button"
            onClick={onSearchWeb}
            style={secondaryButtonStyle}
          >
            より正確に調べる
          </button>
        )}
        <button type="button" onClick={onEdit} style={textButtonStyle}>
          編集
        </button>
      </div>
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
  fontSize: 14,
  fontWeight: 700,
  color: "#111827",
  marginBottom: 8,
};

const badgeRowStyle: CSSProperties = {
  display: "flex",
  flexWrap: "wrap",
  alignItems: "center",
  gap: 6,
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
  marginTop: 6,
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
  marginTop: 6,
  fontSize: 12,
  color: "#C2410C",
  lineHeight: 1.5,
};

const actionsStyle: CSSProperties = {
  display: "flex",
  flexDirection: "column",
  gap: 8,
  marginTop: 12,
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
  border: "none",
  borderRadius: 10,
  background: "transparent",
  color: "#6B7280",
  fontWeight: 600,
  fontSize: 13,
  padding: "8px 12px",
  cursor: "pointer",
};
