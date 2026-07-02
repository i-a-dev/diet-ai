import { useState } from 'react'
import { Menu } from 'lucide-react'
import { ProfileSettingsSheet } from './ProfileSettingsSheet.tsx'
import { SideMenu } from './SideMenu.tsx'
import { useAuth } from '../contexts/AuthContext.tsx'

interface TopNavProps {
  title: string
  onProfileUpdated?: () => void
}

export function TopNav({ title, onProfileUpdated }: TopNavProps) {
  const [menuOpen, setMenuOpen] = useState(false)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const { user, logout } = useAuth()

  const openProfileSettings = () => {
    setMenuOpen(false)
    setSettingsOpen(true)
  }

  return (
    <>
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          minHeight: 44,
          padding: '0 20px',
          background: '#fff',
          borderBottom: '1px solid #F0F0F0',
          flexShrink: 0,
        }}
      >
        <button
          type="button"
          aria-label="メニューを開く"
          onClick={() => setMenuOpen(true)}
          style={{
            width: 22,
            height: 22,
            border: 'none',
            background: 'transparent',
            padding: 0,
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Menu size={22} color="#111" strokeWidth={2} />
        </button>
        <span
          style={{
            fontSize: 17,
            fontWeight: 600,
            color: '#111',
            lineHeight: '22px',
          }}
        >
          {title}
        </span>
        <span style={{ width: 22 }} />
      </div>

      <SideMenu
        open={menuOpen}
        onClose={() => setMenuOpen(false)}
        onOpenProfileSettings={openProfileSettings}
        onLogout={() => void logout()}
        userEmail={user?.email}
      />
      <ProfileSettingsSheet
        open={settingsOpen}
        onClose={() => setSettingsOpen(false)}
        onSaved={onProfileUpdated}
      />
    </>
  )
}
