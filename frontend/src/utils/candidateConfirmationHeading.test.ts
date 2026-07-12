import { describe, expect, it } from "vitest";
import { getCandidateConfirmationHeading } from "./candidateConfirmationHeading.ts";

describe("getCandidateConfirmationHeading", () => {
  it("named_size, 1件", () => {
    expect(getCandidateConfirmationHeading("named_size", 1)).toBe(
      "このサイズで合っていますか？",
    );
  });

  it("named_size, 2件", () => {
    expect(getCandidateConfirmationHeading("named_size", 2)).toBe(
      "どのサイズを食べましたか？",
    );
  });

  it("serving_size, 1件", () => {
    expect(getCandidateConfirmationHeading("serving_size", 1)).toBe(
      "この盛りサイズで合っていますか？",
    );
  });

  it("serving_size, 2件以上", () => {
    expect(getCandidateConfirmationHeading("serving_size", 3)).toBe(
      "どの盛りサイズを食べましたか？",
    );
  });

  it("weight, 1件", () => {
    expect(getCandidateConfirmationHeading("weight", 1)).toBe(
      "この内容量で合っていますか？",
    );
  });

  it("weight, 2件以上", () => {
    expect(getCandidateConfirmationHeading("weight", 2)).toBe(
      "どの内容量の商品ですか？",
    );
  });

  it("volume, 1件", () => {
    expect(getCandidateConfirmationHeading("volume", 1)).toBe(
      "この容量で合っていますか？",
    );
  });

  it("volume, 2件以上", () => {
    expect(getCandidateConfirmationHeading("volume", 4)).toBe(
      "どの容量の商品ですか？",
    );
  });

  it("unknown, 1件", () => {
    expect(getCandidateConfirmationHeading("unknown", 1)).toBe(
      "こちらの商品で合っていますか？",
    );
  });

  it("multiple, 1件", () => {
    expect(getCandidateConfirmationHeading("multiple", 1)).toBe(
      "こちらの商品で合っていますか？",
    );
  });

  it("unknown, 2件以上", () => {
    expect(getCandidateConfirmationHeading("unknown", 2)).toBe(
      "食べたものを選んでください",
    );
  });

  it("未知の dimension, 1件", () => {
    expect(getCandidateConfirmationHeading("none", 1)).toBe(
      "こちらの内容で合っていますか？",
    );
  });

  it("未知の dimension, 2件以上", () => {
    expect(getCandidateConfirmationHeading("none", 2)).toBe(
      "食べたものを選んでください",
    );
  });

  it("null dimension でもクラッシュしない", () => {
    expect(getCandidateConfirmationHeading(null, 1)).toBe(
      "こちらの内容で合っていますか？",
    );
  });
});
