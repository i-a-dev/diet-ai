import { useCallback, useEffect, useMemo, useState } from 'react'
import './App.css'
import { fetchChatMessages, fetchDailyRecord, fetchWeeklyReport, sendChatMessage } from './api/client'
import { PhoneLayout } from './components/PhoneLayout'
import { ChatScreen } from './features/chat/ChatScreen'
import { RecordScreen } from './features/record/RecordScreen'
import { ReportScreen } from './features/report/ReportScreen'
import type { AppTab, ChatMessage, DailyRecord, WeeklyReport } from './types/domain'

function App() {
  const [activeTab, setActiveTab] = useState<AppTab>('record')
  const [chatMessages, setChatMessages] = useState<ChatMessage[]>([])
  const [dailyRecord, setDailyRecord] = useState<DailyRecord | null>(null)
  const [weeklyReport, setWeeklyReport] = useState<WeeklyReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [sending, setSending] = useState(false)

  const fetchInitialData = useCallback(async () => {
    setLoading(true)
    try {
      const [messages, record, report] = await Promise.all([
        fetchChatMessages(),
        fetchDailyRecord(),
        fetchWeeklyReport(),
      ])

      setChatMessages(messages)
      setDailyRecord(record)
      setWeeklyReport(report)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void fetchInitialData()
  }, [fetchInitialData])

  const handleSendMessage = useCallback(async (text: string) => {
    setSending(true)
    try {
      const reply = await sendChatMessage(text)
      setChatMessages((previous) => [...previous, reply.userMessage, reply.assistantMessage])
    } finally {
      setSending(false)
    }
  }, [])

  const screenTitle = useMemo(() => {
    if (activeTab === 'chat') {
      return 'AIコーチと相談'
    }

    if (activeTab === 'report') {
      return '記録を見る'
    }

    return '記録する'
  }, [activeTab])

  const screenContent = useMemo(() => {
    if (activeTab === 'chat') {
      return <ChatScreen messages={chatMessages} sending={sending} onSendMessage={handleSendMessage} />
    }

    if (activeTab === 'report') {
      return <ReportScreen report={weeklyReport} loading={loading} />
    }

    return <RecordScreen record={dailyRecord} loading={loading} />
  }, [activeTab, chatMessages, sending, handleSendMessage, weeklyReport, loading, dailyRecord])

  return (
    <div className="app-root">
      <PhoneLayout title={screenTitle} activeTab={activeTab} onChangeTab={setActiveTab}>
        {screenContent}
      </PhoneLayout>
    </div>
  )
}

export default App
