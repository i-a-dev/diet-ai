export type FoodSource =
  | "regex"
  | "fatsecret"
  | "open_food_facts"
  | "local_db"
  | "claude_estimate"
  | "ai_web_search"
  | "brave_html"
  | "claude_web_search"
  | "user_registered";

export type SearchConfidence = "high" | "medium" | "low";

export type SearchState =
  | "idle"
  | "searching"
  | "found"
  | "estimated"
  | "from_history"
  | "low_confidence_estimate"
  | "web_searching"
  | "web_found"
  | "needs_confirmation"
  | "completed"
  | "error";

export interface FoodSearchCandidate {
  product_name: string;
  brand?: string;
  kcal: number;
  source_url?: string | null;
  source: "brave_html" | "claude_web_search" | "ai_web_search";
  identity_confidence: SearchConfidence;
}

export interface FoodResultItem {
  name: string;
  amount: number;
  unit: string;
  calories: number;
}

export interface FoodSearchResult {
  id: string;
  name: string;
  displayName: string;
  amount: number;
  unit: string;
  calories: number;
  protein: number | null;
  fat: number | null;
  carbs: number | null;
  source: FoodSource;
  confidence: SearchConfidence;
  isEstimated: boolean;
  barcode?: string | null;
  brandName?: string | null;
  rawInput: string;
  selectedProductName?: string | null;
  sourceUrl?: string | null;
  identityConfidence?: SearchConfidence | null;
  items?: FoodResultItem[];
  caloriesEdited?: boolean;
}

export interface FoodSearchStep {
  key:
    | "regex_extracting"
    | "fatsecret_searching"
    | "open_food_facts_searching"
    | "local_db_searching"
    | "claude_estimating"
    | "waiting_user_choice"
    | "ai_web_searching";
  label: string;
  status: "pending" | "active" | "done" | "skipped";
}

export interface FoodSearchProgress {
  state: SearchState;
  steps: FoodSearchStep[];
  result: FoodSearchResult | null;
  candidates?: FoodSearchCandidate[];
  message?: string;
}
