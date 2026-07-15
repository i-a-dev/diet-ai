import { AiEstimateIcon } from "./AiEstimateIcon.tsx";

interface ExerciseChipProps {
  text: string;
  isAiEstimate?: boolean;
  onClick?: () => void;
}

const CHIP_BG = "#EDF9F3";
const CHIP_BORDER = "#BFE6D0";
const CHIP_TEXT = "#2E7D5A";

export function ExerciseChip({
  text,
  isAiEstimate = false,
  onClick,
}: ExerciseChipProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={!onClick}
      style={{
        display: "inline-flex",
        alignItems: "center",
        gap: 4,
        padding: "4px 10px",
        borderRadius: 999,
        border: `1px solid ${CHIP_BORDER}`,
        background: CHIP_BG,
        fontSize: 10,
        color: CHIP_TEXT,
        whiteSpace: "nowrap",
        cursor: onClick ? "pointer" : "default",
        opacity: onClick ? 1 : 0.95,
      }}
    >
      {isAiEstimate && (
        <span
          aria-label="AI推定"
          title="AI推定"
          style={{
            display: "inline-flex",
            alignItems: "center",
            justifyContent: "center",
            flexShrink: 0,
            lineHeight: 0,
          }}
        >
          <AiEstimateIcon />
        </span>
      )}
      <span>{text}</span>
    </button>
  );
}
