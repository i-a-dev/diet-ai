import { useEffect, useState, type CSSProperties } from "react";
import { BottomSheet } from "./BottomSheet.tsx";

interface StepsRegisterSheetProps {
  open: boolean;
  initialSteps: number;
  isSaving?: boolean;
  onClose: () => void;
  onSave: (steps: number) => void | Promise<void>;
}

export function StepsRegisterSheet({
  open,
  initialSteps,
  isSaving = false,
  onClose,
  onSave,
}: StepsRegisterSheetProps) {
  const [steps, setSteps] = useState(initialSteps);

  useEffect(() => {
    if (open) {
      setSteps(initialSteps);
    }
  }, [open, initialSteps]);

  const canSave = steps >= 0 && steps <= 100000;
  return (
    <BottomSheet open={open} title="歩数を記録" onClose={onClose}>
      <div style={stepsRowStyle}>
        <div style={stepsInputFrameStyle}>
          <input
            type="number"
            min={0}
            max={100000}
            value={Number.isNaN(steps) ? "" : steps}
            onChange={(event) => {
              const next = Number(event.target.value);
              setSteps(
                Number.isNaN(next)
                  ? 0
                  : Math.max(0, Math.min(100000, Math.round(next))),
              );
            }}
            style={stepsInputStyle}
          />
        </div>
        <span style={unitStyle}>歩</span>
      </div>

      <button type="button" style={secondaryCardStyle} disabled>
        <div
          style={{
            display: "flex",
            flexDirection: "column",
            alignItems: "flex-start",
          }}
        >
          <span style={{ fontSize: 13, fontWeight: 600, color: "#555" }}>
            ヘルスケアと連携する
          </span>
          <span style={{ fontSize: 12, color: "#9CA3AF", marginTop: 2 }}>
            iPhoneのヘルスケアから取得
          </span>
        </div>
        <span style={{ color: "#A3A3A3", fontSize: 18 }}>&gt;</span>
      </button>

      <button
        type="button"
        disabled={!canSave || isSaving}
        onClick={() => void onSave(steps)}
        style={{
          ...primaryButtonStyle,
          opacity: !canSave || isSaving ? 0.5 : 1,
          cursor: !canSave || isSaving ? "not-allowed" : "pointer",
        }}
      >
        {isSaving ? "保存中..." : "登録する"}
      </button>
    </BottomSheet>
  );
}

const stepsInputFrameStyle: CSSProperties = {
  border: "1px solid #E5E7EB",
  borderRadius: 12,
  flex: 1,
  padding: "0px 14px",
};

const stepsRowStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  gap: 10,
  marginBottom: 14,
};

const stepsInputStyle: CSSProperties = {
  border: "none",
  outline: "none",
  textAlign: "center",
  fontSize: 40,
  fontWeight: 700,
  color: "#111",
  width: "100%",
  background: "transparent",
};

const unitStyle: CSSProperties = {
  fontSize: 20,
  color: "#666",
  fontWeight: 600,
};

const secondaryCardStyle: CSSProperties = {
  width: "100%",
  border: "1px solid #E9EEF0",
  borderRadius: 12,
  background: "#fff",
  padding: "12px 14px",
  marginBottom: 10,
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
};

const primaryButtonStyle: CSSProperties = {
  width: "100%",
  // marginTop: 10,
  border: "none",
  borderRadius: 12,
  background: "#2EAA72",
  color: "#fff",
  fontSize: 16,
  fontWeight: 700,
  padding: "12px 12px",
};
