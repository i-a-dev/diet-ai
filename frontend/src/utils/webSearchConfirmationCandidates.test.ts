import { describe, expect, it } from "vitest";
import type { FoodSearchCandidate } from "../types/foodSearch.ts";
import {
  filterVisibleWebSearchCandidates,
  toWebConfirmationCandidates,
} from "./webSearchConfirmationCandidates.ts";

function makeCandidate(
  overrides: Partial<FoodSearchCandidate> = {},
): FoodSearchCandidate {
  return {
    product_name: "テスト商品",
    kcal: 100,
    source: "brave_html",
    identity_confidence: "high",
    ...overrides,
  };
}

describe("filterVisibleWebSearchCandidates", () => {
  it("kcal が無い候補を除外する", () => {
    const visible = filterVisibleWebSearchCandidates([
      makeCandidate({ kcal: 0 }),
      makeCandidate({ kcal: 321, product_name: "有効" }),
    ]);

    expect(visible).toHaveLength(1);
    expect(visible[0].product_name).toBe("有効");
  });

  it("重複候補を1件にまとめる", () => {
    const visible = filterVisibleWebSearchCandidates([
      makeCandidate({ kcal: 321, variant_label: "50g" }),
      makeCandidate({ kcal: 321, variant_label: "50g" }),
    ]);

    expect(visible).toHaveLength(1);
  });

  it("重複排除後に1件なら1件", () => {
    const visible = toWebConfirmationCandidates([
      makeCandidate({ kcal: 321, variant_label: "50g" }),
      makeCandidate({ kcal: 321, variant_label: "50g" }),
      makeCandidate({ kcal: 0 }),
    ]);

    expect(visible).toHaveLength(1);
  });
});

describe("toWebConfirmationCandidates", () => {
  it("サイズバッジを付ける", () => {
    const visible = toWebConfirmationCandidates([
      makeCandidate({
        product_name: "マックフライポテト",
        variant_label: "Mサイズ",
        kcal: 410,
      }),
    ]);

    expect(visible[0]?.badge).toBe("Mサイズ");
    expect(visible[0]?.kcal).toBe(410);
  });

  it("ブランド名とHTML商品名をラベルに含める", () => {
    const visible = toWebConfirmationCandidates([
      makeCandidate({
        brand: "ナッシュ",
        base_product_name: "和風おろしハンバーグ",
        product_name: "和風おろしハンバーグ",
        kcal: 321,
        source_url: "https://nosh.jp/menu/detail/469",
      }),
    ]);

    expect(visible[0]?.label).toBe("ナッシュ 和風おろしハンバーグ");
    expect(visible[0]?.sourceUrl).toBe("https://nosh.jp/menu/detail/469");
    expect(visible[0]?.webCandidate?.source_url).toBe(
      "https://nosh.jp/menu/detail/469",
    );
  });
});
