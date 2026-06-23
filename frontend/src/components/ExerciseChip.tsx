interface ExerciseChipProps {
  text: string
  onClick?: () => void
}

const CHIP_BG = '#EDF9F3'
const CHIP_BORDER = '#BFE6D0'
const CHIP_TEXT = '#2E7D5A'

export function ExerciseChip({ text, onClick }: ExerciseChipProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={!onClick}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        padding: '4px 10px',
        borderRadius: 999,
        border: `1px solid ${CHIP_BORDER}`,
        background: CHIP_BG,
        fontSize: 10,
        color: CHIP_TEXT,
        whiteSpace: 'nowrap',
        cursor: onClick ? 'pointer' : 'default',
        opacity: onClick ? 1 : 0.95,
      }}
    >
      {text}
    </button>
  )
}
