import type { ReactNode } from 'react'
import { SecIcon } from './SecIcon.tsx'
import { ORANGE } from '../constants.ts'

const ICON_SIZE = 24
const COL_GAP = 8
const GREEN = '#2EAA72'
const GREEN_BG = '#D6F5E8'
const ACTIVITY_KCAL = '#AAA'

interface ActivitySubSectionProps {
  icon: ReactNode
  title: string
  totalKcal: string
  children: ReactNode
  isLast?: boolean
}

export function ActivitySubSection({
  icon,
  title,
  totalKcal,
  children,
  isLast = false,
}: ActivitySubSectionProps) {
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
        <SecIcon bg={GREEN_BG} color={GREEN} size={ICON_SIZE}>
          {icon}
        </SecIcon>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 14, fontWeight: 600, color: '#333' }}>{title}</span>
          <span style={{ fontSize: 13, color: ACTIVITY_KCAL }}>
            <span style={{ fontWeight: 600 }}>{totalKcal}</span> kcal
          </span>
        </div>
        <span style={{ color: ORANGE, fontSize: 26, lineHeight: 1, cursor: 'pointer' }}>+</span>

        <div style={{ gridColumn: '2 / -1' }}>{children}</div>
      </div>
    </div>
  )
}
