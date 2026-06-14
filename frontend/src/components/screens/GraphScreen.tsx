import { Calendar, ChevronLeft, ChevronRight, Flame, Footprints, Weight } from 'lucide-react'
import { SecIcon } from '../SecIcon.tsx'
import { TopNav } from '../TopNav.tsx'
import { GREEN, ORANGE } from '../../constants.ts'

export function GraphScreen() {
  const days = ['4/18', '4/19', '4/20', '4/21', '4/22', '4/23', '4/24']
  const kcalH = [44, 60, 38, 52, 36, 66, 54]
  const stepH = [48, 64, 34, 56, 42, 68, 52]
  const xsBar = [16, 59, 102, 145, 188, 231, 274]
  const xsLbl = [29, 72, 115, 158, 201, 244, 287]
  const xsLine = [44, 87, 130, 173, 216, 259, 302]
  const yLine = [38, 48, 62, 42, 34, 24, 16]

  return (
    <>
      <TopNav title="記録を見る" rightIcon={<Calendar size={22} color="#C0C0C0" />} />
      <div style={{ flex: 1, overflowY: 'auto', background: '#F7F7F7' }}>
        <div style={{ display: 'flex', background: '#F0F0F0', borderRadius: 10, padding: 3, margin: '14px 16px 10px' }}>
          {['週', '月', '3ヶ月'].map((text, index) => (
            <div
              key={text}
              style={{
                flex: 1,
                padding: '7px 0',
                fontSize: 13,
                fontWeight: index === 0 ? 700 : 500,
                textAlign: 'center',
                color: index === 0 ? '#fff' : '#888',
                borderRadius: 8,
                background: index === 0 ? ORANGE : 'transparent',
                cursor: 'pointer',
              }}
            >
              {text}
            </div>
          ))}
        </div>

        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '4px 20px 10px' }}>
          <ChevronLeft size={20} color="#C0C0C0" />
          <span style={{ fontSize: 13, color: '#666' }}>4/18（木）〜 4/24（水）</span>
          <ChevronRight size={20} color="#C0C0C0" />
        </div>

        <div style={{ background: '#fff', margin: '0 16px 12px', borderRadius: 16, padding: '14px 16px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
            <SecIcon bg="#FDE8C8" color={ORANGE} size={26}>
              <Weight size={14} />
            </SecIcon>
            <span style={{ fontSize: 15, fontWeight: 600, color: '#222' }}>体重</span>
          </div>
          <svg width="100%" height="100" viewBox="0 0 310 100" preserveAspectRatio="none">
            {[15, 45, 75].map((y) => (
              <line key={y} x1="20" y1={y} x2="310" y2={y} stroke="#F0F0F0" strokeWidth="1" />
            ))}
            {[
              ['64', 15],
              ['63', 45],
              ['62', 75],
            ].map(([label, y]) => (
              <text key={label} x="0" y={Number(y) + 3} fontSize="8" fill="#C0C0C0" fontFamily="sans-serif">
                {label}
              </text>
            ))}
            <text x="0" y="96" fontSize="8" fill="#C0C0C0" fontFamily="sans-serif">
              (kg)
            </text>
            <polyline points={xsLine.map((x, i) => `${x},${yLine[i]}`).join(' ')} fill="none" stroke={ORANGE} strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
            {xsLine.map((x, index) => (
              <circle key={x} cx={x} cy={yLine[index]} r="3.5" fill="#fff" stroke={ORANGE} strokeWidth="2" />
            ))}
            <rect x="278" y="2" width="32" height="15" rx="4" fill={ORANGE} />
            <text x="294" y="13" fontSize="9" fill="#fff" textAnchor="middle" fontWeight="700" fontFamily="sans-serif">
              62.4
            </text>
            {days.map((day, index) => (
              <text key={day} x={xsLine[index]} y="96" fontSize="8" fill="#C0C0C0" textAnchor="middle" fontFamily="sans-serif">
                {day}
              </text>
            ))}
          </svg>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 10, paddingTop: 10, borderTop: '1px solid #F5F5F5' }}>
            {[
              ['62.7 kg', '週の平均'],
              ['-0.8 kg', '前週比'],
              ['-5.4 kg', '目標まで'],
            ].map(([value, label], index) => (
              <div key={label} style={{ textAlign: 'center', flex: 1 }}>
                <div style={{ fontSize: 15, fontWeight: 700, color: index > 0 ? ORANGE : '#111' }}>{value}</div>
                <div style={{ fontSize: 11, color: '#AAA', marginTop: 3 }}>{label}</div>
              </div>
            ))}
          </div>
        </div>

        <div style={{ background: '#fff', margin: '0 16px 12px', borderRadius: 16, padding: '14px 16px' }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <SecIcon bg="#FDE8E8" color="#E85B2B" size={26}>
                <Flame size={14} />
              </SecIcon>
              <span style={{ fontSize: 15, fontWeight: 600, color: '#222' }}>カロリー</span>
            </div>
            <span style={{ fontSize: 12, color: '#888' }}>平均 1,684kcal</span>
          </div>
          <svg width="100%" height="90" viewBox="0 0 310 90" preserveAspectRatio="none">
            {[20, 50].map((y) => (
              <line key={y} x1="0" y1={y} x2="310" y2={y} stroke="#F5F5F5" strokeWidth="1" />
            ))}
            {[
              ['3,000', 18],
              ['1,000', 48],
              ['0', 76],
            ].map(([label, y]) => (
              <text key={label} x="2" y={Number(y)} fontSize="7" fill="#DDD" fontFamily="sans-serif">
                {label}
              </text>
            ))}
            {kcalH.map((height, index) => (
              <rect key={days[index]} x={xsBar[index]} y={82 - height} width="26" height={height} rx="4" fill={GREEN} />
            ))}
            {days.map((day, index) => (
              <text key={day} x={xsLbl[index]} y="88" fontSize="8" fill="#C0C0C0" textAnchor="middle" fontFamily="sans-serif">
                {day}
              </text>
            ))}
          </svg>
          <div style={{ marginTop: 10 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 5 }}>
              <span style={{ fontSize: 12, color: '#888' }}>目標 1,800kcal</span>
              <span style={{ fontSize: 12, color: '#888' }}>達成率 94%</span>
            </div>
            <div style={{ height: 8, background: '#F0F0F0', borderRadius: 4 }}>
              <div style={{ height: 8, background: ORANGE, borderRadius: 4, width: '94%' }} />
            </div>
          </div>
        </div>

        <div style={{ background: '#fff', margin: '0 16px 14px', borderRadius: 16, padding: '14px 16px' }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <SecIcon bg="#D6F5E8" color="#2EAA72" size={26}>
                <Footprints size={14} />
              </SecIcon>
              <span style={{ fontSize: 15, fontWeight: 600, color: '#222' }}>歩数</span>
            </div>
            <span style={{ fontSize: 12, color: '#888' }}>平均 6,215歩</span>
          </div>
          <svg width="100%" height="90" viewBox="0 0 310 90" preserveAspectRatio="none">
            {[20, 50].map((y) => (
              <line key={y} x1="0" y1={y} x2="310" y2={y} stroke="#F5F5F5" strokeWidth="1" />
            ))}
            {[
              ['10,000', 18],
              ['5,000', 48],
              ['0', 76],
            ].map(([label, y]) => (
              <text key={label} x="2" y={Number(y)} fontSize="7" fill="#DDD" fontFamily="sans-serif">
                {label}
              </text>
            ))}
            {stepH.map((height, index) => (
              <rect key={days[index]} x={xsBar[index]} y={82 - height} width="26" height={height} rx="4" fill={GREEN} />
            ))}
            {days.map((day, index) => (
              <text key={day} x={xsLbl[index]} y="88" fontSize="8" fill="#C0C0C0" textAnchor="middle" fontFamily="sans-serif">
                {day}
              </text>
            ))}
          </svg>
        </div>
      </div>
    </>
  )
}
