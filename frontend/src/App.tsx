import { useState } from 'react'
import { SBar } from './components/SBar.tsx'
import { TabBar } from './components/TabBar.tsx'
import { ChatScreen } from './components/screens/ChatScreen.tsx'
import { GraphScreen } from './components/screens/GraphScreen.tsx'
import { RecordScreen } from './components/screens/RecordScreen.tsx'

export default function App() {
  const [tab, setTab] = useState<number>(0)
  const screens = [<ChatScreen key="chat" />, <RecordScreen key="record" />, <GraphScreen key="graph" />]

  return (
    <div
      style={{
        fontFamily: "-apple-system,BlinkMacSystemFont,'Hiragino Sans','Noto Sans JP',sans-serif",
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
        <SBar />
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>{screens[tab]}</div>
        <TabBar active={tab} onChange={setTab} />
      </div>
    </div>
  )
}
