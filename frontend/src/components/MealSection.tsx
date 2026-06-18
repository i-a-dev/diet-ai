import type { ReactNode } from 'react'
import { FoodChip } from './FoodChip.tsx'
import { SecIcon } from './SecIcon.tsx'
import { ORANGE } from '../constants.ts'

const ICON_SIZE = 24
const COL_GAP = 8
const MEAL_KCAL = '#AAA'

interface MealItem {
  label: string
  kcal: string
}

interface MealSectionProps {
  icon: ReactNode
  title: string
  totalKcal: string
  items: MealItem[]
  isLast?: boolean
  onAdd?: () => void
}

export function MealSection({ icon, title, totalKcal, items, isLast = false, onAdd }: MealSectionProps) {
  return (
    <div
      style={{
        padding: '12px 0',
        borderBottom: isLast ? 'none' : '1px solid #F0F0F0',
      }}
    >
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: `${ICON_SIZE}px 1fr auto`,
          columnGap: COL_GAP,
          rowGap: 8,
          alignItems: 'center',
        }}
      >
        <SecIcon bg="#FFF0DC" color={ORANGE} size={ICON_SIZE}>
          {icon}
        </SecIcon>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 14, fontWeight: 600, color: '#333' }}>{title}</span>
          <span style={{ fontSize: 13, color: MEAL_KCAL }}>
            <span style={{ fontWeight: 600 }}>{totalKcal}</span> kcal
          </span>
        </div>
        <button
          type="button"
          onClick={onAdd}
          aria-label={`${title}を追加`}
          style={{
            border: 'none',
            background: 'transparent',
            color: ORANGE,
            fontSize: 26,
            lineHeight: 1,
            cursor: 'pointer',
            padding: 0,
          }}
        >
          +
        </button>

        {items.length > 0 && (
          <div
            style={{
              gridColumn: '2 / -1',
              display: 'flex',
              flexWrap: 'wrap',
              gap: 6,
              alignItems: 'center',
            }}
          >
            {items.map((item) => (
              <FoodChip key={item.label} label={item.label} kcal={item.kcal} />
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
