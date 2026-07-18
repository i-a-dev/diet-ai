import { useEffect, useRef, useState, type CSSProperties } from "react";
import {
  estimateExercisePreview,
  fetchExerciseHistory,
  type ExercisePreviewResponse,
  type ExerciseHistoryEntry,
} from "../api/client.ts";
import { BottomSheet } from "./BottomSheet.tsx";
import {
  ExercisePreviewCard,
  type ExercisePreviewCardModel,
} from "./ExercisePreviewCard.tsx";

export type ExerciseUnit = "min" | "rep";

export interface ExerciseInput {
  name: string;
  amount: number;
  unit: ExerciseUnit;
  /** 手入力で上書きした消費カロリー。未指定時はサーバー側で再計算 */
  burnedCalories?: number;
}

interface ExerciseRegisterSheetProps {
  open: boolean;
  isSaving?: boolean;
  recordDate?: string;
  onClose: () => void;
  onSave: (input: ExerciseInput) => void | Promise<void>;
}

export function ExerciseRegisterSheet({
  open,
  isSaving = false,
  recordDate,
  onClose,
  onSave,
}: ExerciseRegisterSheetProps) {
  const [name, setName] = useState("");
  const [amount, setAmount] = useState<number | null>(null);
  const [unit, setUnit] = useState<ExerciseUnit>("min");
  const [history, setHistory] = useState<ExerciseHistoryEntry[]>([]);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const [selectedHistoryEntry, setSelectedHistoryEntry] =
    useState<ExerciseHistoryEntry | null>(null);
  const historyRequestTokenRef = useRef(0);
  const previewRequestTokenRef = useRef(0);
  const nameInputRef = useRef<HTMLInputElement | null>(null);
  const [isPreviewLoading, setIsPreviewLoading] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [preview, setPreview] = useState<ExercisePreviewResponse | null>(null);
  const [isManualEdit, setIsManualEdit] = useState(false);
  const [manualKcal, setManualKcal] = useState("");

  const clearPreview = () => {
    previewRequestTokenRef.current = Date.now();
    setPreview(null);
    setPreviewError(null);
    setIsPreviewLoading(false);
    setSelectedHistoryEntry(null);
    setIsManualEdit(false);
    setManualKcal("");
  };

  useEffect(() => {
    if (!open) return;
    setName("");
    setAmount(null);
    setUnit("min");
    setHistoryError(null);
    clearPreview();
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
  const isValid =
    trimmedName.length > 0 && amount !== null && amount > 0 && amount <= 10000;
  const previewReady =
    preview !== null && !isPreviewLoading && previewError === null;
  const historyReady =
    selectedHistoryEntry !== null &&
    selectedHistoryEntry.name.trim() === trimmedName &&
    selectedHistoryEntry.amount === amount &&
    selectedHistoryEntry.unit === unit;
  const activePreview: ExercisePreviewCardModel | null = previewReady
    ? toPreviewViewModel(preview)
    : historyReady
      ? historyToPreviewViewModel(selectedHistoryEntry)
      : null;
  const parsedManualKcal = Number(manualKcal);
  const isManualKcalValid =
    manualKcal.trim() !== "" &&
    Number.isFinite(parsedManualKcal) &&
    parsedManualKcal >= 1 &&
    parsedManualKcal <= 5000;
  const canSave = activePreview !== null;
  const canSubmit =
    canSave && (!isManualEdit || isManualKcalValid);

  const requestPreview = (
    exerciseName: string,
    exerciseAmount: number,
    exerciseUnit: ExerciseUnit,
  ) => {
    const normalizedName = exerciseName.trim();
    if (
      normalizedName.length === 0 ||
      exerciseAmount <= 0 ||
      exerciseAmount > 10000
    ) {
      return;
    }

    const token = Date.now();
    previewRequestTokenRef.current = token;
    setIsPreviewLoading(true);
    setPreviewError(null);
    setPreview(null);

    void (async () => {
      try {
        const nextPreview = await estimateExercisePreview(
          normalizedName,
          exerciseAmount,
          exerciseUnit,
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

  const handleCalculatePreview = () => {
    if (!isValid || amount === null || isPreviewLoading || isSaving) return;
    requestPreview(trimmedName, amount, unit);
  };

  const handleHistorySelect = (item: ExerciseHistoryEntry) => {
    previewRequestTokenRef.current = Date.now();
    setPreview(null);
    setPreviewError(null);
    setIsPreviewLoading(false);
    setIsManualEdit(false);
    setManualKcal("");
    setName(item.name);
    setAmount(item.amount);
    setUnit(item.unit);
    setSelectedHistoryEntry(item);
  };

  const handleStartManualEdit = () => {
    if (!activePreview || isSaving) return;
    setIsManualEdit(true);
    setManualKcal(String(activePreview.caloriesBurned));
  };

  const handleCancelManualEdit = () => {
    setIsManualEdit(false);
    setManualKcal("");
  };

  const handleSave = () => {
    if (!canSubmit || amount === null || isSaving) return;
    void onSave({
      name: trimmedName,
      amount,
      unit,
      ...(isManualEdit && isManualKcalValid
        ? { burnedCalories: Math.round(parsedManualKcal) }
        : {}),
    });
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
            onChange={(event) => {
              setName(event.target.value);
              clearPreview();
            }}
            placeholder="例：スクワット、ランニング"
            style={inputStyle}
          />

          <label style={{ ...labelStyle, marginTop: 10 }}>時間・回数</label>
          <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
            <input
              type="number"
              min={1}
              max={10000}
              value={amount ?? ""}
              placeholder="例：30"
              onChange={(event) => {
                const raw = event.target.value;
                if (raw === "") {
                  setAmount(null);
                  clearPreview();
                  return;
                }
                const next = Number(raw);
                setAmount(
                  Number.isNaN(next)
                    ? null
                    : Math.max(1, Math.min(10000, Math.round(next))),
                );
                clearPreview();
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
                onClick={() => {
                  setUnit("min");
                  clearPreview();
                }}
                style={{
                  ...switchBtnStyle,
                  ...(unit === "min" ? activeSwitchBtnStyle : undefined),
                }}
              >
                分
              </button>
              <button
                type="button"
                onClick={() => {
                  setUnit("rep");
                  clearPreview();
                }}
                style={{
                  ...switchBtnStyle,
                  ...(unit === "rep" ? activeSwitchBtnStyle : undefined),
                }}
              >
                回
              </button>
            </div>
          </div>

          {!isManualEdit && (
            <div style={previewAreaStyle}>
              {trimmedName.length === 0 ? (
                <div style={previewHintStyle}>
                  運動名を入力すると消費カロリーを表示します
                </div>
              ) : isPreviewLoading ? (
                <div style={previewHintStyle}>カロリーを計算しています...</div>
              ) : previewError ? (
                <div style={previewErrorStyle}>{previewError}</div>
              ) : activePreview ? (
                <ExercisePreviewCard
                  model={activePreview}
                  isFromHistory={historyReady}
                />
              ) : null}
            </div>
          )}

          {canSave && isManualEdit && (
            <div style={{ marginTop: 10, marginBottom: 14 }}>
              <label style={labelStyle}>カロリー（手入力）</label>
              <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                <input
                  type="number"
                  min={1}
                  max={5000}
                  placeholder="例：150"
                  value={manualKcal}
                  onChange={(event) => setManualKcal(event.target.value)}
                  style={{
                    ...inputStyle,
                    marginBottom: 0,
                    textAlign: "center",
                    fontWeight: 700,
                  }}
                />
                <span style={{ fontSize: 13, color: "#888", flexShrink: 0 }}>
                  kcal
                </span>
              </div>
            </div>
          )}

          {!canSave && (
            <>
              <div style={hintTitleStyle}>運動の履歴</div>
              <div style={historyScrollAreaStyle}>
                <div style={chipWrapStyle}>
                  {history.map((item) => (
                    <button
                      key={`${item.name}-${item.amount}-${item.unit}-${item.burnedCalories}`}
                      type="button"
                      onClick={() => handleHistorySelect(item)}
                      style={historyChipStyle}
                    >
                      <span style={historyChipLabelStyle}>
                        {item.name} {item.amount}
                        {item.unit === "min" ? "分" : "回"}
                      </span>
                      <span style={historyChipKcalStyle}>
                        {item.burnedCalories}kcal
                      </span>
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
          {canSave ? (
            <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
              {isManualEdit ? (
                <button
                  type="button"
                  disabled={isSaving}
                  onClick={handleCancelManualEdit}
                  style={{
                    ...secondaryButtonStyle,
                    opacity: isSaving ? 0.5 : 1,
                    cursor: isSaving ? "not-allowed" : "pointer",
                  }}
                >
                  計算結果に戻す
                </button>
              ) : (
                <button
                  type="button"
                  disabled={isSaving}
                  onClick={handleStartManualEdit}
                  style={{
                    ...secondaryButtonStyle,
                    opacity: isSaving ? 0.5 : 1,
                    cursor: isSaving ? "not-allowed" : "pointer",
                  }}
                >
                  手入力で修正する
                </button>
              )}
              <button
                type="button"
                disabled={!canSubmit || isSaving}
                onClick={handleSave}
                style={{
                  ...submitButtonStyle,
                  background: "#2EAA72",
                  opacity: !canSubmit || isSaving ? 0.5 : 1,
                  cursor: !canSubmit || isSaving ? "not-allowed" : "pointer",
                }}
              >
                {isSaving ? "保存中..." : "登録する"}
              </button>
            </div>
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

function toPreviewViewModel(
  preview: ExercisePreviewResponse,
): ExercisePreviewCardModel {
  return {
    caloriesBurned: preview.preview.caloriesBurned,
    confidence: preview.preview.confidence,
    source: preview.preview.source,
    exercise: preview.preview.exercise,
    estimatedExercise: preview.preview.estimatedExercise,
    minutes: preview.preview.minutes,
    mets: preview.preview.mets,
    note: preview.preview.note,
    weightKg: preview.weight.kg,
    weightSource: preview.weight.source,
    weightRecordedOn: preview.weight.recordedOn,
  };
}

function historyToPreviewViewModel(
  entry: ExerciseHistoryEntry,
): ExercisePreviewCardModel {
  const note = entry.note ?? "";
  return {
    caloriesBurned: entry.burnedCalories,
    confidence: entry.confidence,
    source: entry.source,
    exercise: entry.name,
    estimatedExercise: parseEstimatedExerciseFromNote(note),
    minutes: entry.minutes,
    mets: entry.mets,
    note,
    weightKg: entry.weightKg,
    weightSource: entry.weightSource,
    weightRecordedOn:
      entry.weightSource === "reference" ? entry.recordedOn : null,
  };
}

function parseEstimatedExerciseFromNote(note: string): string {
  const match = note.match(/AI推定: 「(.+?)」相当で計算/);
  return match?.[1] ?? "";
}

function toUniqueExerciseHistory(
  history: ExerciseHistoryEntry[],
): ExerciseHistoryEntry[] {
  const seen = new Set<string>();
  const unique: ExerciseHistoryEntry[] = [];

  for (const entry of history) {
    const key = `${entry.name.trim().toLowerCase()}|${entry.amount}|${entry.unit}`;
    if (entry.name.trim() === "" || seen.has(key)) continue;
    seen.add(key);
    unique.push(entry);
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

const previewErrorStyle: CSSProperties = {
  fontSize: 12,
  color: "#C0392B",
  background: "#FFF1F0",
  border: "1px solid #FFD4D1",
  borderRadius: 10,
  padding: "8px 10px",
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
  display: "inline-flex",
  alignItems: "center",
  gap: 8,
  padding: "4px 12px",
  borderRadius: 999,
  border: "1px solid #BFE6D0",
  background: "#EDF9F3",
  fontSize: 13,
  color: "#2E7D5A",
  cursor: "pointer",
  maxWidth: "100%",
};

const historyChipLabelStyle: CSSProperties = {
  fontWeight: 500,
  lineHeight: 1.3,
  overflow: "hidden",
  textOverflow: "ellipsis",
  whiteSpace: "nowrap",
};

const historyChipKcalStyle: CSSProperties = {
  flexShrink: 0,
  fontSize: 12,
  fontWeight: 500,
  color: "#15803D",
  lineHeight: 1.2,
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

const secondaryButtonStyle: CSSProperties = {
  width: "100%",
  border: "1px solid #E8E8E8",
  borderRadius: 10,
  background: "#fff",
  color: "#666",
  fontSize: 14,
  fontWeight: 700,
  padding: "12px 0",
};
