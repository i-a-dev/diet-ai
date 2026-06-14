import type { ReactNode } from 'react'
import { ORANGE } from '../constants.ts'

interface FoodRowProps {
  icon: ReactNode
  name: string
  kcal: string
}

export function FoodRow({ icon, name, kcal }: FoodRowProps) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 0', borderBottom: '1px solid #F5F5F5' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        <div
          style={{
            width: 36,
            height: 36,
            borderRadius: 10,
            background: '#FFF0DC',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: ORANGE,
          }}
        >
          {icon}
        </div>
        <span style={{ fontSize: 14, color: '#222' }}>{name}</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
        <span style={{ fontSize: 14, color: '#888' }}>{kcal}</span>
        <span style={{ color: ORANGE, fontSize: 26, lineHeight: 1, cursor: 'pointer' }}>+</span>
      </div>
    </div>
  )
}
