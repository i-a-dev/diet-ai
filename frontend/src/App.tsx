import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
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
import splashLogo from './assets/splash-logo.png'

type GuestView = 'login' | 'forgot-password'

const SPLASH_FADE_MS = 500
const SPLASH_MIN_MS = 700

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

/** 読み込み完了後もフェードアウトが終わるまでスプラッシュを残す */
function useSplashGate(isBusy: boolean) {
  const [blocking, setBlocking] = useState(isBusy)
  const [fadingOut, setFadingOut] = useState(false)
  const shownAtRef = useRef<number | null>(isBusy ? Date.now() : null)
  const finishedRef = useRef(false)

  const finish = useCallback(() => {
    if (finishedRef.current) return
    finishedRef.current = true
    setBlocking(false)
    setFadingOut(false)
    shownAtRef.current = null
  }, [])

  useEffect(() => {
    if (isBusy) {
      finishedRef.current = false
      if (!blocking) {
        setBlocking(true)
        setFadingOut(false)
        shownAtRef.current = Date.now()
      }
      return
    }

    if (!blocking || fadingOut) return

    const elapsed = shownAtRef.current ? Date.now() - shownAtRef.current : SPLASH_MIN_MS
    const wait = Math.max(0, SPLASH_MIN_MS - elapsed)
    const timer = window.setTimeout(() => setFadingOut(true), wait)
    return () => window.clearTimeout(timer)
  }, [isBusy, blocking, fadingOut])

  // transitionend が飛ばない場合（HMR・opacity 変化なし等）の保険
  useEffect(() => {
    if (!fadingOut) return
    const timer = window.setTimeout(finish, SPLASH_FADE_MS + 100)
    return () => window.clearTimeout(timer)
  }, [fadingOut, finish])

  return { showSplash: blocking, fadingOut, onFadedOut: finish }
}

function LoadingScreen({
  fadingOut = false,
  onFadedOut,
}: {
  fadingOut?: boolean
  onFadedOut?: () => void
}) {
  const [opaque, setOpaque] = useState(false)

  useEffect(() => {
    // HMR などでフェードアウト中に再マウントされた場合、フェードインさせない
    if (fadingOut) {
      setOpaque(false)
      return
    }

    const id = requestAnimationFrame(() => {
      requestAnimationFrame(() => setOpaque(true))
    })
    return () => cancelAnimationFrame(id)
  }, [fadingOut])

  return (
    <PhoneMockFrame>
      <div
        style={{
          flex: 1,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          background: '#fff',
          padding: '0 40px',
        }}
      >
        <img
          src={splashLogo}
          alt="Movi"
          style={{
            width: '100%',
            maxWidth: 280,
            height: 'auto',
            display: 'block',
            opacity: opaque ? 1 : 0,
            transition: `opacity ${SPLASH_FADE_MS}ms ease`,
          }}
          onTransitionEnd={(event) => {
            if (event.propertyName !== 'opacity') return
            if (fadingOut && !opaque) {
              onFadedOut?.()
            }
          }}
        />
      </div>
    </PhoneMockFrame>
  )
}

function AppContent({
  onboardingOpen,
  onOnboardingClose,
}: {
  onboardingOpen: boolean
  onOnboardingClose: () => void
}) {
  const [tab, setTab] = useState<number>(1)
  const isDesktopPreview = useMediaQuery('(min-width: 768px)')
  const screens = [<ChatScreen key="chat" />, <RecordScreen key="record" />, <GraphScreen key="graph" />]

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
          onClose={onOnboardingClose}
          onSaved={onOnboardingClose}
        />
      </div>
    </PhoneMockFrame>
  )
}

export default function App() {
  const { isAuthenticated, isLoading, user } = useAuth()
  const [guestView, setGuestView] = useState<GuestView>('login')
  const [bootstrapReady, setBootstrapReady] = useState(false)
  const [onboardingOpen, setOnboardingOpen] = useState(false)

  // 認証復元 +（ログイン時）プロフィール確認をまとめて待つ → スプラッシュは1回だけ
  useEffect(() => {
    if (isLoading) {
      setBootstrapReady(false)
      return
    }

    if (!isAuthenticated || user === null) {
      setBootstrapReady(true)
      setOnboardingOpen(false)
      return
    }

    let cancelled = false
    setBootstrapReady(false)

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
          setBootstrapReady(true)
        }
      })

    return () => {
      cancelled = true
    }
  }, [isLoading, isAuthenticated, user])

  const splash = useSplashGate(isLoading || !bootstrapReady)

  const authRoute = useMemo(
    () => parseAuthRoute(window.location.pathname, window.location.search),
    [],
  )

  if (splash.showSplash) {
    return (
      <LoadingScreen fadingOut={splash.fadingOut} onFadedOut={splash.onFadedOut} />
    )
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
            <ForgotPasswordScreen
              key="forgot-password"
              onBackToLogin={() => setGuestView('login')}
            />
          ) : (
            <LoginScreen
              key="login"
              onForgotPassword={() => setGuestView('forgot-password')}
            />
          )}
        </div>
      </PhoneMockFrame>
    )
  }

  return (
    <AppContent
      key={user.id}
      onboardingOpen={onboardingOpen}
      onOnboardingClose={() => setOnboardingOpen(false)}
    />
  )
}
