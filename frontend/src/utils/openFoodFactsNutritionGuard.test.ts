import { describe, expect, it } from "vitest";
import {
  hasImpossibleCalorieDensity,
  hasInconsistentKcalAndKj,
  hasInconsistentKcalAndMacros,
  isOpenFoodFactsNutritionPlausible,
  MAX_PLAUSIBLE_KCAL_PER_100G,
} from "./openFoodFactsNutritionGuard.ts";

describe("hasImpossibleCalorieDensity", () => {
  it("純脂肪上限を超える密度を検出する", () => {
    // ベースブレッド不良データ相当: 758 kcal / 77g ≈ 984 kcal/100g
    expect(hasImpossibleCalorieDensity(758, 77, "g")).toBe(true);
  });

  it("上限ちょうどは許容する", () => {
    expect(
      hasImpossibleCalorieDensity(MAX_PLAUSIBLE_KCAL_PER_100G, 100, "g"),
    ).toBe(false);
  });

  it("通常の食品密度は通す", () => {
    expect(hasImpossibleCalorieDensity(265, 77, "g")).toBe(false);
  });

  it("食単位など質量換算できない単位は密度チェックしない", () => {
    expect(hasImpossibleCalorieDensity(758, 1, "食")).toBe(false);
  });
});

describe("hasInconsistentKcalAndKj", () => {
  it("kcal と kJ が大きく食い違うと true", () => {
    // OFF 不良データ: 758 kcal vs 1220 kJ (≈291 kcal)
    expect(hasInconsistentKcalAndKj(758, 1220)).toBe(true);
  });

  it("換算が一致していれば false", () => {
    expect(hasInconsistentKcalAndKj(291, 1220)).toBe(false);
  });

  it("片方しかなければ false", () => {
    expect(hasInconsistentKcalAndKj(291, null)).toBe(false);
    expect(hasInconsistentKcalAndKj(undefined, 1220)).toBe(false);
  });
});

describe("hasInconsistentKcalAndMacros", () => {
  it("マクロ推定と大きく食い違うと true", () => {
    // P13.6 F9.1 C35.4 → 約278 kcal、表示758
    expect(hasInconsistentKcalAndMacros(758, 13.6, 9.1, 35.4)).toBe(true);
  });

  it("マクロ推定と近い値は通す", () => {
    expect(hasInconsistentKcalAndMacros(278, 13.6, 9.1, 35.4)).toBe(false);
  });
});

describe("isOpenFoodFactsNutritionPlausible", () => {
  it("ベースブレッド不良データ相当を棄却する", () => {
    expect(
      isOpenFoodFactsNutritionPlausible({
        calories: 758,
        amount: 77,
        unit: "g",
        energyKcalServing: 758,
        energyKjServing: 1220,
        energyKcal100g: 984.6,
        energyKj100g: 1579,
        proteinG: 13.6,
        fatG: 9.1,
        carbsG: 35.4,
      }),
    ).toBe(false);
  });

  it("妥当な栄養値は通す", () => {
    expect(
      isOpenFoodFactsNutritionPlausible({
        calories: 265,
        amount: 77,
        unit: "g",
        energyKcalServing: 265,
        energyKjServing: 1109,
        energyKcal100g: 344,
        energyKj100g: 1440,
        proteinG: 13.6,
        fatG: 9.1,
        carbsG: 35.4,
      }),
    ).toBe(true);
  });
});
