import { Settings, X } from 'lucide-react'

interface SideMenuProps {
  open: boolean
  onClose: () => void
  onOpenProfileSettings: () => void
}

export function SideMenu({ open, onClose, onOpenProfileSettings }: SideMenuProps) {
  if (!open) {
    return null
  }

  return (
    <div
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 40,
        display: 'flex',
      }}
    >
      <div
        style={{
          width: 280,
          maxWidth: '82vw',
          background: '#fff',
          display: 'flex',
          flexDirection: 'column',
          boxShadow: '4px 0 24px rgba(0,0,0,0.12)',
        }}
      >
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '14px 16px',
            borderBottom: '1px solid #F0F0F0',
          }}
        >
          <span style={{ fontSize: 17, fontWeight: 700, color: '#111' }}>メニュー</span>
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

        <div style={{ padding: '8px 0' }}>
          <div
            style={{
              padding: '8px 16px 6px',
              fontSize: 11,
              fontWeight: 600,
              color: '#999',
              letterSpacing: '0.04em',
            }}
          >
            設定
          </div>
          <button
            type="button"
            onClick={onOpenProfileSettings}
            style={{
              width: '100%',
              display: 'flex',
              alignItems: 'center',
              gap: 12,
              padding: '14px 16px',
              border: 'none',
              background: 'transparent',
              cursor: 'pointer',
              textAlign: 'left',
            }}
          >
            <Settings size={20} color="#666" />
            <span style={{ fontSize: 15, color: '#111', fontWeight: 500 }}>プロフィール</span>
          </button>
        </div>
      </div>
      <button
        type="button"
        aria-label="メニューを閉じる"
        onClick={onClose}
        style={{
          flex: 1,
          border: 'none',
          background: 'rgba(0,0,0,0.35)',
          cursor: 'pointer',
          padding: 0,
        }}
      />
    </div>
  )
}
