/**
 * ストリーミング raw テキストから、完成した Markdown ブロックだけを切り出す。
 * 未完成の末尾は active として残す（ReactMarkdown に渡さない）。
 */

export type FinalizedMarkdownBlock = {
  id: string;
  source: string;
};

export type PartitionResult = {
  blocks: string[];
  active: string;
};

function isListLine(line: string): boolean {
  return /^\s{0,3}([-*+]|\d+\.)\s+/.test(line);
}

function isHeadingLine(line: string): boolean {
  return /^\s{0,3}#{1,6}\s+\S/.test(line);
}

function isBlockquoteLine(line: string): boolean {
  return /^\s{0,3}>\s?/.test(line);
}

/** インライン記法（** / __ / ` / ~~）の開始・終了が揃っているか */
export function isInlineBalanced(text: string): boolean {
  let t = text.replace(/```[\s\S]*?```/g, "");
  const boldCount = (t.match(/\*\*/g) ?? []).length;
  if (boldCount % 2 !== 0) {
    return false;
  }
  t = t.replace(/\*\*[^*]*\*\*/g, "");
  if ((t.match(/\*\*/g) ?? []).length % 2 !== 0) {
    return false;
  }
  const underCount = (t.match(/__/g) ?? []).length;
  if (underCount % 2 !== 0) {
    return false;
  }
  const strikeCount = (t.match(/~~/g) ?? []).length;
  if (strikeCount % 2 !== 0) {
    return false;
  }
  const withoutFences = t.replace(/```[\s\S]*?```/g, "");
  const tickCount = (withoutFences.match(/`/g) ?? []).length;
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
  // 終了フェンス後の改行まで含めて確定（なければ未確定）
  if (end >= text.length) {
    return null;
  }
  if (text[end] === "\n") {
    end += 1;
  } else if (text[end] === "\r" && text[end + 1] === "\n") {
    end += 2;
  } else {
    // 閉じた直後に別文字 → フェンス行末改行待ち
    return null;
  }
  return { block: text.slice(0, end) };
}

function tryExtractHeading(text: string): { block: string } | null {
  const firstNl = text.indexOf("\n");
  const firstLine = firstNl === -1 ? text : text.slice(0, firstNl);
  if (!isHeadingLine(firstLine)) {
    return null;
  }
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
 * 引用は連続する > 行をまとめ、空行または非引用行の手前までで確定。
 * 空行を含む場合はその空行まで含める。
 */
function tryExtractBlockquote(text: string): { block: string } | null {
  const firstNl = text.indexOf("\n");
  const firstLine = firstNl === -1 ? text : text.slice(0, firstNl);
  if (!isBlockquoteLine(firstLine)) {
    return null;
  }
  if (firstNl === -1) {
    return null;
  }

  let pos = 0;
  while (pos < text.length) {
    const nextNl = text.indexOf("\n", pos);
    const lineEnd = nextNl === -1 ? text.length : nextNl;
    const line = text.slice(pos, lineEnd);

    if (isBlockquoteLine(line)) {
      if (nextNl === -1) {
        return null;
      }
      pos = nextNl + 1;
      continue;
    }

    if (line === "") {
      if (nextNl === -1) {
        return { block: text.slice(0, pos) };
      }
      const block = text.slice(0, nextNl + 1);
      if (!isInlineBalanced(block)) {
        return null;
      }
      return { block };
    }

    // 非引用行が来た → 直前まで確定
    const block = text.slice(0, pos);
    if (block === "" || !isInlineBalanced(block)) {
      return null;
    }
    return { block };
  }

  return null;
}

/**
 * リストは空行、または別種ブロック開始まで全体を active に残し、
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

    // リスト項目の継続行（インデント付き）はリスト内
    if (/^\s{2,}\S/.test(line) && line !== "") {
      if (nextNl === -1) {
        return null;
      }
      pos = nextNl + 1;
      continue;
    }

    if (line === "") {
      if (nextNl === -1) {
        return { block: text.slice(0, pos) };
      }
      const block = text.slice(0, nextNl + 1);
      if (!isInlineBalanced(block)) {
        return null;
      }
      return { block };
    }

    // 別ブロック開始（見出し・引用・フェンス等）
    if (
      isHeadingLine(line) ||
      isBlockquoteLine(line) ||
      line.startsWith("```")
    ) {
      const block = text.slice(0, pos);
      if (block === "" || !isInlineBalanced(block)) {
        return null;
      }
      return { block };
    }

    // 空行なしで別段落 → まだ確定しない
    return null;
  }

  return null;
}

/**
 * 段落は空行（\n\n）で確定。途中の単改行だけでは確定しない。
 * 太字などが揃っていても段落終了まで確定しない。
 */
function tryExtractParagraph(text: string): { block: string } | null {
  const blank = text.indexOf("\n\n");
  if (blank === -1) {
    return null;
  }
  const block = text.slice(0, blank + 2);
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

  if (text.startsWith("```")) {
    return tryExtractCodeFence(text);
  }

  const firstNl = text.indexOf("\n");
  const firstLine = firstNl === -1 ? text : text.slice(0, firstNl);

  if (isHeadingLine(firstLine)) {
    return tryExtractHeading(text);
  }

  if (isBlockquoteLine(firstLine)) {
    return tryExtractBlockquote(text);
  }

  if (isListLine(firstLine)) {
    return tryExtractList(text);
  }

  return tryExtractParagraph(text);
}

/**
 * 表示済み raw 全文を確定ブロック列と未確定末尾に分割する。
 */
export function partitionCompletedBlocks(raw: string): PartitionResult {
  if (raw === "") {
    return { blocks: [], active: "" };
  }

  const blocks: string[] = [];
  let offset = 0;

  while (offset < raw.length) {
    const slice = raw.slice(offset);
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
 * 返信完了時: 残りの active も最終ブロックとして確定する。
 */
export function partitionAllBlocksOnComplete(raw: string): string[] {
  const { blocks, active } = partitionCompletedBlocks(raw);
  if (active === "") {
    return blocks;
  }
  return [...blocks, active];
}

/**
 * 既存ブロック配列を新しい markdown 配列へ最小更新で同期する。
 * 先頭一致分は id / 参照を維持し、再レンダーを防ぐ。
 */
export function syncFinalizedBlocks(
  previous: FinalizedMarkdownBlock[],
  nextSources: string[],
  createId: () => string,
): FinalizedMarkdownBlock[] {
  let unchangedPrefix = 0;
  const limit = Math.min(previous.length, nextSources.length);
  while (
    unchangedPrefix < limit &&
    previous[unchangedPrefix].source === nextSources[unchangedPrefix]
  ) {
    unchangedPrefix += 1;
  }

  if (
    unchangedPrefix === nextSources.length &&
    previous.length === nextSources.length
  ) {
    return previous;
  }

  const next: FinalizedMarkdownBlock[] = previous.slice(0, unchangedPrefix);
  for (let i = unchangedPrefix; i < nextSources.length; i += 1) {
    next.push({
      id: createId(),
      source: nextSources[i],
    });
  }
  return next;
}
