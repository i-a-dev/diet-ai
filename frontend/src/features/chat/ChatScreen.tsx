import { FormEvent, useMemo, useState } from 'react'
import type { ChatMessage } from '../../types/domain'

interface ChatScreenProps {
  messages: ChatMessage[]
  sending: boolean
  onSendMessage: (text: string) => Promise<void>
}

function formatTime(sentAt: string): string {
  const date = new Date(sentAt)
  return new Intl.DateTimeFormat('ja-JP', { hour: '2-digit', minute: '2-digit' }).format(date)
}

export function ChatScreen({ messages, sending, onSendMessage }: ChatScreenProps) {
  const [input, setInput] = useState('')

  const sortedMessages = useMemo(
    () => [...messages].sort((a, b) => new Date(a.sentAt).getTime() - new Date(b.sentAt).getTime()),
    [messages],
  )

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const text = input.trim()

    if (!text || sending) {
      return
    }

    await onSendMessage(text)
    setInput('')
  }

  return (
    <div className="chat-screen">
      <div className="coach-card">
        <div className="coach-avatar">👩🏻‍⚕️</div>
        <div>
          <p className="coach-title">まいさん専用のAIコーチです</p>
          <p className="coach-description">いつでも気軽に相談してください</p>
        </div>
      </div>

      <div className="chat-list">
        {sortedMessages.map((message) => (
          <article key={message.id} className={`chat-bubble ${message.role}`}>
            <p>{message.text}</p>
            <time>{formatTime(message.sentAt)}</time>
          </article>
        ))}
      </div>

      <form className="chat-form" onSubmit={handleSubmit}>
        <input
          value={input}
          onChange={(event) => setInput(event.target.value)}
          placeholder="メッセージを入力..."
          disabled={sending}
          aria-label="メッセージ入力"
        />
        <button type="submit" disabled={sending}>
          送信
        </button>
      </form>
    </div>
  )
}
