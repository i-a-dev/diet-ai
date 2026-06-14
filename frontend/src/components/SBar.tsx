export function SBar() {
  return (
    <div
      style={{
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        padding: '14px 24px 4px',
        fontSize: 12,
        fontWeight: 700,
        color: '#fff',
        background: '#000',
      }}
    >
      <span>9:41</span>
      <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
        <svg width="17" height="12" viewBox="0 0 17 12" fill="none">
          <rect x="0" y="3" width="3" height="9" rx="1" fill="#fff" />
          <rect x="4.5" y="2" width="3" height="10" rx="1" fill="#fff" />
          <rect x="9" y="0" width="3" height="12" rx="1" fill="#fff" />
          <rect x="13.5" y="0" width="3" height="12" rx="1" fill="rgba(255,255,255,0.35)" />
        </svg>
        <svg width="16" height="12" viewBox="0 0 16 12" fill="none">
          <path
            d="M8 2.4C10.8 2.4 13.3 3.6 15 5.5L16 4.3C14 2.1 11.2 0.8 8 0.8C4.8 0.8 2 2.1 0 4.3L1 5.5C2.7 3.6 5.2 2.4 8 2.4Z"
            fill="#fff"
          />
          <path
            d="M8 5.2C9.9 5.2 11.6 6 12.8 7.3L13.8 6.1C12.3 4.6 10.3 3.6 8 3.6C5.7 3.6 3.7 4.6 2.2 6.1L3.2 7.3C4.4 6 6.1 5.2 8 5.2Z"
            fill="#fff"
          />
          <circle cx="8" cy="10" r="1.5" fill="#fff" />
        </svg>
        <svg width="25" height="12" viewBox="0 0 25 12" fill="none">
          <rect x="0.5" y="0.5" width="21" height="11" rx="3.5" stroke="#fff" strokeOpacity="0.35" />
          <rect x="2" y="2" width="16" height="8" rx="2" fill="#fff" />
          <path d="M23 4.5V7.5C23.8 7.2 24.5 6.5 24.5 6C24.5 5.5 23.8 4.8 23 4.5Z" fill="#fff" fillOpacity="0.4" />
        </svg>
      </div>
    </div>
  )
}
