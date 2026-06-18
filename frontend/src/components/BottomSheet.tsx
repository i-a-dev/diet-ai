import type { ReactNode } from 'react'
import { X } from 'lucide-react'

interface BottomSheetProps {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
}

export function BottomSheet({ open, title, onClose, children }: BottomSheetProps) {
  if (!open) return null

  return (
    <div
      style={{
        position: 'absolute',
        inset: 0,
        zIndex: 20,
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'flex-end',
      }}
    >
      <button
        type="button"
        aria-label="閉じる"
        onClick={onClose}
        style={{
          flex: 1,
          border: 'none',
          background: 'rgba(0,0,0,0.35)',
          cursor: 'pointer',
          padding: 0,
        }}
      />
      <div
        style={{
          background: '#fff',
          borderRadius: '16px 16px 0 0',
          padding: '16px 16px 24px',
          maxHeight: '85%',
          overflowY: 'auto',
        }}
      >
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            marginBottom: 20,
          }}
        >
          <span style={{ fontSize: 17, fontWeight: 700, color: '#111' }}>{title}</span>
          <button
            type="button"
            onClick={onClose}
            aria-label="閉じる"
            style={{
              border: 'none',
              background: 'transparent',
              padding: 4,
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
            }}
          >
            <X size={22} color="#AAA" />
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}
