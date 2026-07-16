/**
 * ストリーミング用のインクリメンタル Markdown 記号除去。
 * 書記素を1つずつ受け取り、表示してよい文字列だけを append する（append-only）。
 */

export type StreamingTextFilterState = {
  atLineStart: boolean;
  linePrefix: string;
  pendingAsterisks: number;
  pendingUnderscores: number;
  pendingTildes: number;
  pendingBackticks: number;
  inCodeFence: boolean;
  skippingFenceLang: boolean;
  inInlineCode: boolean;
};

export type StreamingTextFilter = {
  push: (grapheme: string) => string;
  getState: () => StreamingTextFilterState;
};

function isEmphasisOnlyPrefix(prefix: string): boolean {
  return (
    /^\*{1,3}$/.test(prefix) ||
    /^_{1,3}$/.test(prefix) ||
    /^~{1,2}$/.test(prefix)
  );
}

function isBlockMarkerPrefix(prefix: string): boolean {
  if (/^\s{0,3}#{1,6}$/.test(prefix)) {
    return true;
  }
  if (/^\s{0,3}[-*+]$/.test(prefix)) {
    return true;
  }
  if (/^\s{0,3}>$/.test(prefix)) {
    return true;
  }
  if (/^\s{0,3}\d+\.$/.test(prefix)) {
    return true;
  }
  if (/^\s{0,3}(-{3,}|\*{3,}|_{3,})$/.test(prefix)) {
    return true;
  }
  return false;
}

function canGrowPrefix(prefix: string, next: string): boolean {
  const candidate = prefix + next;
  if (/^\s{0,3}$/.test(candidate)) {
    return true;
  }
  if (/^\s{0,3}#{1,6}$/.test(candidate)) {
    return true;
  }
  if (/^\s{0,3}[-*+]$/.test(candidate)) {
    return true;
  }
  if (/^\s{0,3}>$/.test(candidate)) {
    return true;
  }
  if (/^\s{0,3}\d+$/.test(candidate)) {
    return true;
  }
  if (/^\s{0,3}\d+\.$/.test(candidate)) {
    return true;
  }
  if (/^\s{0,3}-{1,}$/.test(candidate)) {
    return true;
  }
  if (/^\s{0,3}\*{1,}$/.test(candidate)) {
    return true;
  }
  if (/^\s{0,3}_{1,}$/.test(candidate)) {
    return true;
  }
  return false;
}

