import type { FoodSearchResult } from "../types/foodSearch.ts";
import { FoodEstimateCard } from "./FoodEstimateCard.tsx";
import {
  getFoodEstimateCardVariant,
  type FoodResultDisplayMode,
} from "../utils/foodResultDisplay.ts";

export type { FoodResultDisplayMode };

interface FoodResultPreviewProps {
  result: FoodSearchResult;
  mode: FoodResultDisplayMode;
  caloriesEdited?: boolean;
  /** @deprecated 操作はカード外。後方互換のため残す */
  onEdit?: () => void;
  /** @deprecated 操作はカード外。後方互換のため残す */
  onAdd?: () => void;
  /** @deprecated 操作はカード外。後方互換のため残す */
  onSearchWeb?: () => void;
}

export function FoodResultPreview({
  result,
  mode,
  caloriesEdited,
}: FoodResultPreviewProps) {
  return (
    <FoodEstimateCard
      result={result}
      variant={getFoodEstimateCardVariant(result, mode)}
      caloriesEdited={caloriesEdited}
    />
  );
}
