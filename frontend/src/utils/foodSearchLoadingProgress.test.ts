import { describe, expect, it } from "vitest";
import type { FoodSearchStep } from "../types/foodSearch.ts";
import {
  FOOD_SEARCH_LOADING_HINT,
  resolveFoodSearchLoadingState,
} from "./foodSearchLoadingProgress.ts";

function makeSteps(
  activeKey?: FoodSearchStep["key"],
  doneKeys: FoodSearchStep["key"][] = [],
): FoodSearchStep[] {
  const keys: FoodSearchStep["key"][] = [
    "regex_extracting",
    "alias_db_searching",
    "local_db_searching",
    "fatsecret_searching",
    "open_food_facts_searching",
    "claude_estimating",
    "waiting_user_choice",
    "ai_web_searching",
  ];

  return keys.map((key) => ({
    key,
    label: key,
    status:
      key === activeKey ? "active" : doneKeys.includes(key) ? "done" : "pending",
  }));
}

describe("resolveFoodSearchLoadingState", () => {
  it("入力解析時に10%になる", () => {
    const state = resolveFoodSearchLoadingState(
      makeSteps("regex_extracting"),
    );
    expect(state.progressPercent).toBe(10);
    expect(state.statusMessage).toBe("入力内容を確認しています");
  });

  it("Alias DB 時に30%になる", () => {
    const state = resolveFoodSearchLoadingState(
      makeSteps("alias_db_searching", ["regex_extracting"]),
    );
    expect(state.progressPercent).toBe(30);
    expect(state.statusMessage).toBe("よく使われる食品から探しています");
  });

  it("Local DB 時に30%になる", () => {
    const state = resolveFoodSearchLoadingState(
      makeSteps("local_db_searching", [
        "regex_extracting",
        "alias_db_searching",
      ]),
    );
    expect(state.progressPercent).toBe(30);
    expect(state.statusMessage).toBe("よく使われる食品から探しています");
  });

  it("FatSecret 時に55%になる", () => {
    const state = resolveFoodSearchLoadingState(
      makeSteps("fatsecret_searching", [
        "regex_extracting",
        "alias_db_searching",
        "local_db_searching",
      ]),
    );
    expect(state.progressPercent).toBe(55);
    expect(state.statusMessage).toBe("食品データベースを検索しています");
  });

  it("Open Food Facts 時に75%になる", () => {
    const state = resolveFoodSearchLoadingState(
      makeSteps("open_food_facts_searching", [
        "regex_extracting",
        "alias_db_searching",
        "local_db_searching",
        "fatsecret_searching",
      ]),
    );
    expect(state.progressPercent).toBe(75);
    expect(state.statusMessage).toBe("商品情報を確認しています");
  });

  it("Claude推定時に90%になる", () => {
    const state = resolveFoodSearchLoadingState(
      makeSteps("claude_estimating", [
        "regex_extracting",
        "alias_db_searching",
        "local_db_searching",
        "fatsecret_searching",
        "open_food_facts_searching",
      ]),
    );
    expect(state.progressPercent).toBe(90);
    expect(state.statusMessage).toBe(
      "見つからないため、AIで目安を計算しています",
    );
  });

  it("ステップを飛ばしても進捗が後退しない", () => {
    const afterFatSecret = resolveFoodSearchLoadingState(
      makeSteps("fatsecret_searching", [
        "regex_extracting",
        "alias_db_searching",
        "local_db_searching",
      ]),
      55,
    );
    // 何らかの理由で alias に戻っても 55 を維持
    const regressAttempt = resolveFoodSearchLoadingState(
      makeSteps("alias_db_searching", ["regex_extracting"]),
      afterFatSecret.progressPercent,
    );
    expect(regressAttempt.progressPercent).toBe(55);

    // FatSecret を飛ばして OFF へ
    const skipped = resolveFoodSearchLoadingState(
      makeSteps("open_food_facts_searching", [
        "regex_extracting",
        "alias_db_searching",
        "local_db_searching",
        "fatsecret_searching",
      ]),
      30,
    );
    expect(skipped.progressPercent).toBe(75);
  });

  it("補足文言定数が要件どおり", () => {
    expect(FOOD_SEARCH_LOADING_HINT).toBe(
      "商品によっては数秒かかる場合があります",
    );
  });
});
