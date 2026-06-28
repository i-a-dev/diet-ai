import { useCallback, type CSSProperties, type ReactNode } from 'react'
import { usePressRepeat } from '../hooks/usePressRepeat.ts'

interface StepperButtonProps {
  ariaLabel: string
  onStep: () => void
  children: ReactNode
  style?: CSSProperties
  disabled?: boolean
}

export const stepperButtonBaseStyle: CSSProperties = {
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
  touchAction: 'none',
  userSelect: 'none',
  WebkitUserSelect: 'none',
}

export function StepperButton({
  ariaLabel,
  onStep,
  children,
  style,
  disabled = false,
}: StepperButtonProps) {
  const handleStep = useCallback(() => {
    onStep()
  }, [onStep])

  const pressHandlers = usePressRepeat(handleStep, disabled)

  return (
    <button
      type="button"
      aria-label={ariaLabel}
      disabled={disabled}
      style={{
        ...stepperButtonBaseStyle,
        ...style,
        opacity: disabled ? 0.5 : 1,
        cursor: disabled ? 'not-allowed' : 'pointer',
      }}
      {...pressHandlers}
    >
      {children}
    </button>
  )
}
