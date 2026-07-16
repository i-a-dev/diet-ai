import { describe, expect, it } from "vitest";
import {
  createStreamingTextFilter,
  stripMarkdownForStreaming,
} from "./streamingTextFilter.ts";

function feed(raw: string): string {
  const filter = createStreamingTextFilter();
  let out = "";
  for (const unit of Array.from(raw)) {
    out += filter.push(unit);
  }
  return out;
}

describe("createStreamingTextFilter", () => {
  it("strips complete bold markers incrementally", () => {
    expect(feed("**今日は順調です**")).toBe("今日は順調です");
  });

  it("hides incomplete bold while streaming", () => {
    expect(feed("**今日は")).toBe("今日は");
    expect(feed("文字**")).toBe("文字");
    expect(feed("**")).toBe("");
  });

  it("strips list markers without showing hyphen or numbers", () => {
    expect(feed("- 朝食\n- 昼食\n- 夕食")).toBe("朝食\n昼食\n夕食");
    expect(feed("1. 朝食\n2. 昼食")).toBe("朝食\n昼食");
    expect(feed("-")).toBe("");
    expect(feed("- ")).toBe("");
  });

  it("strips headings and blockquotes", () => {
    expect(feed("## 見出し")).toBe("見出し");
    expect(feed("##")).toBe("");
    expect(feed("> 引用文")).toBe("引用文");
  });

  it("strips fenced code markers and keeps content", () => {
    expect(feed("```js\nconst a = 1\n```")).toBe("const a = 1\n");
    expect(feed("```js\nconst a = 1\n")).toBe("const a = 1\n");
    expect(feed("```")).toBe("");
  });

  it("strips inline code markers", () => {
    expect(feed("`kcal`")).toBe("kcal");
    expect(feed("`kc")).toBe("kc");
  });

  it("keeps plain prose and newlines", () => {
    expect(feed("こんにちは\n\n今日も頑張りましょう")).toBe(
      "こんにちは\n\n今日も頑張りましょう",
    );
  });

  it("is append-only across pushes", () => {
    const filter = createStreamingTextFilter();
    expect(filter.push("あ")).toBe("あ");
    expect(filter.push("\n")).toBe("\n");
    expect(filter.push("-")).toBe("");
    expect(filter.push(" ")).toBe("");
    expect(filter.push("い")).toBe("い");
  });
});

describe("stripMarkdownForStreaming compat", () => {
  it("matches incremental filter for common cases", () => {
    expect(stripMarkdownForStreaming("**今日は順調です**")).toBe(
      "今日は順調です",
    );
    expect(stripMarkdownForStreaming("- 朝食\n- 昼食")).toBe("朝食\n昼食");
  });
});
