import { isWebSearchSource } from '../utils/calorieSource.ts'
import { AiEstimateIcon } from './AiEstimateIcon.tsx'

interface FoodChipProps {
  label: string
  kcal: string
  calorieSource?: string | null
  onClick?: () => void
}

const CHIP_BG = '#FFF5EB'
const CHIP_BORDER = '#F5E1D2'
const CHIP_TEXT = '#8B5E3C'
const WEB_SEARCH_ICON = '#4A90D9'

type ChipSourceKind = 'estimate' | 'web_search' | null

function resolveChipSourceKind(
  calorieSource: string | null | undefined,
): ChipSourceKind {
  if (isWebSearchSource(calorieSource)) {
    return 'web_search'
  }
  if (calorieSource === 'claude_estimate') {
    return 'estimate'
  }
  return null
}

/** 既存データに残る「（推定）」をチップ表示から外す */
function stripEstimateSuffix(label: string): string {
  return label.replace(/[ 　]*（推定）$/u, '').trim()
}

/** 添付画像のグローブ（緯度・経線） */
function AiWebSearchIcon({ size = 12 }: { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 16 16"
      fill="none"
      stroke={WEB_SEARCH_ICON}
      strokeWidth={1.4}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <circle cx="8" cy="8" r="6.2" />
      <ellipse cx="8" cy="8" rx="2.6" ry="6.2" />
      <path d="M2.2 8h11.6" />
      <path d="M3.1 4.8h9.8" />
      <path d="M3.1 11.2h9.8" />
    </svg>
  )
}

function SourceIcon({ kind }: { kind: Exclude<ChipSourceKind, null> }) {
  const label = kind === 'estimate' ? 'AI推定' : 'AI web検索'

  return (
    <span
      aria-label={label}
      title={label}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0,
        lineHeight: 0,
      }}
    >
      {kind === 'estimate' ? <AiEstimateIcon /> : <AiWebSearchIcon />}
    </span>
  )
}

export function FoodChip({ label, kcal, calorieSource, onClick }: FoodChipProps) {
  const sourceKind = resolveChipSourceKind(calorieSource)
  const displayLabel =
    sourceKind === 'estimate' ? stripEstimateSuffix(label) : label

  const style = {
    display: 'inline-flex' as const,
    alignItems: 'center' as const,
    justifyContent: 'space-between' as const,
    gap: 6,
    padding: '4px 8px',
    borderRadius: 999,
    border: `1px solid ${CHIP_BORDER}`,
    background: CHIP_BG,
    fontSize: 10,
    color: CHIP_TEXT,
    whiteSpace: 'nowrap' as const,
  }

  const content = (
    <>
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
        {sourceKind && <SourceIcon kind={sourceKind} />}
        <span>{displayLabel}</span>
      </span>
      <span>{kcal}</span>
    </>
  )

  if (!onClick) {
    return <div style={style}>{content}</div>
  }

  return (
    <button
      type="button"
      onClick={onClick}
      style={{ ...style, cursor: 'pointer' }}
    >
      {content}
    </button>
  )
}
