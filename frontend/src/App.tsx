import { useEffect, useState } from 'react'
import { SBar } from './components/SBar.tsx'
import { TabBar } from './components/TabBar.tsx'
import { ProfileSettingsSheet } from './components/ProfileSettingsSheet.tsx'
import { ChatScreen } from './components/screens/ChatScreen.tsx'
import { GraphScreen } from './components/screens/GraphScreen.tsx'
import { RecordScreen } from './components/screens/RecordScreen.tsx'
import { fetchUserProfile } from './api/client.ts'
import { useMediaQuery } from './hooks/useMediaQuery.ts'

const fontFamily = "-apple-system,BlinkMacSystemFont,'Hiragino Sans','Noto Sans JP',sans-serif"

export default function App() {
  const [tab, setTab] = useState<number>(0)
  const [onboardingOpen, setOnboardingOpen] = useState(false)
  const [profileChecked, setProfileChecked] = useState(false)
  const isDesktopPreview = useMediaQuery('(min-width: 768px)')
  const screens = [<ChatScreen key="chat" />, <RecordScreen key="record" />, <GraphScreen key="graph" />]

  useEffect(() => {
    let cancelled = false

    fetchUserProfile()
      .then((response) => {
        if (!cancelled && !response.profile.isComplete) {
          setOnboardingOpen(true)
        }
      })
      .catch(() => {
        // API 未接続時はオンボーディングをスキップしてアプリを表示
      })
      .finally(() => {
        if (!cancelled) {
          setProfileChecked(true)
        }
      })

    return () => {
      cancelled = true
    }
  }, [])

  const appShell = (
    <>
      {isDesktopPreview && <SBar />}
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden', minHeight: 0 }}>
        {screens[tab]}
      </div>
      <TabBar active={tab} onChange={setTab} />
      <ProfileSettingsSheet
        open={onboardingOpen}
        mode="onboarding"
        onClose={() => setOnboardingOpen(false)}
        onSaved={() => setOnboardingOpen(false)}
      />
    </>
  )

  if (!profileChecked) {
    return (
      <div
        style={{
          fontFamily,
          background: '#fff',
          height: '100dvh',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          color: '#888',
          fontSize: 14,
        }}
      >
        読み込み中...
      </div>
    )
  }

  if (isDesktopPreview) {
    return (
      <div
        style={{
          fontFamily,
          background: '#F2F2F7',
          minHeight: '100vh',
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          padding: '40px 20px',
        }}
      >
        <div style={{ fontSize: 12, color: '#999', letterSpacing: '0.05em', marginBottom: 16 }}>
          ダイエットアプリ - UIモックアップ
        </div>

        <div
          style={{
            width: 375,
            background: '#fff',
            borderRadius: 50,
            border: '8px solid #1a1a1a',
            overflow: 'hidden',
            display: 'flex',
            flexDirection: 'column',
            boxShadow: '0 24px 64px rgba(0,0,0,0.20)',
            height: 800,
          }}
        >
          {appShell}
        </div>
      </div>
    )
  }

  return (
    <div
      style={{
        fontFamily,
        background: '#fff',
        height: '100dvh',
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden',
      }}
    >
      {appShell}
    </div>
  )
}
