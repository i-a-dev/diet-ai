import type { WeeklyPoint, WeeklyReport } from '../../types/domain'

interface ReportScreenProps {
  report: WeeklyReport | null
  loading: boolean
}

function BarChart({ points, maxValue }: { points: WeeklyPoint[]; maxValue: number }) {
  return (
    <div className="bar-chart">
      {points.map((point) => {
        const heightRate = Math.max((point.value / maxValue) * 100, 6)

        return (
          <div key={point.label} className="bar-item">
            <div className="bar-track">
              <div className="bar-fill" style={{ height: `${heightRate}%` }} />
            </div>
            <span>{point.label}</span>
          </div>
        )
      })}
    </div>
  )
}

export function ReportScreen({ report, loading }: ReportScreenProps) {
  if (loading || !report) {
    return <p className="loading-text">レポートを読み込み中...</p>
  }

  const calorieMax = Math.max(...report.calories.points.map((point) => point.value))
  const stepMax = Math.max(...report.steps.points.map((point) => point.value))

  return (
    <div className="report-screen">
      <p className="date-label">{report.rangeLabel}</p>

      <section className="metric-card">
        <h2>体重</h2>
        <p className="sub-value">週の平均 {report.weight.weeklyAverage.toFixed(1)} kg</p>
        <p className="sub-value">前週比 {report.weight.weeklyDiff.toFixed(1)} kg</p>
        <p className="sub-value">目標まで {report.weight.targetDiff.toFixed(1)} kg</p>
      </section>

      <section className="metric-card">
        <h2>カロリー</h2>
        <p className="sub-value">
          平均 {report.calories.average} kcal / 目標 {report.calories.target} kcal
        </p>
        <p className="sub-value">達成率 {report.calories.achievementRate}%</p>
        <BarChart points={report.calories.points} maxValue={calorieMax} />
      </section>

      <section className="metric-card">
        <h2>歩数</h2>
        <p className="sub-value">
          平均 {report.steps.average.toLocaleString()} 歩 / 目標 {report.steps.target.toLocaleString()} 歩
        </p>
        <BarChart points={report.steps.points} maxValue={stepMax} />
      </section>
    </div>
  )
}
