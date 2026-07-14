import { describe, expect, it } from "vitest";
import {
  partitionStreamMarkdown,
  sanitizeActivePlainText,
  syncFinalizedBlocks,
} from "./streamMarkdownPartition.ts";

describe("partitionStreamMarkdown", () => {
  it("keeps incomplete bold in active plain", () => {
    const result = partitionStreamMarkdown("こんにちは**太");
    expect(result.blocks).toEqual([]);
    expect(result.active).toContain("こんにちは");
  });

  it("finalizes paragraph only after blank line", () => {
    expect(partitionStreamMarkdown("一行目\n").blocks).toEqual([]);
    const done = partitionStreamMarkdown("一行目\n\n");
    expect(done.blocks).toHaveLength(1);
    expect(done.active).toBe("");
  });

  it("keeps list until blank line", () => {
    expect(partitionStreamMarkdown("- 朝食\n- 昼食\n").blocks).toEqual([]);
    expect(partitionStreamMarkdown("- 朝食\n- 昼食\n\n").blocks).toHaveLength(1);
  });

  it("keeps open code fence in active", () => {
    expect(partitionStreamMarkdown("```js\nconst a = 1\n").blocks).toEqual([]);
    expect(
      partitionStreamMarkdown("```js\nconst a = 1\n```\n").blocks,
    ).toHaveLength(1);
  });

  it("finalizes heading after newline", () => {
    expect(partitionStreamMarkdown("## 見出し").blocks).toEqual([]);
    expect(partitionStreamMarkdown("## 見出し\n").blocks).toHaveLength(1);
  });
});

describe("sanitizeActivePlainText", () => {
  it("hides trailing incomplete bold markers", () => {
    expect(sanitizeActivePlainText("文字**")).toBe("文字");
  });
});

describe("syncFinalizedBlocks", () => {
  it("reuses previous block identities for unchanged prefix", () => {
    let n = 0;
    const createId = () => `id-${++n}`;
    const first = syncFinalizedBlocks([], ["A\n\n"], createId);
    const second = syncFinalizedBlocks(first, ["A\n\n", "B\n\n"], createId);
    expect(second[0]).toBe(first[0]);
    expect(second).toHaveLength(2);
    expect(second[1].markdown).toBe("B\n\n");
  });
});
