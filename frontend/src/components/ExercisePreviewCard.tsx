import { useState, type CSSProperties } from "react";
import { ChevronDown, ChevronUp, Flame, Info } from "lucide-react";
import {
  buildCalorieFormulaText,
  buildEquivalenceText,
  buildMetsComparisons,
  formatMetsValue,
  getIntensityDescription,
  getIntensityLabel,
  getIntensityLevel,
  getIntensitySegments,
} from "../utils/exercisePreview.ts";

export interface ExercisePreviewCardModel {
  caloriesBurned: number;
  confidence: "high" | "medium" | "low";
  source: "local_db" | "llm_estimate";
  exercise: string;
  estimatedExercise: string;
  minutes: number;
  mets: number;
  note: string;
  weightKg: number;
  weightSource: "current" | "reference" | "default";
  weightRecordedOn: string | null;
}

interface ExercisePreviewCardProps {
  model: ExercisePreviewCardModel;
  isFromHistory?: boolean;
}

export function ExercisePreviewCard({
  model,
  isFromHistory = false,
}: ExercisePreviewCardProps) {
  const [detailsOpen, setDetailsOpen] = useState(false);
  const isLowConfidence = model.confidence === "low";
  const intensityLevel = getIntensityLevel(model.mets);
  const intensitySegments = getIntensitySegments(intensityLevel);
  const comparisons = buildMetsComparisons(model.exercise, model.mets);
  const maxComparisonMets = Math.max(...comparisons.map((item) => item.mets), 1);
  const equivalenceText = buildEquivalenceText(
    model.exercise,
    model.estimatedExercise,
    model.source,
  );
  const formulaText = buildCalorieFormulaText(
    model.mets,
    model.weightKg,
    model.minutes,
    model.caloriesBurned,
  );

  return (
    <div style={cardStyle}>
      <div style={topRowStyle}>
        <span style={kcalTextStyle}>{model.caloriesBurned} kcal</span>
        <span
          style={
            isLowConfidence ? lowBadgeStyle : estimateBadgeStyle
          }
        >
          {isLowConfidence
            ? "低精度"
            : model.source === "llm_estimate"
              ? "AI推定"
              : "ローカルMETs"}
        </span>
      </div>

      <div style={intensityRowStyle}>
        <Flame size={16} color="#2EAA72" strokeWidth={2.4} />
        <span style={intensityTextStyle}>
          運動強度：{getIntensityLabel(intensityLevel)}（
          {getIntensityDescription(model.mets)}）
        </span>
      </div>

      <div style={intensityBarWrapStyle}>
        {intensitySegments.map((segment) => (
          <div key={segment.level} style={intensitySegmentStyle}>
            <div
              style={{
                ...intensityBarTrackStyle,
                ...(segment.active ? intensityBarActiveStyle : undefined),
              }}
            />
            <span
              style={{
                ...intensityBarLabelStyle,
                ...(segment.active ? intensityBarLabelActiveStyle : undefined),
              }}
            >
              {segment.label}
            </span>
          </div>
        ))}
      </div>

      {isLowConfidence && (
        <div style={lowWarningTextStyle}>
          実際のカロリーと異なる場合があります
        </div>
      )}

      <button
        type="button"
        onClick={() => setDetailsOpen((open) => !open)}
        style={detailsToggleStyle}
      >
        <span>この数字の計算方法を見る</span>
        {detailsOpen ? (
          <ChevronUp size={18} color="#9CA3AF" />
        ) : (
          <ChevronDown size={18} color="#9CA3AF" />
        )}
      </button>

      {detailsOpen && (
        <div style={detailsBodyStyle}>
          <p style={equivalenceTextStyle}>{equivalenceText}</p>

          {model.note !== "" && isLowConfidence && (
            <p style={noteTextStyle}>推定根拠: {model.note}</p>
          )}

          <div style={metsInfoBoxStyle}>
            <div style={metsInfoTitleRowStyle}>
              <Info size={14} color="#6B7280" />
              <span style={metsInfoTitleStyle}>METs（メッツ）とは</span>
            </div>
            <p style={metsInfoBodyStyle}>
              安静時（1）の何倍のエネルギーを使うかを表す値です。数字が大きいほど、運動はきつくなります。
            </p>
          </div>

          <div style={comparisonListStyle}>
            {comparisons.map((item) => (
              <div
                key={`${item.name}-${item.mets}`}
                style={{
                  ...comparisonRowStyle,
                  ...(item.isCurrent ? comparisonRowActiveStyle : undefined),
                }}
              >
                <span style={comparisonNameStyle}>{item.name}</span>
                <div style={comparisonBarAreaStyle}>
                  <div
                    style={{
                      ...comparisonBarStyle,
                      width: `${Math.max(12, (item.mets / maxComparisonMets) * 100)}%`,
                      ...(item.isCurrent
                        ? comparisonBarActiveStyle
                        : comparisonBarMutedStyle),
                    }}
                  />
                  <span style={comparisonValueStyle}>
                    {formatMetsValue(item.mets)}
                  </span>
                </div>
              </div>
            ))}
          </div>

          <p style={formulaTextStyle}>{formulaText}</p>

          {model.weightSource === "reference" && model.weightRecordedOn && (
            <p style={weightInfoStyle}>
              {formatShortDate(model.weightRecordedOn)}の体重 {model.weightKg}
              kg をもとに計算しました
            </p>
          )}
          {model.weightSource === "default" && (
            <p style={weightWarningStyle}>
              {isFromHistory
                ? "登録時に体重が未登録のため、60kgで計算したカロリーです"
                : "体重が未登録のため60kgで計算しています"}
            </p>
          )}
        </div>
      )}
    </div>
  );
}

