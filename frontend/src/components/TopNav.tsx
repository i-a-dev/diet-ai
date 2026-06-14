import type { ReactNode } from 'react'
import { Menu } from 'lucide-react'

interface TopNavProps {
  title: string
  rightIcon: ReactNode
}

export function TopNav({ title, rightIcon }: TopNavProps) {
  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: '4px 20px 12px',
        background: '#fff',
        borderBottom: '1px solid #F0F0F0',
      }}
    >
      <Menu size={22} color="#C0C0C0" />
      <span style={{ fontSize: 17, fontWeight: 600, color: '#111' }}>{title}</span>
      {rightIcon}
    </div>
  )
}
