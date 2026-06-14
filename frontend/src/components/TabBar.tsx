import { MessageCircle, PenLine, TrendingUp } from 'lucide-react'
import { ORANGE } from '../constants.ts'

interface TabBarProps {
  active: number
  onChange: (index: number) => void
}

export function TabBar({ active, onChange }: TabBarProps) {
  const tabs = [
    { label: '相談する', icon: <MessageCircle size={24} /> },
    { label: '記録する', icon: <PenLine size={24} /> },
    { label: '記録を見る', icon: <TrendingUp size={24} /> },
  ]

  return (
    <div style={{ display: 'flex', borderTop: '1px solid #F0F0F0', background: '#fff', paddingBottom: 8 }}>
      {tabs.map((tab, index) => (
        <div
          key={tab.label}
          onClick={() => onChange(index)}
          style={{
            flex: 1,
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            paddingTop: 10,
            paddingBottom: 4,
            gap: 3,
            fontSize: 11,
            color: index === active ? ORANGE : '#B0B0B0',
            cursor: 'pointer',
            userSelect: 'none',
          }}
        >
          <div style={{ color: index === active ? ORANGE : '#B0B0B0' }}>{tab.icon}</div>
          {tab.label}
        </div>
      ))}
    </div>
  )
}
