interface FoodChipProps {
  label: string
  kcal: string
}

const CHIP_BG = '#FFF5EB'
const CHIP_BORDER = '#F5E1D2'
const CHIP_TEXT = '#8B5E3C'

export function FoodChip({ label, kcal }: FoodChipProps) {
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
      <span>{label}</span>
      <span>{kcal}</span>
    </div>
  )
}
