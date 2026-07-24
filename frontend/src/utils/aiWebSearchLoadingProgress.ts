export type AiWebSearchLoadingPhase =
  | "identifying_product"
  | "searching_sources"
  | "verifying_information"
  | "expanding_search"
  | "taking_longer";

export interface AiWebSearchLoadingPhaseConfig {
  phase: AiWebSearchLoadingPhase;
  startAtMs: number;
  /** 未指定なら最終フェーズ（漸近） */
  endAtMs?: number;
  startProgress: number;
  targetProgress: number;
  message: string;
}

export interface AiWebSearchLoadingState {
  phase: AiWebSearchLoadingPhase;
  progressPercent: number;
  statusMessage: string;
}

/** AI Web検索（web / deep_web）共通の時間ベース位相 */
export const AI_WEB_SEARCH_LOADING_PHASES: AiWebSearchLoadingPhaseConfig[] = [
  {
    phase: "identifying_product",
    startAtMs: 0,
    endAtMs: 1200,
    startProgress: 8,
    targetProgress: 18,
    message: "商品名を確認しています",
  },
  {
    phase: "searching_sources",
    startAtMs: 1200,
    endAtMs: 5000,
    startProgress: 18,
    targetProgress: 55,
    message: "公式サイトや栄養成分を探しています",
  },
  {
    phase: "verifying_information",
    startAtMs: 5000,
    endAtMs: 9000,
    startProgress: 55,
    targetProgress: 78,
    message: "見つかった情報を照合しています",
  },
  {
    phase: "expanding_search",
    startAtMs: 9000,
    endAtMs: 15000,
    startProgress: 78,
    targetProgress: 90,
    message: "より正確に特定するため、検索範囲を広げています",
  },
  {
    phase: "taking_longer",
    startAtMs: 15000,
    startProgress: 90,
    targetProgress: 94,
    message: "詳しく確認しています。通常より時間がかかっています",
  },
];

export const AI_WEB_SEARCH_LOADING_HINT =
  "商品によっては数秒かかる場合があります";

export const AI_WEB_SEARCH_LOADING_MAX_PROGRESS = 94;
export const AI_WEB_SEARCH_COMPLETE_ANIMATION_MS = 200;

function clamp01(value: number): number {
  if (value <= 0) return 0;
  if (value >= 1) return 1;
  return value;
}

function easeOutCubic(t: number): number {
  const x = 1 - t;
  return 1 - x * x * x;
}

function resolvePhaseConfig(elapsedMs: number): AiWebSearchLoadingPhaseConfig {
  const safeElapsed = Math.max(0, elapsedMs);
  for (let i = AI_WEB_SEARCH_LOADING_PHASES.length - 1; i >= 0; i -= 1) {
    const config = AI_WEB_SEARCH_LOADING_PHASES[i];
    if (safeElapsed >= config.startAtMs) {
      return config;
    }
  }
  return AI_WEB_SEARCH_LOADING_PHASES[0];
}

function progressWithinPhase(
  elapsedMs: number,
  config: AiWebSearchLoadingPhaseConfig,
): number {
  const { startProgress, targetProgress, startAtMs, endAtMs } = config;

  if (endAtMs == null) {
    // 15秒以降: 90% → 94% へ漸近（100%にはしない）
    const overMs = Math.max(0, elapsedMs - startAtMs);
    const asymptotic = 1 - Math.exp(-overMs / 7000);
    return (
      startProgress +
      (targetProgress - startProgress) * asymptotic
    );
  }

  const duration = Math.max(endAtMs - startAtMs, 1);
  const t = clamp01((elapsedMs - startAtMs) / duration);
  return startProgress + (targetProgress - startProgress) * easeOutCubic(t);
}

/**
 * 経過時間から AI Web検索のソフト進捗を解決する。
 * previousProgressPercent より小さくならない。API完了前は最大94%。
 */
export function resolveAiWebSearchLoadingState(
  aiWebSearchElapsedMs: number,
  previousProgressPercent = 0,
): AiWebSearchLoadingState {
  const config = resolvePhaseConfig(aiWebSearchElapsedMs);
  const rawProgress = progressWithinPhase(aiWebSearchElapsedMs, config);
  const capped = Math.min(rawProgress, AI_WEB_SEARCH_LOADING_MAX_PROGRESS);

  return {
    phase: config.phase,
    progressPercent: Math.max(previousProgressPercent, capped),
    statusMessage: config.message,
  };
}
