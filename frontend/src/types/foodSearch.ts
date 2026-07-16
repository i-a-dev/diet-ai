export type FoodSource =
  | "regex"
  | "alias_db"
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
  | "needs_alias_confirmation"
  | "needs_local_db_confirmation"
  | "completed"
  | "error";

export interface FoodSearchCandidate {
  product_name: string;
  brand?: string;
  kcal: number;
  source_url?: string | null;
  source_title?: string | null;
  source: "brave_html" | "claude_web_search" | "ai_web_search" | "alias_db";
  identity_confidence: SearchConfidence;
  base_product_name?: string;
  variant_label?: string;
  variant_confidence?: SearchConfidence;
  variant_dimension?: string;
  serving_weight_g?: number | null;
  package_size?: string | null;
  alias_id?: number;
  verification_confidence?: SearchConfidence;
  evidence_text?: string | null;
  source_type?: string;
}

export type VariantDimension =
  | "named_size"
  | "serving_size"
  | "weight"
  | "volume"
  | "count"
  | "portion"
  | "container"
  | "multiple"
  | "none"
  | "unknown";

export interface AliasSearchCandidate {
  aliasId: number;
  selectionCount: number;
  rejectedCount: number;
  confidenceScore: number;
  lastSelectedAt: string | null;
  source: string;
  food: {
    id: number;
    displayName: string;
    name: string;
    amount: number;
    unit: string;
    calories: number;
    source: string;
    rawInput: string | null;
    sourceUrl: string | null;
    variantLabel?: string | null;
    baseProductName?: string | null;
    servingWeightG?: number | null;
  };
}

export interface LocalDbSearchCandidate {
  foodId: number;
  name: string;
  calories: number;
  source: "local_db";
  baseProductName: string;
  variantLabel: string;
  confidence: SearchConfidence;
  amount: number;
  unit: string;
  rawInput?: string | null;
  sourceUrl?: string | null;
  servingWeightG?: number | null;
  packageSize?: string | null;
}

export interface FoodConfirmationCandidate {
  key: string;
  label: string;
  kcal: number;
  badge?: string | null;
  sourceUrl?: string | null;
  aliasId?: number;
  webCandidate?: FoodSearchCandidate;
  localDbCandidate?: LocalDbSearchCandidate;
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
  fiber?: number | null;
  sodium?: number | null;
  source: FoodSource;
  confidence: SearchConfidence;
  isEstimated: boolean;
  barcode?: string | null;
  brandName?: string | null;
  rawInput: string;
  selectedProductName?: string | null;
  selectedFoodId?: number | null;
  aliasId?: number | null;
  originalSource?: string | null;
  sourceUrl?: string | null;
  identityConfidence?: SearchConfidence | null;
  items?: FoodResultItem[];
  caloriesEdited?: boolean;
}

export interface FoodSearchStep {
  key:
    | "regex_extracting"
    | "alias_db_searching"
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
  aliasCandidates?: AliasSearchCandidate[];
  localDbCandidates?: LocalDbSearchCandidate[];
  confirmationReason?: "variant_ambiguous" | "identity_ambiguous" | null;
  variantDimension?: VariantDimension | string;
  allowManualVariant?: boolean;
  allowEstimatedAdd?: boolean;
  webSearchPhase?: "planning" | "searching_pages" | "extracting_variants";
  selectedCandidateKey?: string | null;
  message?: string;
}
