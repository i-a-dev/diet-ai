import type { ReactNode } from 'react'

interface SecIconProps {
  children: ReactNode
  bg: string
  color: string
  size?: number
}

export function SecIcon({ children, bg, color, size = 28 }: SecIconProps) {
  return (
    <div
      style={{
        width: size,
        height: size,
        borderRadius: 8,
        background: bg,
        color,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0,
      }}
    >
      {children}
    </div>
  )
}
