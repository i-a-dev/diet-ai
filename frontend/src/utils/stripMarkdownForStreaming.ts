/**
 * ストリーミング表示用: Markdown 記号を除去して本文だけを返す。
 * 未完成の記法にも対応し、記号がユーザーに一瞬でも見えないようにする。
 */

function stripCompleteCodeFences(text: string): string {
  return text.replace(/```[^\n]*\n?([\s\S]*?)```(?:\n)?/g, (_, code: string) =>
    code.replace(/\n$/, ""),
  );
}

/** 未閉じの ``` 以降はオープナー行を捨て、中身だけ残す */
function stripOpenCodeFence(text: string): string {
  const matches = text.match(/```/g);
  if (!matches || matches.length % 2 === 0) {
    return text;
  }
  const openAt = text.lastIndexOf("```");
  const before = text.slice(0, openAt);
  let after = text.slice(openAt + 3);
  // 同一行の言語指定を除去（例: js / typescript）
  after = after.replace(/^[^\n]*\n?/, "");
  return before + after;
}

function stripBlockLine(line: string): string {
  if (/^\s{0,3}(#{1,6})\s*$/.test(line)) {
    return "";
  }

  let match = line.match(/^\s{0,3}(#{1,6})\s+(.*)$/);
  if (match) {
    return match[2];
  }

  match = line.match(/^\s{0,3}>\s?(.*)$/);
  if (match) {
    return match[1];
  }

  // リスト記号のみ / 記号+空白のみ
  if (/^\s{0,3}([-*+]|\d+\.)\s*$/.test(line)) {
    return "";
  }

  match = line.match(/^\s{0,3}[-*+]\s+(.*)$/);
  if (match) {
    return match[1];
  }

  match = line.match(/^\s{0,3}\d+\.\s+(.*)$/);
  if (match) {
    return match[1];
  }

  if (/^\s{0,3}(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
    return "";
  }

  return line;
}

function stripInlineMarkdown(text: string): string {
  let s = text;

  s = s.replace(/!\[([^\]]*)\]\([^)]*\)/g, "$1");
  s = s.replace(/\[([^\]]+)\]\([^)]*\)/g, "$1");

  // 太字・取り消し・コードを先に処理し、単一 * / _ の斜体と混同しない
  s = s.replace(/\*\*([^*]+)\*\*/g, "$1");
  s = s.replace(/__([^_]+)__/g, "$1");
  s = s.replace(/~~([^~]+)~~/g, "$1");
  s = s.replace(/`([^`]+)`/g, "$1");

  // 未完了の太字など（末尾）
  s = s.replace(/\*\*([^*]*)$/g, "$1");
  s = s.replace(/__([^_]*)$/g, "$1");
  s = s.replace(/~~([^~]*)$/g, "$1");
  s = s.replace(/`([^`]*)$/g, "$1");

  // 完了した斜体（*text* / _text_）
  s = s.replace(/\*([^*\n]+)\*/g, "$1");
  s = s.replace(/_([^_\n]+)_/g, "$1");

  // 未完了の斜体（末尾）
  s = s.replace(/\*([^*\n]*)$/g, "$1");
  s = s.replace(/_([^_\n]*)$/g, "$1");

  // 残った孤立マーカー
  s = s.replace(/\*\*/g, "");
  s = s.replace(/__/g, "");
  s = s.replace(/~~/g, "");
  s = s.replace(/`/g, "");
  s = s.replace(/\*/g, "");

  return s;
}

export function stripMarkdownForStreaming(raw: string): string {
  if (raw === "") {
    return "";
  }

  let text = stripCompleteCodeFences(raw);
  text = stripOpenCodeFence(text);

  const lines = text.split("\n");
  text = lines.map(stripBlockLine).join("\n");
  text = stripInlineMarkdown(text);

  return text;
}
