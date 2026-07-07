const STOP_WORDS = [
  "カロリー",
  "カロ",
  "kcal",
  "栄養成分",
  "栄養",
  "成分表",
  "エネルギー",
  "熱量",
];

const GENERIC_TERMS = new Set([
  "パン",
  "チョコ",
  "チョコレート",
  "ラーメン",
  "うどん",
  "そば",
  "ご飯",
  "おにぎり",
  "サラダ",
  "スープ",
  "ジュース",
  "お茶",
  "コーヒー",
  "牛乳",
  "ヨーグルト",
]);

const HOMEMADE_PATTERNS = [
  "炒め",
  "煮",
  "焼き",
  "揚げ",
  "蒸し",
  "和え",
  "カレー",
  "シチュー",
  "鍋",
  "丼",
  "定食",
  "弁当",
  "昨日",
  "残り",
  "作った",
  "手作り",
  "自炊",
  "とキャベツ",
  "と玉ねぎ",
  "と人参",
];

export function normalizeAliasQuery(query: string): string {
  let text = query.trim().toLowerCase().replace(/\u3000/g, " ").normalize("NFKC");

  for (const word of STOP_WORDS) {
    text = text.replace(new RegExp(word, "gi"), " ");
  }

  return text.replace(/\s+/g, " ").trim();
}

export function isAliasQueryTooShort(rawQuery: string): boolean {
  return rawQuery.trim().length < 3;
}

export function isGenericAliasTerm(rawQuery: string): boolean {
  const normalized = normalizeAliasQuery(rawQuery);
  return normalized === "" || GENERIC_TERMS.has(normalized);
}

export function looksHomemadeAlias(rawQuery: string): boolean {
  const normalized = normalizeAliasQuery(rawQuery);
  if (normalized === "") return false;

  if (HOMEMADE_PATTERNS.some((pattern) => normalized.includes(pattern))) {
    return true;
  }

  return /[と、]\s*\S/u.test(normalized) && normalized.length >= 6;
}

export function isNearExactAliasMatch(
  rawQuery: string,
  foodName: string,
): boolean {
  const query = normalizeAliasQuery(rawQuery);
  const name = normalizeAliasQuery(foodName);
  if (query === "" || name === "") return false;
  if (query === name) return true;

  if (name.startsWith(query) && name.length - query.length <= 3) {
    return true;
  }

  if (query.startsWith(name) && query.length - name.length <= 3) {
    return true;
  }

  return false;
}

export function shouldSaveFoodAlias(params: {
  rawQuery: string;
  foodName: string;
  source: string;
  caloriesEdited?: boolean;
}): boolean {
  if (params.caloriesEdited) {
    return false;
  }

  if (isAliasQueryTooShort(params.rawQuery)) {
    return false;
  }

  if (looksHomemadeAlias(params.rawQuery)) {
    return false;
  }

  if (isNearExactAliasMatch(params.rawQuery, params.foodName)) {
    return false;
  }

  const eligibleSources = new Set([
    "user_selected",
    "ai_web_search_selected",
    "ai_web_search",
    "brave_html",
    "claude_web_search",
    "local_db",
    "alias_db",
    "user_registered",
    "fatsecret",
    "open_food_facts",
  ]);

  return eligibleSources.has(params.source);
}

export function isAmbiguousAliasQuery(rawQuery: string): boolean {
  if (isGenericAliasTerm(rawQuery)) {
    return true;
  }

  return normalizeAliasQuery(rawQuery).length <= 4;
}

export function resolveAliasSourceForSave(
  source: string,
): "user_selected" | "ai_web_search_selected" | "local_db_selected" {
  if (source === "local_db" || source === "alias_db") {
    return "local_db_selected";
  }

  if (
    source === "ai_web_search" ||
    source === "brave_html" ||
    source === "claude_web_search"
  ) {
    return "ai_web_search_selected";
  }

  return "user_selected";
}
