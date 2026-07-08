/**
 * 食品検索・登録コスト計測用メトリクス
 */
export interface RegistrationMetrics {
  rawInput?: string;
  selectedSource: string;
  searchStartedAt?: number;
  durationMs?: number;
  candidateCount?: number;
  selectedCandidateRank?: number | null;
  caloriesBeforeEdit?: number | null;
  usedAlias?: boolean;
  usedLocalDb?: boolean;
  usedBrave?: boolean;
  usedClaudeEstimate?: boolean;
  usedClaudeWebSearch?: boolean;
  webSearchCountDelta?: number;
  errorType?: string | null;
}

export function buildRegistrationMetricsFromSteps(params: {
  rawInput: string;
  selectedSource: string;
  searchStartedAt?: number;
  steps: Array<{ key: string; status: string }>;
  candidateCount?: number;
  selectedCandidateRank?: number | null;
  caloriesBeforeEdit?: number | null;
  webSearchCountDelta?: number;
}): RegistrationMetrics {
  const stepKeys = new Set(
    params.steps.filter((s) => s.status === "done").map((s) => s.key),
  );

  return {
    rawInput: params.rawInput,
    selectedSource: params.selectedSource,
    searchStartedAt: params.searchStartedAt,
    candidateCount: params.candidateCount,
    selectedCandidateRank: params.selectedCandidateRank ?? null,
    caloriesBeforeEdit: params.caloriesBeforeEdit ?? null,
    usedAlias: stepKeys.has("alias_db_searching"),
    usedLocalDb: stepKeys.has("local_db_searching"),
    usedBrave: params.selectedSource === "brave_html",
    usedClaudeEstimate: stepKeys.has("claude_estimating"),
    usedClaudeWebSearch:
      params.selectedSource === "claude_web_search" ||
      stepKeys.has("ai_web_searching"),
    webSearchCountDelta: params.webSearchCountDelta ?? 0,
  };
}
