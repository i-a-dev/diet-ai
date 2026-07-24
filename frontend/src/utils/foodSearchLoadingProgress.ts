import type { FoodSearchStep } from "../types/foodSearch.ts";

export interface FoodSearchLoadingState {
  progressPercent: number;
  statusMessage: string;
}

interface FoodSearchLoadingStepConfig {
  keys: FoodSearchStep["key"][];
  progressPercent: number;
  statusMessage: string;
}

/** 通常検索の待機画面用。実際の step 切替に連動する。 */
export const FOOD_SEARCH_LOADING_STEP_CONFIGS: FoodSearchLoadingStepConfig[] = [
  {
    keys: ["regex_extracting"],
    progressPercent: 10,
    statusMessage: "入力内容を確認しています",
  },
  {
    keys: ["alias_db_searching", "local_db_searching"],
    progressPercent: 30,
    statusMessage: "よく使われる食品から探しています",
  },
  {
    keys: ["fatsecret_searching"],
    progressPercent: 55,
    statusMessage: "食品データベースを検索しています",
  },
  {
    keys: ["open_food_facts_searching"],
    progressPercent: 75,
    statusMessage: "商品情報を確認しています",
  },
  {
    keys: ["claude_estimating"],
    progressPercent: 90,
    statusMessage: "見つからないため、AIで目安を計算しています",
  },
];

export const FOOD_SEARCH_LOADING_HINT =
  "商品によっては数秒かかる場合があります";

function findConfigIndex(key: FoodSearchStep["key"]): number {
  return FOOD_SEARCH_LOADING_STEP_CONFIGS.findIndex((config) =>
    config.keys.includes(key),
  );
}

/**
 * 通常検索ステップから待機画面の進捗・文言を解決する。
 * previousProgressPercent より小さくならない。
 */
export function resolveFoodSearchLoadingState(
  steps: FoodSearchStep[],
  previousProgressPercent = 0,
): FoodSearchLoadingState {
  const activeStep = steps.find((step) => step.status === "active");
  let configIndex = activeStep ? findConfigIndex(activeStep.key) : -1;

  if (configIndex < 0) {
    // アクティブが無い／対象外のとき、完了済みステップの最大位置を使う（スキップ耐性）
    for (let i = steps.length - 1; i >= 0; i -= 1) {
      const step = steps[i];
      if (step.status !== "done") continue;
      const index = findConfigIndex(step.key);
      if (index >= 0) {
        configIndex = index;
        break;
      }
    }
  }

  if (configIndex < 0) {
    return {
      progressPercent: Math.max(previousProgressPercent, 0),
      statusMessage: FOOD_SEARCH_LOADING_STEP_CONFIGS[0].statusMessage,
    };
  }

  const config = FOOD_SEARCH_LOADING_STEP_CONFIGS[configIndex];
  return {
    progressPercent: Math.max(
      previousProgressPercent,
      config.progressPercent,
    ),
    statusMessage: config.statusMessage,
  };
}