export function createStreamingTextFilter(): StreamingTextFilter {
  const state: StreamingTextFilterState = {
    atLineStart: true,
    linePrefix: "",
    pendingAsterisks: 0,
    pendingUnderscores: 0,
    pendingTildes: 0,
    pendingBackticks: 0,
    inCodeFence: false,
    skippingFenceLang: false,
    inInlineCode: false,
  };

  const discardInlinePendings = () => {
    state.pendingAsterisks = 0;
    state.pendingUnderscores = 0;
    state.pendingTildes = 0;
  };

  const pushContent = (grapheme: string): string => {
    if (grapheme === "\n") {
      discardInlinePendings();
      state.atLineStart = true;
      state.linePrefix = "";
      return "\n";
    }

    if (grapheme === "*") {
      state.pendingUnderscores = 0;
      state.pendingTildes = 0;
      state.pendingAsterisks += 1;
      if (state.pendingAsterisks >= 2) {
        state.pendingAsterisks = 0;
      }
      return "";
    }

    if (grapheme === "_") {
      state.pendingAsterisks = 0;
      state.pendingTildes = 0;
      state.pendingUnderscores += 1;
      if (state.pendingUnderscores >= 2) {
        state.pendingUnderscores = 0;
      }
      return "";
    }

    if (grapheme === "~") {
      state.pendingAsterisks = 0;
      state.pendingUnderscores = 0;
      state.pendingTildes += 1;
      if (state.pendingTildes >= 2) {
        state.pendingTildes = 0;
      }
      return "";
    }

    discardInlinePendings();
    state.atLineStart = false;
    return grapheme;
  };

  const push = (grapheme: string): string => {
    if (grapheme === "\r") {
      return "";
    }

    if (state.skippingFenceLang) {
      if (grapheme === "\n") {
        state.skippingFenceLang = false;
        state.atLineStart = true;
        state.linePrefix = "";
        // 言語指定行末の改行は出さない（コード先頭の空行ジャンプを防ぐ）
        return "";
      }
      return "";
    }

    if (grapheme === "`") {
      if (state.linePrefix) {
        const prefix = state.linePrefix;
        state.linePrefix = "";
        state.atLineStart = false;
        if (isEmphasisOnlyPrefix(prefix) || isBlockMarkerPrefix(prefix)) {
          state.pendingBackticks += 1;
          return "";
        }
        state.pendingBackticks += 1;
        return prefix;
      }
      discardInlinePendings();
      state.pendingBackticks += 1;
      return "";
    }

    if (state.pendingBackticks > 0) {
      const count = state.pendingBackticks;
      state.pendingBackticks = 0;

      if (count >= 3) {
        state.inCodeFence = !state.inCodeFence;
        if (state.inCodeFence) {
          state.skippingFenceLang = true;
          state.atLineStart = false;
          state.linePrefix = "";
          if (grapheme === "\n") {
            state.skippingFenceLang = false;
            state.atLineStart = true;
            return "";
          }
          return "";
        }
        state.atLineStart = grapheme === "\n";
        state.linePrefix = "";
        if (grapheme === "\n") {
          return "\n";
        }
      } else if (count === 1) {
        state.inInlineCode = !state.inInlineCode;
      }
      // count === 2: 空インライン相当 → 破棄して続行
    }

    if (state.inCodeFence) {
      if (grapheme === "\n") {
        state.atLineStart = true;
        state.linePrefix = "";
      } else {
        state.atLineStart = false;
      }
      return grapheme;
    }

    if (state.inInlineCode) {
      if (grapheme === "\n") {
        state.inInlineCode = false;
        state.atLineStart = true;
        state.linePrefix = "";
        discardInlinePendings();
        return "\n";
      }
      return grapheme;
    }

    if (state.atLineStart || state.linePrefix.length > 0) {
      if (grapheme === "\n") {
        if (
          state.linePrefix === "" ||
          isBlockMarkerPrefix(state.linePrefix) ||
          isEmphasisOnlyPrefix(state.linePrefix)
        ) {
          state.linePrefix = "";
          state.atLineStart = true;
          return "\n";
        }
        const emitted = state.linePrefix + "\n";
        state.linePrefix = "";
        state.atLineStart = true;
        return emitted;
      }

      if (grapheme === " " || grapheme === "\t") {
        if (isBlockMarkerPrefix(state.linePrefix)) {
          state.linePrefix = "";
          state.atLineStart = false;
          return "";
        }
        if (isEmphasisOnlyPrefix(state.linePrefix)) {
          state.linePrefix = "";
          state.atLineStart = false;
          return grapheme;
        }
        if (state.linePrefix === "" && state.atLineStart) {
          return grapheme;
        }
        const emitted = state.linePrefix + grapheme;
        state.linePrefix = "";
        state.atLineStart = false;
        return emitted;
      }

      if (canGrowPrefix(state.linePrefix, grapheme)) {
        state.linePrefix += grapheme;
        state.atLineStart = false;
        return "";
      }

      const prefix = state.linePrefix;
      state.linePrefix = "";
      state.atLineStart = false;
      if (isEmphasisOnlyPrefix(prefix) || isBlockMarkerPrefix(prefix)) {
        return pushContent(grapheme);
      }
      if (prefix) {
        return prefix + pushContent(grapheme);
      }
    }

    return pushContent(grapheme);
  };

  return {
    push,
    getState: () => ({ ...state }),
  };
}

/** テスト・互換用: 全文をインクリメンタルフィルターに通す */
export function stripMarkdownForStreaming(raw: string): string {
  if (raw === "") {
    return "";
  }
  const filter = createStreamingTextFilter();
  let out = "";
  for (const unit of Array.from(raw)) {
    out += filter.push(unit);
  }
  return out;
}
