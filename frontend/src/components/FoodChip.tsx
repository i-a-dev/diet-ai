interface FoodChipProps {
  label: string
  kcal: string
  onClick?: () => void
}

const CHIP_BG = '#FFF5EB'
const CHIP_BORDER = '#F5E1D2'
const CHIP_TEXT = '#8B5E3C'

export function FoodChip({ label, kcal, onClick }: FoodChipProps) {
  const content = (
    <>
      <span>{label}</span>
      <span>{kcal}</span>
    </>
  )

  if (!onClick) {
    return (
      <div
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 8,
          padding: '4px 8px',
          borderRadius: 999,
          border: `1px solid ${CHIP_BORDER}`,
          background: CHIP_BG,
          fontSize: 10,
          color: CHIP_TEXT,
          whiteSpace: 'nowrap',
        }}
      >
        {content}
      </div>
    )
  }

  return (
    <button
      type="button"
      onClick={onClick}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 8,
        padding: '4px 8px',
        borderRadius: 999,
        border: `1px solid ${CHIP_BORDER}`,
        background: CHIP_BG,
        fontSize: 10,
        color: CHIP_TEXT,
        whiteSpace: 'nowrap',
        cursor: 'pointer',
      }}
    >
      {content}
    </button>
  )
}
