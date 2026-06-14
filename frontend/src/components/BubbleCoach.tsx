import type { ReactNode } from 'react'

interface BubbleCoachProps {
  children: ReactNode
}

export function BubbleCoach({ children }: BubbleCoachProps) {
  return (
    <div
      style={{
        background: '#FFF7EE',
        border: '1px solid #F0DEC8',
        borderRadius: 18,
        borderTopLeftRadius: 4,
        padding: '10px 14px',
        fontSize: 13,
        lineHeight: 1.65,
        color: '#222',
      }}
    >
      {children}
    </div>
  )
}
