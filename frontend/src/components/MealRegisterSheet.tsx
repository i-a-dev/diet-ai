import { useEffect, useRef, useState, type CSSProperties } from "react";
import { estimateCalories } from "../api/client.ts";
import { BottomSheet } from "./BottomSheet.tsx";
import { ORANGE } from "../constants.ts";

export interface MealItemInput {
  label: string;
  kcal: string;
}

interface MealRegisterSheetProps {
  open: boolean;
  mealTitle: string;
  suggestions: MealItemInput[];
  onClose: () => void;
  onSave: (item: MealItemInput) => void;
}

export function MealRegisterSheet({
  open,
  mealTitle,
  suggestions,
  onClose,
  onSave,
}: MealRegisterSheetProps) {
  const [label, setLabel] = useState("");
  const [kcal, setKcal] = useState("");
  const [isEstimating, setIsEstimating] = useState(false);
  const [estimateError, setEstimateError] = useState<string | null>(null);
  const [estimateHint, setEstimateHint] = useState<string | null>(null);
  const kcalTouchedRef = useRef(false);
  const skipEstimateRef = useRef(false);
  const estimateRequestId = useRef(0);

  useEffect(() => {
    if (open) {
      setLabel("");
      setKcal("");
      setIsEstimating(false);
      setEstimateError(null);
      setEstimateHint(null);
      kcalTouchedRef.current = false;
      skipEstimateRef.current = false;
    }
  }, [open]);

  useEffect(() => {
    if (!open) return;

    if (skipEstimateRef.current) {
      skipEstimateRef.current = false;
      return;
    }

    const trimmed = label.trim();
    if (trimmed.length < 3 || kcalTouchedRef.current) {
      setIsEstimating(false);
      setEstimateError(null);
      setEstimateHint(null);
      return;
    }

    const timer = window.setTimeout(() => {
      const requestId = ++estimateRequestId.current;
      setIsEstimating(true);
      setEstimateError(null);
      setEstimateHint(null);

      estimateCalories(trimmed)
        .then((result) => {
          if (requestId !== estimateRequestId.current) return;
          if (!kcalTouchedRef.current) {
            setKcal(String(result.kcal));
            setEstimateHint(
              `推定: 約${result.assumed_weight_g}g（信頼度: ${confidenceLabel(result.confidence)}）`,
            );
          }
        })
        .catch((error: unknown) => {
          if (requestId !== estimateRequestId.current) return;
          setEstimateError(
            error instanceof Error
              ? error.message
              : "カロリー推定に失敗しました",
          );
        })
        .finally(() => {
          if (requestId !== estimateRequestId.current) return;
          setIsEstimating(false);
        });
    }, 1500);

    return () => window.clearTimeout(timer);
  }, [label, open]);

  const applySuggestion = (item: MealItemInput) => {
    skipEstimateRef.current = true;
    kcalTouchedRef.current = false;
    setEstimateError(null);
    setEstimateHint(null);
    setLabel(item.label);
    setKcal(item.kcal.replace("kcal", ""));
  };

  const handleSave = () => {
    const trimmedLabel = label.trim();
    const trimmedKcal = kcal.trim();
    if (!trimmedLabel || !trimmedKcal) return;
    onSave({
      label: trimmedLabel,
      kcal: trimmedKcal.endsWith("kcal") ? trimmedKcal : `${trimmedKcal}kcal`,
    });
  };

  return (
    <BottomSheet open={open} title={`${mealTitle}を記録`} onClose={onClose}>
      <label style={fieldLabelStyle}>食品名</label>
      <input
        type="text"
        placeholder="例：白米 150g"
        value={label}
        onChange={(e) => setLabel(e.target.value)}
        style={inputStyle}
      />

      <label style={{ ...fieldLabelStyle, marginTop: 14 }}>カロリー</label>
      <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
        <input
          type="number"
          placeholder="例：234"
          value={kcal}
          onChange={(e) => {
            kcalTouchedRef.current = true;
            setEstimateHint(null);
            setKcal(e.target.value);
          }}
          style={{ ...inputStyle, flex: 1, marginBottom: 0 }}
        />
        <span style={{ fontSize: 14, color: "#888", paddingBottom: 12 }}>
          kcal
        </span>
      </div>
      {isEstimating && (
        <div style={{ fontSize: 12, color: "#AAA", marginTop: 4 }}>
          カロリーを推定中...
        </div>
      )}
      {!isEstimating && estimateHint && !estimateError && (
        <div style={{ fontSize: 12, color: "#888", marginTop: 4 }}>
          {estimateHint}
        </div>
      )}
      {!isEstimating && estimateError && (
        <div style={{ fontSize: 12, color: "#C0392B", marginTop: 4 }}>
          {estimateError}
        </div>
      )}

      <div
        style={{
          fontSize: 13,
          fontWeight: 600,
          color: "#888",
          margin: "18px 0 10px",
        }}
      >
        よく使う食品
      </div>
      <div
        style={{ display: "flex", flexWrap: "wrap", gap: 8, marginBottom: 24 }}
      >
        {suggestions.map((item) => (
          <button
            key={item.label}
            type="button"
            onClick={() => applySuggestion(item)}
            style={{
              padding: "8px 12px",
              borderRadius: 999,
              border: "1px solid #F5E1D2",
              background: "#FFF5EB",
              fontSize: 13,
              color: "#8B5E3C",
              cursor: "pointer",
            }}
          >
            {item.label}
          </button>
        ))}
      </div>

      <div style={{ display: "flex", gap: 10 }}>
        <button type="button" onClick={onClose} style={secondaryBtnStyle}>
          キャンセル
        </button>
        <button
          type="button"
          onClick={handleSave}
          disabled={!label.trim() || !kcal.trim() || isEstimating}
          style={{
            ...primaryBtnStyle,
            opacity: label.trim() && kcal.trim() && !isEstimating ? 1 : 0.45,
            cursor:
              label.trim() && kcal.trim() && !isEstimating
                ? "pointer"
                : "not-allowed",
          }}
        >
          追加する
        </button>
      </div>
    </BottomSheet>
  );
}

function confidenceLabel(confidence: "high" | "medium" | "low"): string {
  switch (confidence) {
    case "high":
      return "高";
    case "medium":
      return "中";
    case "low":
      return "低";
  }
}

const fieldLabelStyle: CSSProperties = {
  display: "block",
  fontSize: 13,
  fontWeight: 600,
  color: "#666",
  marginBottom: 6,
};

const inputStyle: CSSProperties = {
  width: "100%",
  boxSizing: "border-box",
  padding: "12px 14px",
  borderRadius: 10,
  border: "1px solid #E8E8E8",
  fontSize: 15,
  color: "#111",
  marginBottom: 4,
  outline: "none",
};

const secondaryBtnStyle: CSSProperties = {
  flex: 1,
  padding: "14px 0",
  borderRadius: 12,
  border: "1px solid #E8E8E8",
  background: "#fff",
  fontSize: 15,
  fontWeight: 600,
  color: "#666",
  cursor: "pointer",
};

const primaryBtnStyle: CSSProperties = {
  flex: 1,
  padding: "14px 0",
  borderRadius: 12,
  border: "none",
  background: ORANGE,
  fontSize: 15,
  fontWeight: 700,
  color: "#fff",
};
