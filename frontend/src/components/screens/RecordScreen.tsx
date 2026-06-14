import { Calendar, ChevronLeft, ChevronRight, Cookie, Footprints, Moon, MoonStar, Pencil, StickyNote, Sun, Sunset, UtensilsCrossed, Weight } from 'lucide-react'
import { FoodRow } from '../FoodRow.tsx'
import { SecIcon } from '../SecIcon.tsx'
import { TopNav } from '../TopNav.tsx'
import { ORANGE } from '../../constants.ts'

export function RecordScreen() {
  const secStyle = { background: '#fff', margin: '10px 16px 0', borderRadius: 16, padding: '14px 16px' }
  const secHead = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }
  const secTitle = { display: 'flex', alignItems: 'center', gap: 8, fontSize: 15, fontWeight: 600, color: '#222' }

  return (
    <>
      <TopNav title="記録する" rightIcon={<Calendar size={22} color="#C0C0C0" />} />
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 20px 12px', borderBottom: '1px solid #F0F0F0', background: '#fff' }}>
        <ChevronLeft size={22} color="#C0C0C0" />
        <span style={{ fontSize: 15, fontWeight: 600, color: '#111' }}>今日 4/24（水）</span>
        <ChevronRight size={22} color="#C0C0C0" />
      </div>
      <div style={{ flex: 1, overflowY: 'auto', background: '#F7F7F7' }}>
        <div style={{ ...secStyle, marginTop: 12 }}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#FDE8C8" color={ORANGE}>
                <Weight size={16} />
              </SecIcon>
              体重
            </div>
            <Pencil size={18} color="#C0C0C0" />
          </div>
          <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between' }}>
            <div>
              <div>
                <span style={{ fontSize: 36, fontWeight: 700, color: '#111' }}>62.4</span>
                <span style={{ fontSize: 18, color: '#888' }}> kg</span>
              </div>
              <div style={{ fontSize: 13, color: ORANGE, marginTop: 5 }}>前日比 -0.2kg</div>
            </div>
            <svg width="110" height="44" viewBox="0 0 110 44">
              <polyline points="0,34 18,30 36,38 54,26 72,20 90,14 110,8" fill="none" stroke={ORANGE} strokeWidth="2.2" strokeLinejoin="round" strokeLinecap="round" />
              {[{ cx: 0, cy: 34 }, { cx: 18, cy: 30 }, { cx: 36, cy: 38 }, { cx: 54, cy: 26 }, { cx: 72, cy: 20 }, { cx: 90, cy: 14 }].map((point) => (
                <circle key={`${point.cx}-${point.cy}`} cx={point.cx} cy={point.cy} r="3" fill="#fff" stroke={ORANGE} strokeWidth="1.8" />
              ))}
              <circle cx="110" cy="8" r="4" fill={ORANGE} />
            </svg>
          </div>
        </div>

        <div style={secStyle}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#FDE8C8" color={ORANGE}>
                <UtensilsCrossed size={16} />
              </SecIcon>
              食事
            </div>
            <span style={{ fontSize: 13, color: ORANGE, fontWeight: 500 }}>カロリー 1,582 / 1,800kcal</span>
          </div>
          <FoodRow icon={<Sun size={18} />} name="朝ごはん" kcal="412 kcal" />
          <FoodRow icon={<Sunset size={18} />} name="昼ごはん" kcal="618 kcal" />
          <FoodRow icon={<Moon size={18} />} name="夜ごはん" kcal="552 kcal" />
          <FoodRow icon={<Cookie size={18} />} name="間食・おやつ" kcal="0 kcal" />
        </div>

        <div style={secStyle}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#D6F5E8" color="#2EAA72">
                <Footprints size={16} />
              </SecIcon>
              運動・歩数
            </div>
            <span style={{ color: ORANGE, fontSize: 26, lineHeight: 1, cursor: 'pointer' }}>+</span>
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between' }}>
            <div>
              <div style={{ fontSize: 12, color: '#AAA', marginBottom: 3 }}>歩数</div>
              <div>
                <span style={{ fontSize: 28, fontWeight: 700, color: '#111' }}>5,842</span>
                <span style={{ fontSize: 14, color: '#888' }}> 歩</span>
              </div>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontSize: 12, color: '#AAA', marginBottom: 3 }}>消費カロリー</div>
              <div>
                <span style={{ fontSize: 28, fontWeight: 700, color: '#111' }}>231</span>
                <span style={{ fontSize: 14, color: '#888' }}> kcal</span>
              </div>
            </div>
          </div>
        </div>

        <div style={secStyle}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#EAE6FD" color="#7F6FD4">
                <MoonStar size={16} />
              </SecIcon>
              睡眠
            </div>
            <span style={{ color: ORANGE, fontSize: 26, lineHeight: 1, cursor: 'pointer' }}>+</span>
          </div>
          <div style={{ fontSize: 12, color: '#AAA', marginBottom: 3 }}>睡眠時間</div>
          <div>
            <span style={{ fontSize: 28, fontWeight: 700, color: '#111' }}>6時間20分</span>
          </div>
        </div>

        <div style={{ ...secStyle, marginBottom: 10 }}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#E0EFFE" color="#3B82F6">
                <StickyNote size={16} />
              </SecIcon>
              メモ
            </div>
            <span style={{ color: ORANGE, fontSize: 26, lineHeight: 1, cursor: 'pointer' }}>+</span>
          </div>
          <div style={{ fontSize: 14, color: '#AAA' }}>今日はケーキを食べちゃった</div>
        </div>
      </div>
    </>
  )
}
