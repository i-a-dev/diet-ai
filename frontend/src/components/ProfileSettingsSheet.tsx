import { useEffect, useState, type CSSProperties, type ReactNode } from 'react'
import { ChevronLeft, Target, User, X } from 'lucide-react'
import { fetchUserProfile, updateUserProfile } from '../api/client.ts'
import { ORANGE } from '../constants.ts'

const GREEN = '#48B868'
const GREEN_BG = '#E8F7ED'
const ORANGE_BG = '#FFF3E6'

interface ProfileSettingsSheetProps {
  open: boolean
  onClose: () => void
  onSaved?: () => void
}

function roundOneDecimal(value: number) {
  return Math.round(value * 10) / 10
}

function SettingCard({
  icon,
  iconBg,
  label,
  value,
  unit,
  min,
  max,
  step,
  onChange,
}: {
  icon: ReactNode
  iconBg: string
  label: string
  value: number
  unit: string
  min: number
  max: number
  step: number
  onChange: (value: number) => void
}) {
  const adjust = (delta: number) => {
    onChange(roundOneDecimal(Math.max(min, Math.min(max, value + delta))))
  }

  return (
    <div style={cardStyle}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 20 }}>
        <div
          style={{
            width: 36,
            height: 36,
            borderRadius: 10,
            background: iconBg,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            flexShrink: 0,
          }}
        >
          {icon}
        </div>
        <span style={{ fontSize: 16, fontWeight: 700, color: '#111' }}>{label}</span>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 24 }}>
        <button
          type="button"
          onClick={() => adjust(-step)}
          style={stepperBtnStyle}
          aria-label={`${label}を${step}減らす`}
        >
          −
        </button>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
          <span style={{ fontSize: 44, fontWeight: 700, color: '#111', lineHeight: 1 }}>
            {value.toFixed(1)}
          </span>
          <span style={{ fontSize: 18, color: '#999', fontWeight: 500 }}>{unit}</span>
        </div>
        <button
          type="button"
          onClick={() => adjust(step)}
          style={stepperBtnStyle}
          aria-label={`${label}を${step}増やす`}
        >
          ＋
        </button>
      </div>
    </div>
  )
}

export function ProfileSettingsSheet({ open, onClose, onSaved }: ProfileSettingsSheetProps) {
  const [heightCm, setHeightCm] = useState(160)
  const [targetWeightKg, setTargetWeightKg] = useState(57)
  const [isLoading, setIsLoading] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) {
      return
    }

    let cancelled = false
    setIsLoading(true)
    setError(null)

    fetchUserProfile()
      .then((response) => {
        if (cancelled) {
          return
        }
        setHeightCm(response.profile.heightCm ?? 160)
        setTargetWeightKg(response.profile.targetWeightKg ?? 57)
      })
      .catch((fetchError: Error) => {
        if (!cancelled) {
          setError(fetchError.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [open])

  const handleSave = async () => {
    setIsSaving(true)
    setError(null)

    try {
      await updateUserProfile({ heightCm, targetWeightKg })
      onSaved?.()
      onClose()
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : '保存に失敗しました')
    } finally {
      setIsSaving(false)
    }
  }

  if (!open) {
    return null
  }

  return (
    <div
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 50,
        background: '#F5F5F7',
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden',
      }}
    >
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          minHeight: 44,
          padding: '0 16px',
          background: '#fff',
          borderBottom: '1px solid #F0F0F0',
          flexShrink: 0,
        }}
      >
        <button
          type="button"
          onClick={onClose}
          aria-label="戻る"
          style={headerBtnStyle}
        >
          <ChevronLeft size={24} color="#111" />
        </button>
        <span style={{ fontSize: 16, fontWeight: 700, color: '#111' }}>身長・目標体重を登録</span>
        <button
          type="button"
          onClick={onClose}
          aria-label="閉じる"
          style={headerBtnStyle}
        >
          <X size={22} color="#AAA" />
        </button>
      </div>

      <div
        style={{
          flex: 1,
          overflowY: 'auto',
          padding: '20px 16px 24px',
        }}
      >
        <p
          style={{
            margin: '0 0 20px',
            fontSize: 14,
            color: '#666',
            lineHeight: 1.7,
            textAlign: 'center',
          }}
        >
          あなたに最適なアドバイスや目標設定のために、
          <br />
          現在の身長と目標体重を登録しましょう。
        </p>

        {isLoading ? (
          <div style={{ textAlign: 'center', padding: '48px 0', color: '#888', fontSize: 14 }}>
            読み込み中...
          </div>
        ) : (
          <>
            <SettingCard
              icon={<User size={18} color={GREEN} strokeWidth={2.2} />}
              iconBg={GREEN_BG}
              label="身長"
              value={heightCm}
              unit="cm"
              min={100}
              max={220}
              step={0.1}
              onChange={setHeightCm}
            />

            <SettingCard
              icon={<Target size={18} color={ORANGE} strokeWidth={2.2} />}
              iconBg={ORANGE_BG}
              label="目標体重"
              value={targetWeightKg}
              unit="kg"
              min={20}
              max={200}
              step={0.1}
              onChange={setTargetWeightKg}
            />

            {error && (
              <div style={{ fontSize: 13, color: '#DC2626', textAlign: 'center', marginBottom: 12 }}>
                {error}
              </div>
            )}

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 4 }}>
              <button
                type="button"
                onClick={() => void handleSave()}
                disabled={isSaving}
                style={{
                  ...primaryBtnStyle,
                  opacity: isSaving ? 0.7 : 1,
                  cursor: isSaving ? 'not-allowed' : 'pointer',
                }}
              >
                {isSaving ? '保存中...' : '保存する'}
              </button>
              <button type="button" onClick={onClose} disabled={isSaving} style={secondaryBtnStyle}>
                キャンセル
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

const headerBtnStyle: CSSProperties = {
  width: 32,
  height: 32,
  border: 'none',
  background: 'transparent',
  padding: 0,
  cursor: 'pointer',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
}

const cardStyle: CSSProperties = {
  background: '#fff',
  borderRadius: 16,
  border: '1px solid #EBEBEB',
  padding: '18px 16px 16px',
  marginBottom: 14,
}

const stepperBtnStyle: CSSProperties = {
  width: 44,
  height: 44,
  borderRadius: '50%',
  border: '1px solid #E8E8E8',
  background: '#fff',
  fontSize: 22,
  color: '#888',
  cursor: 'pointer',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  lineHeight: 1,
  flexShrink: 0,
}

const primaryBtnStyle: CSSProperties = {
  width: '100%',
  padding: '15px 0',
  borderRadius: 12,
  border: 'none',
  background: ORANGE,
  fontSize: 16,
  fontWeight: 700,
  color: '#fff',
  cursor: 'pointer',
}

const secondaryBtnStyle: CSSProperties = {
  width: '100%',
  padding: '15px 0',
  borderRadius: 12,
  border: '1px solid #E8E8E8',
  background: '#fff',
  fontSize: 16,
  fontWeight: 600,
  color: '#666',
  cursor: 'pointer',
}
