import { describe, expect, it } from "vitest";
import { stripMarkdownForStreaming } from "./stripMarkdownForStreaming.ts";

describe("stripMarkdownForStreaming", () => {
  it("strips complete bold markers", () => {
    expect(stripMarkdownForStreaming("**今日は順調です**")).toBe(
      "今日は順調です",
    );
  });

  it("hides incomplete bold while streaming", () => {
    expect(stripMarkdownForStreaming("**今日は")).toBe("今日は");
    expect(stripMarkdownForStreaming("文字**")).toBe("文字");
    expect(stripMarkdownForStreaming("**")).toBe("");
  });

  it("strips list markers without showing hyphen or numbers", () => {
    expect(stripMarkdownForStreaming("- 朝食\n- 昼食\n- 夕食")).toBe(
      "朝食\n昼食\n夕食",
    );
    expect(stripMarkdownForStreaming("1. 朝食\n2. 昼食")).toBe("朝食\n昼食");
    expect(stripMarkdownForStreaming("-")).toBe("");
    expect(stripMarkdownForStreaming("- ")).toBe("");
  });

  it("strips headings and blockquotes", () => {
    expect(stripMarkdownForStreaming("## 見出し")).toBe("見出し");
    expect(stripMarkdownForStreaming("##")).toBe("");
    expect(stripMarkdownForStreaming("> 引用文")).toBe("引用文");
  });

  it("strips fenced code markers and keeps content", () => {
    expect(stripMarkdownForStreaming("```js\nconst a = 1\n```")).toBe(
      "const a = 1\n",
    );
    expect(stripMarkdownForStreaming("```js\nconst a = 1\n")).toBe(
      "const a = 1\n",
    );
    expect(stripMarkdownForStreaming("```")).toBe("");
  });

  it("strips inline code markers", () => {
    expect(stripMarkdownForStreaming("`kcal`")).toBe("kcal");
    expect(stripMarkdownForStreaming("`kc")).toBe("kc");
  });

  it("keeps plain prose and newlines", () => {
    expect(stripMarkdownForStreaming("こんにちは\n\n今日も頑張りましょう")).toBe(
      "こんにちは\n\n今日も頑張りましょう",
    );
  });
});
