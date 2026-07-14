/**
 * ストリーミング中の Markdown を、確定ブロックと未確定プレーンに分離する。
 * 未確定記法を ReactMarkdown に渡さないことが目的。
 */

export type FinalizedBlock = {
  id: string;
  markdown: string;
};

export type PartitionResult = {
  blocks: string[];
  active: string;
};

function isListLine(line: string): boolean {
  return /^\s*([-*+]|\d+\.)\s+/.test(line);
}

function isHeadingLine(line: string): boolean {
  return /^#{1,6}\s+\S/.test(line);
}

/** インライン記法（** / `）の開始・終了が揃っているか */
export function isInlineBalanced(text: string): boolean {
  // 完成済みフェンスは除外してから判定
  let t = text.replace(/```[\s\S]*?```/g, "");
  const boldCount = (t.match(/\*\*/g) ?? []).length;
  if (boldCount % 2 !== 0) {
    return false;
  }
  t = t.replace(/\*\*[^*]*\*\*/g, "");
  // 残った単独 ** も不可
  if ((t.match(/\*\*/g) ?? []).length % 2 !== 0) {
    return false;
  }
  const tickCount = (t.match(/`/g) ?? []).length;
  return tickCount % 2 === 0;
}

function tryExtractCodeFence(text: string): { block: string } | null {
  if (!text.startsWith("```")) {
    return null;
  }
  const closeIdx = text.indexOf("```", 3);
  if (closeIdx === -1) {
    return null;
  }
  let end = closeIdx + 3;
  if (text[end] === "\n") {
    end += 1;
  }
  return { block: text.slice(0, end) };
}

function tryExtractHeading(text: string): { block: string } | null {
  const firstNl = text.indexOf("\n");
  const firstLine = firstNl === -1 ? text : text.slice(0, firstNl);
  if (!isHeadingLine(firstLine)) {
    return null;
  }
  // 行末の改行が来るまで確定しない
  if (firstNl === -1) {
    return null;
  }
  const block = text.slice(0, firstNl + 1);
  if (!isInlineBalanced(block)) {
    return null;
  }
  return { block };
}

/**
 * リストは空行が来るまで全体を active に残す。
 * 空行を含めて1ブロックとして確定する。
 */
function tryExtractList(text: string): { block: string } | null {
  const firstNl = text.indexOf("\n");
  const firstLine = firstNl === -1 ? text : text.slice(0, firstNl);
  if (!isListLine(firstLine)) {
    return null;
  }

  let pos = 0;
  let sawItem = false;

  while (pos < text.length) {
    const nextNl = text.indexOf("\n", pos);
    const lineEnd = nextNl === -1 ? text.length : nextNl;
    const line = text.slice(pos, lineEnd);

    if (!sawItem) {
      if (!isListLine(line)) {
        return null;
      }
      sawItem = true;
      if (nextNl === -1) {
        return null;
      }
      pos = nextNl + 1;
      continue;
    }

    if (isListLine(line)) {
      if (nextNl === -1) {
        return null;
      }
      pos = nextNl + 1;
      continue;
    }

    if (line === "") {
      // 空行でリスト確定（空行の改行まで含める）
      if (nextNl === -1) {
        // 末尾が空行相当
        return { block: text.slice(0, pos) };
      }
      const block = text.slice(0, nextNl + 1);
      if (!isInlineBalanced(block)) {
        return null;
      }
      return { block };
    }

    // 空行なしで別段落が始まった → まだ確定しない（リスト継続扱い）
    return null;
  }

  return null;
}

/**
 * 段落は空行（\n\n）で確定。途中の単改行だけでは確定しない。
 */
function tryExtractParagraph(text: string): { block: string } | null {
  const blank = text.indexOf("\n\n");
  if (blank === -1) {
    return null;
  }
  const block = text.slice(0, blank + 2);
  // 段落内に未完了フェンスが食い込んでいないこと
  if ((block.match(/```/g) ?? []).length % 2 !== 0) {
    return null;
  }
  if (!isInlineBalanced(block)) {
    return null;
  }
  return { block };
}

function tryExtractCompleteBlock(text: string): { block: string } | null {
  if (text === "") {
    return null;
  }

  // 先頭の単独改行はスキップせず active 側へ（段落余白の誤確定を防ぐ）
  if (text.startsWith("```")) {
    return tryExtractCodeFence(text);
  }

  const firstNl = text.indexOf("\n");
  const firstLine = firstNl === -1 ? text : text.slice(0, firstNl);

  if (isHeadingLine(firstLine)) {
    return tryExtractHeading(text);
  }

  if (isListLine(firstLine)) {
    return tryExtractList(text);
  }

  return tryExtractParagraph(text);
}

/**
 * 表示済み全文を確定ブロック列と未確定プレーンに分割する。
 */
export function partitionStreamMarkdown(text: string): PartitionResult {
  if (text === "") {
    return { blocks: [], active: "" };
  }

  const blocks: string[] = [];
  let offset = 0;

  while (offset < text.length) {
    const slice = text.slice(offset);
    const extracted = tryExtractCompleteBlock(slice);
    if (!extracted) {
      return { blocks, active: slice };
    }
    blocks.push(extracted.block);
    offset += extracted.block.length;
  }

  return { blocks, active: "" };
}

/**
 * 未確定 Markdown 記号がユーザーに見えないよう軽くマスクする。
 * ReactMarkdown を未確定で呼ぶ代わりのプレーン表示用。
 */
export function sanitizeActivePlainText(text: string): string {
  let s = text;

  // 未閉コードフェンス: 開始 ``` 以降を隠す
  const fenceMatches = s.match(/```/g);
  if (fenceMatches && fenceMatches.length % 2 === 1) {
    const i = s.lastIndexOf("```");
    s = s.slice(0, i);
  }

  // 未完了太字: 末尾の開始 ** を除去
  const boldMatches = s.match(/\*\*/g);
  if (boldMatches && boldMatches.length % 2 === 1) {
    const i = s.lastIndexOf("**");
    s = s.slice(0, i) + s.slice(i + 2);
  }

  // 未完了インラインコード
  // フェンス除去後に単独 backtick が奇数なら末尾を隠す
  const withoutFences = s.replace(/```[\s\S]*?```/g, "");
  const tickMatches = withoutFences.match(/`/g);
  if (tickMatches && tickMatches.length % 2 === 1) {
    const i = s.lastIndexOf("`");
    if (i !== -1) {
      s = s.slice(0, i) + s.slice(i + 1);
    }
  }

  return s;
}

/**
 * 既存ブロック配列を、新しい markdown 配列へ最小更新で同期する。
 * 先頭から一致するブロックは id / 参照を維持し、再レンダーを防ぐ。
 */
export function syncFinalizedBlocks(
  previous: FinalizedBlock[],
  nextMarkdowns: string[],
  createId: () => string,
): FinalizedBlock[] {
  let unchangedPrefix = 0;
  const limit = Math.min(previous.length, nextMarkdowns.length);
  while (
    unchangedPrefix < limit &&
    previous[unchangedPrefix].markdown === nextMarkdowns[unchangedPrefix]
  ) {
    unchangedPrefix += 1;
  }

  if (
    unchangedPrefix === nextMarkdowns.length &&
    previous.length === nextMarkdowns.length
  ) {
    return previous;
  }

  const next: FinalizedBlock[] = previous.slice(0, unchangedPrefix);
  for (let i = unchangedPrefix; i < nextMarkdowns.length; i += 1) {
    next.push({
      id: createId(),
      markdown: nextMarkdowns[i],
    });
  }
  return next;
}
