const API_BASE = import.meta.env.VITE_API_BASE_URL ?? '/api'

async function request<T>(path: string, options?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE}${path}`, {
    headers: {
      'Content-Type': 'application/json',
      ...(options?.headers ?? {}),
    },
    ...options,
  })

  if (!response.ok) {
    const error = (await response.json().catch(() => null)) as { message?: string } | null
    throw new Error(error?.message ?? 'リクエストに失敗しました')
  }

  return response.json() as Promise<T>
}

export interface WeightSummary {
  current: number | null
  diffFromPreviousDay: number | null
  recordedOn: string
  dateLabel: string
}

export interface DailyRecordResponse {
  date: string
  recordedOn: string
  weight: WeightSummary
}

export interface SaveWeightResponse {
  weight: WeightSummary
}

export function fetchDailyRecord(date?: string) {
  const query = date ? `?date=${encodeURIComponent(date)}` : ''
  return request<DailyRecordResponse>(`/records/daily${query}`)
}

export function saveWeight(weight: number, date?: string) {
  return request<SaveWeightResponse>('/records/weight', {
    method: 'POST',
    body: JSON.stringify({ weight, date }),
  })
}
