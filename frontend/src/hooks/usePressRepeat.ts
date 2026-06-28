import { useCallback, useEffect, useRef, type PointerEvent as ReactPointerEvent, type SyntheticEvent } from 'react'

const INITIAL_DELAY_MS = 400
const START_INTERVAL_MS = 120
const MIN_INTERVAL_MS = 50
const ACCELERATION_STEP_MS = 8

export function usePressRepeat(onRepeat: () => void, disabled = false) {
  const onRepeatRef = useRef(onRepeat)
  const delayTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const repeatTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const intervalMsRef = useRef(START_INTERVAL_MS)

  useEffect(() => {
    onRepeatRef.current = onRepeat
  }, [onRepeat])

  const clearTimers = useCallback(() => {
    if (delayTimerRef.current !== null) {
      clearTimeout(delayTimerRef.current)
      delayTimerRef.current = null
    }
    if (repeatTimerRef.current !== null) {
      clearTimeout(repeatTimerRef.current)
      repeatTimerRef.current = null
    }
    intervalMsRef.current = START_INTERVAL_MS
  }, [])

  const scheduleRepeat = useCallback(() => {
    repeatTimerRef.current = setTimeout(() => {
      onRepeatRef.current()
      intervalMsRef.current = Math.max(
        MIN_INTERVAL_MS,
        intervalMsRef.current - ACCELERATION_STEP_MS,
      )
      scheduleRepeat()
    }, intervalMsRef.current)
  }, [])

  const start = useCallback(() => {
    if (disabled) {
      return
    }

    clearTimers()
    onRepeatRef.current()
    intervalMsRef.current = START_INTERVAL_MS
    delayTimerRef.current = setTimeout(() => {
      scheduleRepeat()
    }, INITIAL_DELAY_MS)
  }, [clearTimers, disabled, scheduleRepeat])

  const stop = useCallback(() => {
    clearTimers()
  }, [clearTimers])

  useEffect(() => clearTimers, [clearTimers])

  const onPointerDown = useCallback(
    (event: ReactPointerEvent<HTMLButtonElement>) => {
      if (disabled) {
        return
      }

      event.preventDefault()
      event.currentTarget.setPointerCapture(event.pointerId)
      start()
    },
    [disabled, start],
  )

  return {
    onPointerDown,
    onPointerUp: stop,
    onPointerLeave: stop,
    onPointerCancel: stop,
    onLostPointerCapture: stop,
    onContextMenu: (event: SyntheticEvent) => {
      event.preventDefault()
    },
  }
}
