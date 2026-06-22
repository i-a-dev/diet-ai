import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
} from "react";
import {
  fetchExerciseHistory,
  type ExerciseHistoryEntry,
} from "../api/client.ts";
import { BottomSheet } from "./BottomSheet.tsx";

export type ExerciseUnit = "min" | "rep";

export interface ExerciseInput {
  name: string;
  amount: number;
  unit: ExerciseUnit;
}

interface ExerciseRegisterSheetProps {
  open: boolean;
  isSaving?: boolean;
  onClose: () => void;
  onSave: (input: ExerciseInput) => void | Promise<void>;
}

function calcPreviewCalories(
  name: string,
  amount: number,
  unit: ExerciseUnit,
): number {
  if (amount <= 0) return 0;
  if (unit === "min") {
    if (name.includes("ウォーキング")) return Math.round(amount * 3);
    if (name.includes("ランニング")) return Math.round(amount * 10);
    if (name.includes("ストレッチ")) return Math.round(amount * 2);
    return Math.round(amount * 4);
  }
  if (name.includes("スクワット")) return Math.round(amount * 2);
  if (name.includes("腹筋")) return Math.round(amount * 1.5);
  return Math.round(amount);
}

export function ExerciseRegisterSheet({
  open,
  isSaving = false,
  onClose,
  onSave,
}: ExerciseRegisterSheetProps) {
  const [name, setName] = useState("");
  const [amount, setAmount] = useState<number>(30);
  const [unit, setUnit] = useState<ExerciseUnit>("min");
  const [history, setHistory] = useState<ExerciseInput[]>([]);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const historyRequestTokenRef = useRef(0);

  useEffect(() => {
    if (!open) return;
    setName("");
    setAmount(30);
    setUnit("min");
    setHistoryError(null);
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const token = Date.now();
    historyRequestTokenRef.current = token;

    void (async () => {
      try {
        const response = await fetchExerciseHistory({ limit: 60 });
        if (historyRequestTokenRef.current !== token) return;
        setHistory(toUniqueExerciseHistory(response.history));
      } catch {
        if (historyRequestTokenRef.current !== token) return;
        setHistory([]);
        setHistoryError("履歴の取得に失敗しました");
      }
    })();
  }, [open]);

  const trimmedName = name.trim();
  const isValid = trimmedName.length > 0 && amount > 0 && amount <= 10000;
  const previewCalories = useMemo(
    () => calcPreviewCalories(trimmedName, amount, unit),
    [trimmedName, amount, unit],
  );

  return (
    <BottomSheet open={open} title="運動を記録" onClose={onClose}>
      <div style={sheetLayoutStyle}>
        <div>
          <label style={labelStyle}>運動名</label>
          <input
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="例：ウォーキング、スクワット、ランニング"
            style={inputStyle}
          />

          <label style={{ ...labelStyle, marginTop: 10 }}>時間・回数</label>
          <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
            <input
              type="number"
              min={1}
              max={10000}
              value={Number.isNaN(amount) ? "" : amount}
              onChange={(event) => {
                const next = Number(event.target.value);
                setAmount(
                  Number.isNaN(next)
                    ? 1
                    : Math.max(1, Math.min(10000, Math.round(next))),
                );
              }}
              style={{
                ...inputStyle,
                marginBottom: 0,
                textAlign: "center",
                fontWeight: 700,
              }}
            />
            <div style={switchWrapStyle}>
              <button
                type="button"
                onClick={() => setUnit("min")}
                style={{
                  ...switchBtnStyle,
                  ...(unit === "min" ? activeSwitchBtnStyle : undefined),
                }}
              >
                分
              </button>
              <button
                type="button"
                onClick={() => setUnit("rep")}
                style={{
                  ...switchBtnStyle,
                  ...(unit === "rep" ? activeSwitchBtnStyle : undefined),
                }}
              >
                回
              </button>
            </div>
          </div>

          <div style={hintTitleStyle}>運動の履歴</div>
          <div style={historyScrollAreaStyle}>
            <div style={chipWrapStyle}>
              {history.map((item) => (
                <button
                  key={`${item.name}-${item.amount}-${item.unit}`}
                  type="button"
                  onClick={() => {
                    setName(item.name);
                    setAmount(item.amount);
                    setUnit(item.unit);
                  }}
                  style={historyChipStyle}
                >
                  {item.name} {item.amount}
                  {item.unit === "min" ? "分" : "回"}
                </button>
              ))}
              {history.length === 0 && (
                <span style={emptyHistoryStyle}>
                  {historyError ?? "履歴がありません"}
                </span>
              )}
            </div>
          </div>
        </div>

        <div style={footerStyle}>
          <button
            type="button"
            disabled={!isValid || isSaving}
            onClick={() => void onSave({ name: trimmedName, amount, unit })}
            style={{
              ...submitButtonStyle,
              opacity: !isValid || isSaving ? 0.5 : 1,
              cursor: !isValid || isSaving ? "not-allowed" : "pointer",
            }}
          >
            {isSaving ? "保存中..." : "登録する"}
          </button>
        </div>
      </div>
    </BottomSheet>
  );
}

