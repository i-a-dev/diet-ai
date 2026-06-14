import type { DailyRecord } from '../../types/domain'

interface RecordScreenProps {
  record: DailyRecord | null
  loading: boolean
}

function toHourMinuteText(totalMinutes: number): string {
  const hour = Math.floor(totalMinutes / 60)
  const minute = totalMinutes % 60
  return `${hour}時間${minute}分`
}

export function RecordScreen({ record, loading }: RecordScreenProps) {
  if (loading || !record) {
    return <p className="loading-text">記録を読み込み中...</p>
  }

  const totalCalories = record.meals.reduce((sum, meal) => sum + meal.calories, 0)

  return (
    <div className="record-screen">
      <p className="date-label">{record.date}</p>

      <section className="metric-card">
        <h2>体重</h2>
        <p className="main-value">{record.weight.current.toFixed(1)} kg</p>
        <p className="sub-value">前日比 {record.weight.diffFromPreviousDay.toFixed(1)} kg</p>
      </section>

      <section className="metric-card">
        <h2>食事</h2>
        <p className="sub-value">
          カロリー {totalCalories} / {record.calorieGoal} kcal
        </p>
        <ul className="simple-list">
          {record.meals.map((meal) => (
            <li key={meal.id}>
              <span>{meal.name}</span>
              <strong>{meal.calories} kcal</strong>
            </li>
          ))}
        </ul>
      </section>

      <section className="metric-card">
        <h2>運動・歩数</h2>
        <p className="sub-value">{record.steps.count.toLocaleString()} 歩</p>
        <p className="sub-value">消費カロリー {record.steps.burnedCalories} kcal</p>
      </section>

      <section className="metric-card">
        <h2>睡眠</h2>
        <p className="sub-value">{toHourMinuteText(record.sleep.durationMinutes)}</p>
      </section>

      <section className="metric-card">
        <h2>メモ</h2>
        <p className="memo-text">{record.memo}</p>
      </section>
    </div>
  )
}
