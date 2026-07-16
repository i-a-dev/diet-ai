import { describe, expect, it } from "vitest";
import {
  partitionAllBlocksOnComplete,
  partitionCompletedBlocks,
  syncFinalizedBlocks,
} from "./streamBlockParser.ts";

describe("partitionCompletedBlocks", () => {
  it("keeps incomplete bold in active", () => {
    const result = partitionCompletedBlocks("こんにちは**太");
    expect(result.blocks).toEqual([]);
    expect(result.active).toContain("こんにちは");
  });

  it("does not finalize bold mid-paragraph even when closed", () => {
    const result = partitionCompletedBlocks("今日は**順調**です");
    expect(result.blocks).toEqual([]);
    expect(result.active).toBe("今日は**順調**です");
  });

  it("finalizes paragraph only after blank line", () => {
    expect(partitionCompletedBlocks("一行目\n").blocks).toEqual([]);
    const done = partitionCompletedBlocks("一行目\n\n");
    expect(done.blocks).toHaveLength(1);
    expect(done.blocks[0]).toBe("一行目\n\n");
    expect(done.active).toBe("");
  });

  it("finalizes paragraph with bold after blank line", () => {
    const done = partitionCompletedBlocks("今日は**順調**です\n\n次");
    expect(done.blocks).toHaveLength(1);
    expect(done.blocks[0]).toBe("今日は**順調**です\n\n");
    expect(done.active).toBe("次");
  });

  it("keeps list until blank line", () => {
    expect(partitionCompletedBlocks("- 朝食\n- 昼食\n").blocks).toEqual([]);
    expect(partitionCompletedBlocks("- 朝食\n- 昼食\n\n").blocks).toHaveLength(
      1,
    );
  });

  it("keeps open code fence in active", () => {
    expect(partitionCompletedBlocks("```js\nconst a = 1\n").blocks).toEqual([]);
    expect(
      partitionCompletedBlocks("```js\nconst a = 1\n```\n").blocks,
    ).toHaveLength(1);
  });

  it("does not finalize code fence without trailing newline", () => {
    expect(
      partitionCompletedBlocks("```js\nconst a = 1\n```").blocks,
    ).toEqual([]);
  });

  it("finalizes heading after newline", () => {
    expect(partitionCompletedBlocks("## 見出し").blocks).toEqual([]);
    expect(partitionCompletedBlocks("## 見出し\n").blocks).toHaveLength(1);
  });

  it("finalizes blockquote on blank line", () => {
    expect(partitionCompletedBlocks("> 引用\n").blocks).toEqual([]);
    expect(partitionCompletedBlocks("> 引用\n\n").blocks).toHaveLength(1);
  });
});

describe("partitionAllBlocksOnComplete", () => {
  it("finalizes trailing active paragraph", () => {
    expect(partitionAllBlocksOnComplete("最後の段落")).toEqual(["最後の段落"]);
  });

  it("keeps already finalized blocks and appends active", () => {
    expect(partitionAllBlocksOnComplete("一段落\n\n残り")).toEqual([
      "一段落\n\n",
      "残り",
    ]);
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
    expect(second[1].source).toBe("B\n\n");
  });
});
