import { useEffect, useRef, useState, type CSSProperties } from "react";
import {
  estimateExercisePreview,
  fetchExerciseHistory,
  type ExercisePreviewResponse,
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
  recordDate?: string;
  onClose: () => void;
  onOpenWeightRegister?: () => void;
  onSave: (input: ExerciseInput) => void | Promise<void>;
}

export function ExerciseRegisterSheet({
  open,
  isSaving = false,
  recordDate,
  onClose,
  onOpenWeightRegister,
  onSave,
}: ExerciseRegisterSheetProps) {
  const [name, setName] = useState("");
  const [amount, setAmount] = useState<number>(30);
  const [unit, setUnit] = useState<ExerciseUnit>("min");
  const [history, setHistory] = useState<ExerciseInput[]>([]);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const historyRequestTokenRef = useRef(0);
  const previewRequestTokenRef = useRef(0);
  const nameInputRef = useRef<HTMLInputElement | null>(null);
  const [isPreviewLoading, setIsPreviewLoading] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [preview, setPreview] = useState<ExercisePreviewResponse | null>(null);

  useEffect(() => {
    if (!open) return;
    setName("");
    setAmount(30);
    setUnit("min");
    setHistoryError(null);
    setIsPreviewLoading(false);
    setPreviewError(null);
    setPreview(null);
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
  const previewReady =
    preview !== null && !isPreviewLoading && previewError === null;

  // 変更: 入力変更時は前回の推定結果を破棄し、手動再計算を必須にする。
  useEffect(() => {
    if (!open) return;
    setPreview(null);
    setPreviewError(null);
    setIsPreviewLoading(false);
  }, [open, trimmedName, amount, unit]);

  const handleCalculatePreview = () => {
    if (!isValid || isPreviewLoading || isSaving) return;
    const token = Date.now();
    previewRequestTokenRef.current = token;
    setIsPreviewLoading(true);
    setPreviewError(null);
    setPreview(null);

    // 変更: ユーザーが「カロリーを計算する」を押した時だけ推定APIを呼ぶ。
    void (async () => {
      try {
        const nextPreview = await estimateExercisePreview(
          trimmedName,
          amount,
          unit,
          recordDate,
        );
        if (previewRequestTokenRef.current !== token) return;
        setPreview(nextPreview);
      } catch (error) {
        if (previewRequestTokenRef.current !== token) return;
        setPreview(null);
        setPreviewError(
          error instanceof Error ? error.message : "カロリー計算に失敗しました",
        );
      } finally {
        if (previewRequestTokenRef.current !== token) return;
        setIsPreviewLoading(false);
      }
    })();
  };

  const handleSave = () => {
    if (!previewReady || isSaving) return;
    // 変更: 保存時はプレビューで正規化された運動名を利用する。
    void onSave({ name: preview.preview.exercise, amount, unit });
  };

  return (
    <BottomSheet open={open} title="運動を記録" onClose={onClose}>
      <div style={sheetLayoutStyle}>
        <div>
          <label style={labelStyle}>運動名</label>
          <input
            ref={nameInputRef}
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

          <div style={previewAreaStyle}>
            {trimmedName.length === 0 ? (
              <div style={previewHintStyle}>
                運動名を入力すると消費カロリーを表示します
              </div>
            ) : previewError ? (
              <div style={previewErrorStyle}>{previewError}</div>
            ) : previewReady && preview.preview.confidence === "low" ? (
              <div style={lowPreviewCardStyle}>
                <div style={previewTopRowStyle}>
                  <span style={previewKcalTextStyle}>
                    {preview.preview.caloriesBurned} kcal
                  </span>
                  <span style={lowBadgeStyle}>低精度</span>
                </div>
                <div style={previewSubTextStyle}>
                  {preview.preview.exercise} / {preview.preview.minutes}分 /
                  METs {preview.preview.mets}
                </div>
                {preview.preview.note !== "" && (
                  <div style={previewNoteStyle}>
                    推定根拠: {preview.preview.note}
                  </div>
                )}
                <div style={lowWarningTextStyle}>
                  実際のカロリーと異なる場合があります
                </div>
              </div>
            ) : previewReady ? (
              <div style={previewCardStyle}>
                <div style={previewTopRowStyle}>
                  <span style={previewKcalTextStyle}>
                    {preview.preview.caloriesBurned} kcal
                  </span>
                  <span style={estimateBadgeStyle}>推定</span>
                </div>
                <div style={previewSubTextStyle}>
                  {preview.preview.mets} METs × {preview.weight.kg}kg ×{" "}
                  {preview.preview.minutes}h/60
                </div>
              </div>
            ) : null}

            {previewReady &&
              preview.weight.source === "reference" &&
              preview.weight.recordedOn && (
                <div style={weightInfoStyle}>
                  {formatShortDate(preview.weight.recordedOn)}の体重{" "}
                  {preview.weight.kg}kg をもとに計算しました
                </div>
              )}
            {previewReady && preview.weight.source === "default" && (
              <div style={weightWarningStyle}>
                体重が未登録のため60kgで計算しています{" "}
              </div>
            )}
          </div>

          {!previewReady && (
            <>
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
            </>
          )}
        </div>

        <div style={footerStyle}>
          {previewReady ? (
            <button
              type="button"
              disabled={isSaving}
              onClick={handleSave}
              style={{
                ...submitButtonStyle,
                background: "#2EAA72",
                opacity: isSaving ? 0.5 : 1,
                cursor: isSaving ? "not-allowed" : "pointer",
              }}
            >
              {isSaving ? "保存中..." : "登録する"}
            </button>
          ) : (
            <button
              type="button"
              disabled={!isValid || isPreviewLoading || isSaving}
              onClick={handleCalculatePreview}
              style={{
                ...submitButtonStyle,
                opacity: !isValid || isPreviewLoading || isSaving ? 0.5 : 1,
                cursor:
                  !isValid || isPreviewLoading || isSaving
                    ? "not-allowed"
                    : "pointer",
              }}
            >
              {isPreviewLoading ? "計算中..." : "カロリーを計算する"}
            </button>
          )}
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

function formatShortDate(date: string): string {
  const [year, month, day] = date.split("-");
  if (!year || !month || !day) return date;
  return `${Number(month)}/${Number(day)}`;
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

const previewAreaStyle: CSSProperties = {
  marginTop: 10,
  marginBottom: 14,
};

const previewStyle: CSSProperties = {
  fontSize: 12,
  color: "#6B7280",
};

const previewHintStyle: CSSProperties = {
  ...previewStyle,
};

const previewCardStyle: CSSProperties = {
  borderRadius: 12,
  border: "1px solid #BFE6D0",
  background: "#EDF9F3",
  padding: "10px 12px",
};

const lowPreviewCardStyle: CSSProperties = {
  borderRadius: 12,
  border: "1px solid #F7CFA3",
  background: "#FFF6EC",
  padding: "10px 12px",
};

const previewTopRowStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
};

const previewKcalTextStyle: CSSProperties = {
  color: "#2EAA72",
  fontSize: 22,
  fontWeight: 800,
};

const previewSubTextStyle: CSSProperties = {
  marginTop: 6,
  color: "#4B5563",
  fontSize: 12,
};

const estimateBadgeStyle: CSSProperties = {
  fontSize: 11,
  color: "#2E7D5A",
  background: "#D6F5E8",
  borderRadius: 999,
  padding: "2px 8px",
  fontWeight: 700,
};

const lowBadgeStyle: CSSProperties = {
  fontSize: 11,
  color: "#9A5515",
  background: "#FDE8D2",
  borderRadius: 999,
  padding: "2px 8px",
  fontWeight: 700,
};

const previewNoteStyle: CSSProperties = {
  marginTop: 6,
  fontSize: 12,
  color: "#7C5A3D",
};

const lowWarningTextStyle: CSSProperties = {
  marginTop: 6,
  fontSize: 12,
  color: "#B45309",
  fontWeight: 600,
};

const previewErrorStyle: CSSProperties = {
  fontSize: 12,
  color: "#C0392B",
  background: "#FFF1F0",
  border: "1px solid #FFD4D1",
  borderRadius: 10,
  padding: "8px 10px",
};

const weightInfoStyle: CSSProperties = {
  fontSize: 12,
  color: "#4B5563",
  marginTop: 6,
};

const weightWarningStyle: CSSProperties = {
  fontSize: 12,
  color: "#B45309",
  marginTop: 6,
};

const weightLinkButtonStyle: CSSProperties = {
  border: "none",
  background: "transparent",
  color: "#2EAA72",
  textDecoration: "underline",
  padding: 0,
  fontSize: 12,
  fontWeight: 700,
  cursor: "pointer",
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
