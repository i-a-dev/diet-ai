import type { ChatMessage, ChatReply, DailyRecord, WeeklyReport } from '../types/domain'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    headers: {
      'Content-Type': 'application/json',
      ...(init?.headers ?? {}),
    },
    ...init,
  })

  if (!response.ok) {
    throw new Error(`API error: ${response.status}`)
  }

  return (await response.json()) as T
}

export async function fetchChatMessages(): Promise<ChatMessage[]> {
  const data = await request<{ messages: ChatMessage[] }>('/chat/messages')
  return data.messages
}

export async function sendChatMessage(text: string): Promise<ChatReply> {
  return request<ChatReply>('/chat/messages', {
    method: 'POST',
    body: JSON.stringify({ text }),
  })
}

export async function fetchDailyRecord(date?: string): Promise<DailyRecord> {
  const query = date ? `?date=${encodeURIComponent(date)}` : ''
  return request<DailyRecord>(`/records/daily${query}`)
}

export async function fetchWeeklyReport(startDate?: string): Promise<WeeklyReport> {
  const query = startDate ? `?startDate=${encodeURIComponent(startDate)}` : ''
  return request<WeeklyReport>(`/reports/weekly${query}`)
}
