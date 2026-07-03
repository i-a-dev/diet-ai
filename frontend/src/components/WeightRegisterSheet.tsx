import { useCallback, useEffect, useState } from 'react'
import { BottomSheet } from './BottomSheet.tsx'
import { StepperButton } from './StepperButton.tsx'
import { ORANGE } from '../constants.ts'

interface WeightRegisterSheetProps {
  open: boolean
  initialValue: number
  dateLabel?: string
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
  isSaving = false,
  onClose,
  onSave,
}: WeightRegisterSheetProps) {
  const [value, setValue] = useState(initialValue)

  useEffect(() => {
    if (open) setValue(initialValue)
  }, [open, initialValue])

  const decrease = useCallback(() => {
    setValue((prev) => roundWeight(Math.max(20, Math.min(200, prev - 0.1))))
  }, [])

  const increase = useCallback(() => {
    setValue((prev) => roundWeight(Math.max(20, Math.min(200, prev + 0.1))))
  }, [])

  return (
    <BottomSheet open={open} title="体重を記録" onClose={onClose}>
      <div style={{ textAlign: 'center', padding: '8px 0 24px' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 20 }}>
          <StepperButton
            ariaLabel="0.1kg減らす"
            onStep={decrease}
            disabled={isSaving}
            style={{ fontSize: 24, color: ORANGE }}
          >
            −
          </StepperButton>
          <div>
            <span style={{ fontSize: 48, fontWeight: 700, color: '#111', lineHeight: 1 }}>{value.toFixed(1)}</span>
            <span style={{ fontSize: 20, color: '#888', marginLeft: 6 }}>kg</span>
          </div>
          <StepperButton
            ariaLabel="0.1kg増やす"
            onStep={increase}
            disabled={isSaving}
            style={{ fontSize: 24, color: ORANGE }}
          >
            ＋
          </StepperButton>
        </div>
        <div style={{ fontSize: 13, color: '#AAA', marginTop: 12 }}>
          {dateLabel ? `${dateLabel}の記録` : '今日の記録'}
        </div>
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

const secondaryBtnStyle = {
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

const primaryBtnStyle = {
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
