/**
 * SSE 受信テキストをバッファし、書記素を一定間隔で1つずつ公開する。
 * 文字送りの時間管理は requestAnimationFrame + performance.now()。
 * （毎フレーム必ず文字を出すわけではなく、経過時間が間隔以上のときだけ公開）
 */

export const NORMAL_CHARACTER_INTERVAL_MS = 42;
export const MEDIUM_BACKLOG_INTERVAL_MS = 34;
export const LARGE_BACKLOG_INTERVAL_MS = 26;

export const MEDIUM_BACKLOG_LENGTH = 60;
export const LARGE_BACKLOG_LENGTH = 160;

export const MAX_REVEALS_PER_FRAME = 1;

export type StreamRevealController = {
  push: (text: string) => void;
  complete: () => void;
  cancel: () => void;
  getBuffer: () => string;
  getDisplayed: () => string;
};

export type StreamRevealOptions = {
  onUpdate: (displayed: string) => void;
  onCaughtUp?: (fullText: string) => void;
};

const graphemeSegmenter = (() => {
  const IntlAny = Intl as typeof Intl & {
    Segmenter?: new (
      locales?: string | string[],
      options?: { granularity?: "grapheme" | "word" | "sentence" },
    ) => { segment: (input: string) => Iterable<{ segment: string }> };
  };
  if (!IntlAny.Segmenter) {
    return null;
  }
  return new IntlAny.Segmenter("ja", { granularity: "grapheme" });
})();

/** 文字列を Unicode 書記素配列に分割する */
export function toGraphemes(text: string): string[] {
  if (text === "") {
    return [];
  }
  if (graphemeSegmenter) {
    return Array.from(graphemeSegmenter.segment(text), (item) => item.segment);
  }
  return Array.from(text);
}

function takeFirstGrapheme(text: string): string | null {
  if (text === "") {
    return null;
  }
  if (graphemeSegmenter) {
    for (const { segment } of graphemeSegmenter.segment(text)) {
      return segment;
    }
    return null;
  }
  const units = Array.from(text);
  return units[0] ?? null;
}

function getCharacterIntervalMs(backlogGraphemes: number): number {
  if (backlogGraphemes > LARGE_BACKLOG_LENGTH) {
    return LARGE_BACKLOG_INTERVAL_MS;
  }
  if (backlogGraphemes > MEDIUM_BACKLOG_LENGTH) {
    return MEDIUM_BACKLOG_INTERVAL_MS;
  }
  return NORMAL_CHARACTER_INTERVAL_MS;
}

export function createStreamReveal(
  options: StreamRevealOptions,
): StreamRevealController {
  let buffer = "";
  let displayed = "";
  let displayedUtf16Length = 0;
  let pendingGraphemeCount = 0;
  let isComplete = false;
  let cancelled = false;
  let rafId: number | null = null;
  let lastRevealAt = 0;
  let caughtUpNotified = false;

  const stopLoop = () => {
    if (rafId !== null) {
      window.cancelAnimationFrame(rafId);
      rafId = null;
    }
  };

  const hasPending = () => displayedUtf16Length < buffer.length;

  const notifyCaughtUpIfNeeded = () => {
    if (!isComplete || caughtUpNotified || cancelled) {
      return;
    }
    if (hasPending()) {
      return;
    }
    caughtUpNotified = true;
    stopLoop();
    options.onCaughtUp?.(buffer);
  };

  const revealOneGrapheme = (): boolean => {
    const remaining = buffer.slice(displayedUtf16Length);
    const grapheme = takeFirstGrapheme(remaining);
    if (grapheme === null) {
      return false;
    }
    displayedUtf16Length += grapheme.length;
    displayed = buffer.slice(0, displayedUtf16Length);
    pendingGraphemeCount = Math.max(0, pendingGraphemeCount - 1);
    options.onUpdate(displayed);
    return true;
  };

  const tick = (now: number) => {
    rafId = null;
    if (cancelled) {
      return;
    }

    if (!hasPending()) {
      notifyCaughtUpIfNeeded();
      return;
    }

    if (lastRevealAt === 0) {
      lastRevealAt = now;
    }

    let revealsThisFrame = 0;

    while (revealsThisFrame < MAX_REVEALS_PER_FRAME && hasPending()) {
      const intervalMs = getCharacterIntervalMs(pendingGraphemeCount);
      const maxLag = intervalMs * MAX_REVEALS_PER_FRAME;
      if (now - lastRevealAt > maxLag) {
        // フレーム落ち後に一気に追いつかせない
        lastRevealAt = now - maxLag;
      }

      if (now - lastRevealAt < intervalMs) {
        break;
      }

      if (!revealOneGrapheme()) {
        break;
      }

      lastRevealAt += intervalMs;
      revealsThisFrame += 1;
    }

    if (hasPending()) {
      rafId = window.requestAnimationFrame(tick);
      return;
    }

    notifyCaughtUpIfNeeded();
  };

  const ensureLoop = () => {
    if (cancelled || rafId !== null) {
      return;
    }
    rafId = window.requestAnimationFrame(tick);
  };

  return {
    push(text: string) {
      if (cancelled || text === "") {
        return;
      }
      buffer += text;
      pendingGraphemeCount += toGraphemes(text).length;
      ensureLoop();
    },
    complete() {
      if (cancelled) {
        return;
      }
      isComplete = true;
      if (!hasPending()) {
        notifyCaughtUpIfNeeded();
      } else {
        ensureLoop();
      }
    },
    cancel() {
      cancelled = true;
      stopLoop();
    },
    getBuffer: () => buffer,
    getDisplayed: () => displayed,
  };
}
