import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  AI_WEB_SEARCH_COMPLETE_ANIMATION_MS,
  AI_WEB_SEARCH_LOADING_HINT,
  AI_WEB_SEARCH_LOADING_MAX_PROGRESS,
  resolveAiWebSearchLoadingState,
} from "./aiWebSearchLoadingProgress.ts";

describe("resolveAiWebSearchLoadingState", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("開始直後は商品名確認の文言とおおよそ8〜18%", () => {
    const atStart = resolveAiWebSearchLoadingState(0);
    expect(atStart.statusMessage).toBe("商品名を確認しています");
    expect(atStart.phase).toBe("identifying_product");
    expect(atStart.progressPercent).toBeGreaterThanOrEqual(8);
    expect(atStart.progressPercent).toBeLessThan(18);

    const nearEnd = resolveAiWebSearchLoadingState(1199);
    expect(nearEnd.phase).toBe("identifying_product");
    expect(nearEnd.progressPercent).toBeLessThanOrEqual(18);
  });

  it("約1.2秒後に公式サイト探索の文言になる", () => {
    const state = resolveAiWebSearchLoadingState(1200);
    expect(state.statusMessage).toBe(
      "公式サイトや栄養成分を探しています",
    );
    expect(state.phase).toBe("searching_sources");
    expect(state.progressPercent).toBeGreaterThanOrEqual(18);
    expect(state.progressPercent).toBeLessThanOrEqual(55);
  });

  it("約5秒後に照合の文言になる", () => {
    const state = resolveAiWebSearchLoadingState(5000);
    expect(state.statusMessage).toBe("見つかった情報を照合しています");
    expect(state.phase).toBe("verifying_information");
    expect(state.progressPercent).toBeGreaterThanOrEqual(55);
    expect(state.progressPercent).toBeLessThanOrEqual(78);
  });

  it("約9秒後に検索範囲拡大の文言になる", () => {
    const state = resolveAiWebSearchLoadingState(9000);
    expect(state.statusMessage).toBe(
      "より正確に特定するため、検索範囲を広げています",
    );
    expect(state.phase).toBe("expanding_search");
    expect(state.progressPercent).toBeGreaterThanOrEqual(78);
    expect(state.progressPercent).toBeLessThanOrEqual(90);
  });

  it("約15秒後に時間がかかっている文言になる", () => {
    const state = resolveAiWebSearchLoadingState(15000);
    expect(state.statusMessage).toBe(
      "詳しく確認しています。通常より時間がかかっています",
    );
    expect(state.phase).toBe("taking_longer");
    expect(state.progressPercent).toBeGreaterThanOrEqual(90);
  });

  it("API完了前に100%にならない", () => {
    for (const elapsed of [0, 1200, 5000, 9000, 15000, 30000, 120000]) {
      const state = resolveAiWebSearchLoadingState(elapsed);
      expect(state.progressPercent).toBeLessThan(100);
    }
  });

  it("長時間待機しても94%を超えない", () => {
    const state = resolveAiWebSearchLoadingState(60_000);
    expect(state.progressPercent).toBeLessThanOrEqual(
      AI_WEB_SEARCH_LOADING_MAX_PROGRESS,
    );
    expect(state.progressPercent).toBeGreaterThan(90);
  });

  it("進捗は後退しない", () => {
    const first = resolveAiWebSearchLoadingState(5000);
    const second = resolveAiWebSearchLoadingState(1000, first.progressPercent);
    expect(second.progressPercent).toBe(first.progressPercent);
  });

  it("フェーズ境界で急激にジャンプしない", () => {
    const before = resolveAiWebSearchLoadingState(1199);
    const after = resolveAiWebSearchLoadingState(1200);
    expect(Math.abs(after.progressPercent - before.progressPercent)).toBeLessThan(
      2,
    );
  });

  it("補足文言と完了アニメ時間が要件範囲内", () => {
    expect(AI_WEB_SEARCH_LOADING_HINT).toBe(
      "商品によっては数秒かかる場合があります",
    );
    expect(AI_WEB_SEARCH_COMPLETE_ANIMATION_MS).toBeGreaterThanOrEqual(150);
    expect(AI_WEB_SEARCH_COMPLETE_ANIMATION_MS).toBeLessThanOrEqual(250);
  });

  it("fake timer でも経過時間計算が安定する", () => {
    const startedAt = Date.now();
    vi.setSystemTime(startedAt);
    const t0 = resolveAiWebSearchLoadingState(0);

    vi.setSystemTime(startedAt + 5200);
    const elapsed = Date.now() - startedAt;
    const t1 = resolveAiWebSearchLoadingState(elapsed);

    expect(t0.phase).toBe("identifying_product");
    expect(t1.phase).toBe("verifying_information");
    expect(t1.progressPercent).toBeGreaterThan(t0.progressPercent);
  });
});
