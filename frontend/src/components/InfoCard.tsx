import type { ReactNode } from 'react'

interface InfoCardProps {
  children: ReactNode
}

export function InfoCard({ children }: InfoCardProps) {
  return (
    <div
      style={{
        background: '#FFF0DC',
        borderRadius: 10,
        padding: '10px 12px',
        marginTop: 8,
        fontSize: 12,
        color: '#7A4010',
        lineHeight: 1.8,
      }}
    >
      {children}
    </div>
  )
}
