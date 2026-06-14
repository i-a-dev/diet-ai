import type { ReactNode } from 'react'

interface BubbleUserProps {
  children: ReactNode
}

export function BubbleUser({ children }: BubbleUserProps) {
  return (
    <div
      style={{
        background: '#FFF0DC',
        borderRadius: 18,
        borderTopRightRadius: 4,
        padding: '10px 14px',
        fontSize: 13,
        lineHeight: 1.65,
        color: '#7A4010',
        maxWidth: 260,
      }}
    >
      {children}
    </div>
  )
}
