import { ORANGE } from '../constants.ts'

export function CoachAvatar() {
  return (
    <div
      style={{
        width: 38,
        height: 38,
        borderRadius: '50%',
        background: '#FDE8C8',
        border: '2px solid #F5D5A8',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0,
      }}
    >
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke={ORANGE} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="8" r="4" />
        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
      </svg>
    </div>
  )
}
