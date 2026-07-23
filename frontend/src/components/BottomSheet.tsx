import type { CSSProperties, ReactNode } from 'react'
import { X } from 'lucide-react'

interface BottomSheetProps {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
  /**
   * true のとき、コンテンツ量に関わらず maxHeight と同じ高さで固定する。
   * 食品登録など、状態切替で高さがばらつくシート向け。
   */
  fillMaxHeight?: boolean
}

/**
 * 親（PhoneMock / 実機シェル）基準の 92%。
 * 実機シェルは 100dvh のため、実質的にアドレスバー変動にも追従する。
 */
const SHEET_MAX_HEIGHT = '92%'

export function BottomSheet({
  open,
  title,
  onClose,
  children,
  fillMaxHeight = false,
}: BottomSheetProps) {
  if (!open) return null

  const sheetStyle: CSSProperties = fillMaxHeight
    ? {
        background: '#fff',
        borderRadius: '16px 16px 0 0',
        paddingTop: 14,
        paddingLeft: 16,
        paddingRight: 16,
        paddingBottom: 0,
        height: SHEET_MAX_HEIGHT,
        maxHeight: SHEET_MAX_HEIGHT,
        boxSizing: 'border-box',
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden',
      }
    : {
        background: '#fff',
        borderRadius: '16px 16px 0 0',
        padding: '14px 16px 16px',
        maxHeight: SHEET_MAX_HEIGHT,
        overflowY: 'auto',
      }

  const bodyStyle: CSSProperties = fillMaxHeight
    ? {
        flex: 1,
        minHeight: 0,
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden',
      }
    : {}

  return (
    <div
      style={{
        position: 'absolute',
        inset: 0,
        zIndex: 20,
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'flex-end',
      }}
    >
      <button
        type="button"
        aria-label="閉じる"
        onClick={onClose}
        style={{
          flex: 1,
          border: 'none',
          background: 'rgba(0,0,0,0.35)',
          cursor: 'pointer',
          padding: 0,
        }}
      />
      <div style={sheetStyle}>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            marginBottom: 14,
            flexShrink: 0,
          }}
        >
          <span style={{ fontSize: 17, fontWeight: 700, color: '#111' }}>{title}</span>
          <button
            type="button"
            onClick={onClose}
            aria-label="閉じる"
            style={{
              border: 'none',
              background: 'transparent',
              padding: 8,
              margin: -4,
              minWidth: 44,
              minHeight: 44,
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <X size={22} color="#AAA" />
          </button>
        </div>
        <div style={bodyStyle}>{children}</div>
      </div>
    </div>
  )
}