function toUniqueExerciseHistory(
  history: ExerciseHistoryEntry[],
): ExerciseInput[] {
  const seen = new Set<string>();
  const unique: ExerciseInput[] = [];

  for (const entry of history) {
    const key = `${entry.name.trim().toLowerCase()}|${entry.amount}|${entry.unit}`;
    if (entry.name.trim() === "" || seen.has(key)) continue;
    seen.add(key);
    unique.push({
      name: entry.name,
      amount: entry.amount,
      unit: entry.unit,
    });
    if (unique.length >= 50) break;
  }

  return unique;
}

const sheetLayoutStyle: CSSProperties = {
  display: "flex",
  flexDirection: "column",
  gap: 10,
};

const labelStyle: CSSProperties = {
  fontSize: 14,
  color: "#666",
  fontWeight: 600,
  marginBottom: 6,
};

const inputStyle: CSSProperties = {
  width: "100%",
  boxSizing: "border-box",
  padding: "12px 14px",
  borderRadius: 10,
  border: "1px solid #E8E8E8",
  fontSize: 16,
  color: "#111",
  marginBottom: 4,
  outline: "none",
};

const previewStyle: CSSProperties = {
  fontSize: 12,
  color: "#6B7280",
  marginTop: 6,
  marginBottom: 14,
};

const hintTitleStyle: CSSProperties = {
  fontSize: 13,
  color: "#666",
  fontWeight: 700,
  marginBottom: 8,
};

const switchWrapStyle: CSSProperties = {
  display: "flex",
  borderRadius: 10,
  border: "1px solid #E5E7EB",
  overflow: "hidden",
};

const switchBtnStyle: CSSProperties = {
  width: 56,
  height: 44,
  border: "none",
  background: "#fff",
  color: "#666",
  fontSize: 15,
  fontWeight: 700,
};

const activeSwitchBtnStyle: CSSProperties = {
  background: "#2EAA72",
  color: "#fff",
};

const historyScrollAreaStyle: CSSProperties = {
  maxHeight: 180,
  overflowY: "auto",
  border: "1px solid #F1F5F9",
  borderRadius: 10,
  padding: "10px 8px",
  marginBottom: 4,
  background: "#fff",
};

const chipWrapStyle: CSSProperties = {
  display: "flex",
  flexWrap: "wrap",
  gap: 8,
};

const historyChipStyle: CSSProperties = {
  padding: "3px 12px",
  borderRadius: 999,
  border: "1px solid #BFE6D0",
  background: "#EDF9F3",
  fontSize: 13,
  color: "#2E7D5A",
  cursor: "pointer",
};

const emptyHistoryStyle: CSSProperties = {
  fontSize: 12,
  color: "#9CA3AF",
};

const footerStyle: CSSProperties = {
  position: "sticky",
  bottom: 0,
  background: "#fff",
  paddingTop: 4,
};

const submitButtonStyle: CSSProperties = {
  width: "100%",
  border: "none",
  borderRadius: 10,
  background: "#2EAA72",
  color: "#fff",
  fontSize: 14,
  fontWeight: 700,
  padding: "12px 0",
};
