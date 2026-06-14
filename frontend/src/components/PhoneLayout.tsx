import type { ReactNode } from 'react'
import type { AppTab } from '../types/domain'

interface PhoneLayoutProps {
  title: string
  activeTab: AppTab
  onChangeTab: (tab: AppTab) => void
  children: ReactNode
}

const TAB_ITEMS: Array<{ key: AppTab; label: string; icon: string }> = [
  { key: 'chat', label: '相談する', icon: '💬' },
  { key: 'record', label: '記録する', icon: '✏️' },
  { key: 'report', label: '記録を見る', icon: '📊' },
]

export function PhoneLayout({ title, activeTab, onChangeTab, children }: PhoneLayoutProps) {
  return (
    <main className="phone-shell">
      <header className="top-header">
        <button type="button" className="icon-button" aria-label="menu">
          ☰
        </button>
        <h1>{title}</h1>
        <button type="button" className="icon-button" aria-label="calendar">
          📅
        </button>
      </header>

      <section className="screen-content">{children}</section>

      <nav className="bottom-nav" aria-label="アプリのタブ">
        {TAB_ITEMS.map((tab) => (
          <button
            key={tab.key}
            type="button"
            className={`nav-item ${activeTab === tab.key ? 'active' : ''}`}
            onClick={() => onChangeTab(tab.key)}
          >
            <span className="nav-icon">{tab.icon}</span>
            <span>{tab.label}</span>
          </button>
        ))}
      </nav>
    </main>
  )
}
