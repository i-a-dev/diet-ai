import { useEffect, useMemo, useState } from 'react'
import { PhoneMockFrame, phoneAppShellStyle } from './components/PhoneMockFrame.tsx'
import { SBar } from './components/SBar.tsx'
import { TabBar } from './components/TabBar.tsx'
import { ProfileSettingsSheet } from './components/ProfileSettingsSheet.tsx'
import { ChatScreen } from './components/screens/ChatScreen.tsx'
import { ForgotPasswordScreen } from './components/screens/ForgotPasswordScreen.tsx'
import { GraphScreen } from './components/screens/GraphScreen.tsx'
import { LoginScreen } from './components/screens/LoginScreen.tsx'
import { RecordScreen } from './components/screens/RecordScreen.tsx'
import { ResetPasswordScreen } from './components/screens/ResetPasswordScreen.tsx'
import { VerifyEmailScreen } from './components/screens/VerifyEmailScreen.tsx'
import { fetchUserProfile } from './api/client.ts'
import { useAuth } from './contexts/AuthContext.tsx'
import { useMediaQuery } from './hooks/useMediaQuery.ts'

type GuestView = 'login' | 'forgot-password'

function parseAuthRoute(pathname: string, search: string) {
  const params = new URLSearchParams(search)
  const token = params.get('token')?.trim() ?? ''

  if (pathname === '/auth/verify-email' && token) {
    return { type: 'verify-email' as const, token }
  }

  if (pathname === '/auth/reset-password' && token) {
    return { type: 'reset-password' as const, token }
  }

  return null
}

function LoadingScreen() {
  return (
    <PhoneMockFrame>
      <div
        style={{
          flex: 1,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          color: '#888',
          fontSize: 14,
        }}
      >
        読み込み中...
      </div>
    </PhoneMockFrame>
  )
}

function AppContent() {
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
        // プロフィール取得失敗時はオンボーディングをスキップ
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

  if (!profileChecked) {
    return <LoadingScreen />
  }

  return (
    <PhoneMockFrame>
      <div style={phoneAppShellStyle}>
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
      </div>
    </PhoneMockFrame>
  )
}

export default function App() {
  const { isAuthenticated, isLoading, user } = useAuth()
  const [guestView, setGuestView] = useState<GuestView>('login')

  const authRoute = useMemo(
    () => parseAuthRoute(window.location.pathname, window.location.search),
    [],
  )

  if (isLoading) {
    return <LoadingScreen />
  }

  if (authRoute?.type === 'verify-email') {
    return (
      <PhoneMockFrame>
        <div style={phoneAppShellStyle}>
          <VerifyEmailScreen token={authRoute.token} onDone={() => window.location.assign('/')} />
        </div>
      </PhoneMockFrame>
    )
  }

  if (authRoute?.type === 'reset-password') {
    return (
      <PhoneMockFrame>
        <div style={phoneAppShellStyle}>
          <ResetPasswordScreen
            token={authRoute.token}
            onDone={() => {
              setGuestView('login')
              window.location.assign('/')
            }}
          />
        </div>
      </PhoneMockFrame>
    )
  }

  if (!isAuthenticated || user === null) {
    return (
      <PhoneMockFrame>
        <div style={phoneAppShellStyle}>
          {guestView === 'forgot-password' ? (
            <ForgotPasswordScreen onBackToLogin={() => setGuestView('login')} />
          ) : (
            <LoginScreen onForgotPassword={() => setGuestView('forgot-password')} />
          )}
        </div>
      </PhoneMockFrame>
    )
  }

  return <AppContent key={user.id} />
}
