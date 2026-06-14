import { Bike, Camera, Footprints, History, Moon, Sun, Sunset, Users } from 'lucide-react'
import { BubbleCoach } from '../BubbleCoach.tsx'
import { BubbleUser } from '../BubbleUser.tsx'
import { CoachAvatar } from '../CoachAvatar.tsx'
import { InfoCard } from '../InfoCard.tsx'
import { TopNav } from '../TopNav.tsx'
import { ORANGE } from '../../constants.ts'

export function ChatScreen() {
  return (
    <>
      <TopNav title="AIコーチと相談" rightIcon={<History size={22} color="#C0C0C0" />} />
      <div style={{ flex: 1, padding: '14px 16px', display: 'flex', flexDirection: 'column', gap: 14, overflowY: 'auto', background: '#fff' }}>
        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
          <CoachAvatar />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, maxWidth: 260 }}>
            <span style={{ fontSize: 11, color: ORANGE, fontWeight: 600 }}>AIコーチ</span>
            <BubbleCoach>
              〇〇さん専属のAIコーチです！
              <br />
              いつでも気軽に相談してくださいね！
            </BubbleCoach>
          </div>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 3 }}>
          <BubbleUser>
            ケーキ食べちゃった...
            <br />
            目標達成するにはどうしたらいい？
          </BubbleUser>
          <span style={{ fontSize: 11, color: '#C0C0C0' }}>既読 10:30</span>
        </div>

        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
          <CoachAvatar />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, maxWidth: 260 }}>
            <span style={{ fontSize: 11, color: ORANGE, fontWeight: 600 }}>AIコーチ 10:31</span>
            <BubbleCoach>
              全然大丈夫ですよ！
              <br />
              まず安心してください。
              <br />
              ケーキ1個くらいでダイエットは失敗しません◎
            </BubbleCoach>
          </div>
        </div>

        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
          <CoachAvatar />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, maxWidth: 260 }}>
            <span style={{ fontSize: 11, color: ORANGE, fontWeight: 600 }}>AIコーチ 10:32</span>
            <BubbleCoach>
              今回のケーキはだいたい350kcalくらいだと思います。消費するなら、だいたいこんな感じです
              <InfoCard>
                <div style={{ fontSize: 12, fontWeight: 700, color: '#C06010', marginBottom: 6 }}>約350kcalを消費するには</div>
                {[
                  { icon: <Footprints size={14} />, label: 'ウォーキング（普通のペース）', time: '約70〜90分' },
                  { icon: <Users size={14} />, label: 'ベビーカー散歩', time: '約80分' },
                  { icon: <Bike size={14} />, label: '自転車（ゆっくり）', time: '約40分' },
                  { icon: <Footprints size={14} />, label: 'ジョギング', time: '約35分' },
                ].map((row) => (
                  <div
                    key={row.label}
                    style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 12, color: '#7A4010', marginBottom: 3 }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 5, color: '#C06010' }}>
                      {row.icon}
                      <span style={{ color: '#7A4010' }}>{row.label}</span>
                    </div>
                    <span style={{ color: '#C06010', fontWeight: 600, whiteSpace: 'nowrap', marginLeft: 6 }}>{row.time}</span>
                  </div>
                ))}
              </InfoCard>
            </BubbleCoach>
          </div>
        </div>

        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
          <CoachAvatar />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, maxWidth: 260 }}>
            <span style={{ fontSize: 11, color: ORANGE, fontWeight: 600 }}>AIコーチ 10:33</span>
            <BubbleCoach>でも正直、消費しようとしなくて大丈夫です。私なら明日少し調整しますね！</BubbleCoach>
          </div>
        </div>

        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
          <CoachAvatar />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, maxWidth: 260 }}>
            <span style={{ fontSize: 11, color: ORANGE, fontWeight: 600 }}>AIコーチ 10:34</span>
            <BubbleCoach>
              明日のおすすめメニュー、考えました
              <InfoCard>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 6 }}>
                  {[
                    { icon: <Sun size={12} />, title: '朝ごはん', items: ['ゆで卵2個', 'ヨーグルト', 'ブラックコーヒー'] },
                    { icon: <Sunset size={12} />, title: 'お昼ごはん', items: ['鶏むね塩こうじ焼き', 'サラダ', 'ご飯（普通盛り）', '味噌汁'] },
                    { icon: <Moon size={12} />, title: '夜ごはん', items: ['豆腐のサラダ', '味噌汁', 'ご飯100g'] },
                  ].map((col) => (
                    <div key={col.title}>
                      <div style={{ fontSize: 11, fontWeight: 700, color: '#C06010', marginBottom: 4, display: 'flex', alignItems: 'center', gap: 3 }}>
                        <span style={{ color: '#C06010' }}>{col.icon}</span>
                        {col.title}
                      </div>
                      <div style={{ fontSize: 11, color: '#7A4010', lineHeight: 1.8 }}>
                        {col.items.map((item) => (
                          <div key={item}>・{item}</div>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              </InfoCard>
            </BubbleCoach>
          </div>
        </div>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 16px 12px', background: '#fff', borderTop: '1px solid #F0F0F0' }}>
        <Camera size={26} color="#C0C0C0" />
        <div style={{ flex: 1, background: '#F5F5F5', borderRadius: 22, padding: '10px 16px', fontSize: 14, color: '#C0C0C0' }}>
          メッセージを入力...
        </div>
        <button style={{ background: ORANGE, color: '#fff', border: 'none', borderRadius: 22, padding: '10px 20px', fontSize: 14, fontWeight: 600, cursor: 'pointer' }}>
          送信
        </button>
      </div>
    </>
  )
}
