export type AppTab = 'chat' | 'record' | 'report'

export interface ChatMessage {
  id: string
  role: 'assistant' | 'user'
  text: string
  sentAt: string
}

export interface ChatReply {
  userMessage: ChatMessage
  assistantMessage: ChatMessage
}

export interface Meal {
  id: string
  name: string
  calories: number
}

export interface DailyRecord {
  date: string
  weight: {
    current: number
    diffFromPreviousDay: number
  }
  meals: Meal[]
  steps: {
    count: number
    burnedCalories: number
  }
  sleep: {
    durationMinutes: number
  }
  memo: string
  calorieGoal: number
}

export interface WeeklyPoint {
  label: string
  value: number
}

export interface WeeklyReport {
  rangeLabel: string
  weight: {
    points: WeeklyPoint[]
    weeklyAverage: number
    weeklyDiff: number
    targetDiff: number
  }
  calories: {
    points: WeeklyPoint[]
    average: number
    target: number
    achievementRate: number
  }
  steps: {
    points: WeeklyPoint[]
    average: number
    target: number
  }
}
