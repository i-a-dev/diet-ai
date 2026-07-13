import type { ReactNode } from 'react'

interface BubbleCoachProps {
  children: ReactNode
}

export function BubbleCoach({ children }: BubbleCoachProps) {
  return (
    <div
      style={{
        background: '#fff',
        border: '1px solid #E8E8E8',
        borderRadius: 18,
        borderTopLeftRadius: 4,
        padding: '10px 14px',
        fontSize: 13,
        lineHeight: 1.65,
        color: '#222',
        minWidth: 0,
        maxWidth: '100%',
      }}
    >
      {children}
    </div>
  )
}