function formatShortDate(date: string): string {
  const [year, month, day] = date.split("-");
  if (!year || !month || !day) return date;
  return `${Number(month)}/${Number(day)}`;
}

const cardStyle: CSSProperties = {
  borderRadius: 14,
  border: "1px solid #BFE6D0",
  background: "#FFFFFF",
  padding: "14px 14px 12px",
};

const topRowStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  gap: 8,
};

const kcalTextStyle: CSSProperties = {
  color: "#2EAA72",
  fontSize: 28,
  fontWeight: 800,
  lineHeight: 1.1,
};

const estimateBadgeStyle: CSSProperties = {
  fontSize: 11,
  color: "#2E7D5A",
  background: "#D6F5E8",
  borderRadius: 999,
  padding: "3px 10px",
  fontWeight: 700,
  flexShrink: 0,
};

const lowBadgeStyle: CSSProperties = {
  fontSize: 11,
  color: "#9A5515",
  background: "#FDE8D2",
  borderRadius: 999,
  padding: "3px 10px",
  fontWeight: 700,
  flexShrink: 0,
};

const intensityRowStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  gap: 6,
  marginTop: 10,
};

const intensityTextStyle: CSSProperties = {
  fontSize: 13,
  color: "#2E7D5A",
  fontWeight: 700,
};

const intensityBarWrapStyle: CSSProperties = {
  display: "grid",
  gridTemplateColumns: "repeat(4, 1fr)",
  gap: 6,
  marginTop: 10,
};

const intensitySegmentStyle: CSSProperties = {
  display: "flex",
  flexDirection: "column",
  gap: 4,
};

const intensityBarTrackStyle: CSSProperties = {
  height: 8,
  borderRadius: 999,
  background: "#E5E7EB",
};

const intensityBarActiveStyle: CSSProperties = {
  background: "#2EAA72",
};

const intensityBarLabelStyle: CSSProperties = {
  fontSize: 11,
  color: "#9CA3AF",
  textAlign: "center",
  fontWeight: 600,
};

const intensityBarLabelActiveStyle: CSSProperties = {
  color: "#2E7D5A",
  fontWeight: 800,
};

const lowWarningTextStyle: CSSProperties = {
  marginTop: 8,
  fontSize: 12,
  color: "#B45309",
  fontWeight: 600,
};

const detailsToggleStyle: CSSProperties = {
  width: "100%",
  marginTop: 12,
  border: "1px solid #E5E7EB",
  borderRadius: 10,
  background: "#FFFFFF",
  color: "#6B7280",
  fontSize: 13,
  fontWeight: 600,
  padding: "10px 12px",
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  cursor: "pointer",
};

const detailsBodyStyle: CSSProperties = {
  marginTop: 12,
};

const equivalenceTextStyle: CSSProperties = {
  margin: 0,
  fontSize: 13,
  color: "#374151",
  lineHeight: 1.6,
};

const noteTextStyle: CSSProperties = {
  margin: "8px 0 0",
  fontSize: 12,
  color: "#7C5A3D",
  lineHeight: 1.5,
};

const metsInfoBoxStyle: CSSProperties = {
  marginTop: 12,
  borderRadius: 10,
  background: "#F3F4F6",
  padding: "10px 12px",
};

const metsInfoTitleRowStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  gap: 6,
};

const metsInfoTitleStyle: CSSProperties = {
  fontSize: 12,
  fontWeight: 700,
  color: "#374151",
};

const metsInfoBodyStyle: CSSProperties = {
  margin: "6px 0 0",
  fontSize: 12,
  color: "#6B7280",
  lineHeight: 1.6,
};

const comparisonListStyle: CSSProperties = {
  marginTop: 12,
  display: "flex",
  flexDirection: "column",
  gap: 8,
};

const comparisonRowStyle: CSSProperties = {
  display: "grid",
  gridTemplateColumns: "72px 1fr",
  alignItems: "center",
  gap: 8,
  borderRadius: 8,
  padding: "4px 6px",
};

const comparisonRowActiveStyle: CSSProperties = {
  background: "#EDF9F3",
};

const comparisonNameStyle: CSSProperties = {
  fontSize: 12,
  color: "#374151",
  fontWeight: 600,
};

const comparisonBarAreaStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  gap: 8,
  minWidth: 0,
};

const comparisonBarStyle: CSSProperties = {
  height: 10,
  borderRadius: 999,
  minWidth: 12,
};

const comparisonBarMutedStyle: CSSProperties = {
  background: "#B8E6D2",
};

const comparisonBarActiveStyle: CSSProperties = {
  background: "#2EAA72",
};

const comparisonValueStyle: CSSProperties = {
  fontSize: 12,
  color: "#374151",
  fontWeight: 700,
  minWidth: 28,
  textAlign: "right",
};

const formulaTextStyle: CSSProperties = {
  margin: "12px 0 0",
  fontSize: 12,
  color: "#6B7280",
  lineHeight: 1.5,
};

const weightInfoStyle: CSSProperties = {
  margin: "8px 0 0",
  fontSize: 12,
  color: "#4B5563",
  lineHeight: 1.5,
};

const weightWarningStyle: CSSProperties = {
  margin: "8px 0 0",
  fontSize: 12,
  color: "#B45309",
  lineHeight: 1.5,
};
