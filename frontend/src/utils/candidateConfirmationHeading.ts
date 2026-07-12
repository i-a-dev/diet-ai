import type { VariantDimension } from "../types/foodSearch.ts";

export function getCandidateConfirmationHeading(
  variantDimension: VariantDimension | string | null | undefined,
  candidateCount: number,
): string {
  const hasSingleCandidate = candidateCount === 1;

  switch (variantDimension) {
    case "named_size":
      return hasSingleCandidate
        ? "このサイズで合っていますか？"
        : "どのサイズを食べましたか？";

    case "serving_size":
      return hasSingleCandidate
        ? "この盛りサイズで合っていますか？"
        : "どの盛りサイズを食べましたか？";

    case "weight":
      return hasSingleCandidate
        ? "この内容量で合っていますか？"
        : "どの内容量の商品ですか？";

    case "volume":
      return hasSingleCandidate
        ? "この容量で合っていますか？"
        : "どの容量の商品ですか？";

    case "unknown":
    case "multiple":
      return hasSingleCandidate
        ? "こちらの商品で合っていますか？"
        : "食べたものを選んでください";

    default:
      return hasSingleCandidate
        ? "こちらの内容で合っていますか？"
        : "食べたものを選んでください";
  }
}
