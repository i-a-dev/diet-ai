import { useEffect, useState, type CSSProperties } from 'react'
import { BottomSheet } from './BottomSheet.tsx'
import { ORANGE } from '../constants.ts'

interface WeightRegisterSheetProps {
  open: boolean
  initialValue: number
  dateLabel?: string
  referenceDateLabel?: string
  isSaving?: boolean
  onClose: () => void
  onSave: (value: number) => void | Promise<void>
}

function roundWeight(value: number) {
  return Math.round(value * 10) / 10
}

export function WeightRegisterSheet({
  open,
  initialValue,
  dateLabel,
  referenceDateLabel,
  isSaving = false,
  onClose,
  onSave,
}: WeightRegisterSheetProps) {
  const [value, setValue] = useState(initialValue)

  useEffect(() => {
    if (open) setValue(initialValue)
  }, [open, initialValue])

  const adjust = (delta: number) => {
    setValue((prev) => roundWeight(Math.max(20, Math.min(200, prev + delta))))
  }

  return (
    <BottomSheet open={open} title="体重を記録" onClose={onClose}>
      <div style={{ textAlign: 'center', padding: '8px 0 24px' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 20 }}>
          <button
            type="button"
            onClick={() => adjust(-0.1)}
            style={stepperBtnStyle}
            aria-label="0.1kg減らす"
          >
            −
          </button>
          <div>
            <span style={{ fontSize: 48, fontWeight: 700, color: '#111', lineHeight: 1 }}>{value.toFixed(1)}</span>
            <span style={{ fontSize: 20, color: '#888', marginLeft: 6 }}>kg</span>
          </div>
          <button
            type="button"
            onClick={() => adjust(0.1)}
            style={stepperBtnStyle}
            aria-label="0.1kg増やす"
          >
            ＋
          </button>
        </div>
        <div style={{ fontSize: 13, color: '#AAA', marginTop: 12 }}>
          {dateLabel ? `${dateLabel}の記録` : '今日の記録'}
        </div>
        {referenceDateLabel && (
          <div style={{ fontSize: 12, color: '#9CA3AF', marginTop: 4 }}>
            前回記録（{referenceDateLabel}）を初期値にしています
          </div>
        )}
      </div>

      <div style={{ display: 'flex', gap: 10 }}>
        <button type="button" onClick={onClose} disabled={isSaving} style={secondaryBtnStyle}>
          キャンセル
        </button>
        <button
          type="button"
          onClick={() => void onSave(value)}
          disabled={isSaving}
          style={{
            ...primaryBtnStyle,
            opacity: isSaving ? 0.7 : 1,
            cursor: isSaving ? 'not-allowed' : 'pointer',
          }}
        >
          {isSaving ? '保存中...' : '記録する'}
        </button>
      </div>
    </BottomSheet>
  )
}

const stepperBtnStyle: CSSProperties = {
  width: 44,
  height: 44,
  borderRadius: '50%',
  border: '1px solid #E8E8E8',
  background: '#fff',
  fontSize: 24,
  color: ORANGE,
  cursor: 'pointer',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  lineHeight: 1,
}

const secondaryBtnStyle: CSSProperties = {
  flex: 1,
  padding: '14px 0',
  borderRadius: 12,
  border: '1px solid #E8E8E8',
  background: '#fff',
  fontSize: 15,
  fontWeight: 600,
  color: '#666',
  cursor: 'pointer',
}

const primaryBtnStyle: CSSProperties = {
  flex: 1,
  padding: '14px 0',
  borderRadius: 12,
  border: 'none',
  background: ORANGE,
  fontSize: 15,
  fontWeight: 700,
  color: '#fff',
  cursor: 'pointer',
}
