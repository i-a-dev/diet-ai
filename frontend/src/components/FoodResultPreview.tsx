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
  onEdit?: () => void;
  onAdd?: () => void;
  onSearchWeb?: () => void;
  onReestimateWithAi?: () => void;
}

export function FoodResultPreview({
  result,
  mode,
  caloriesEdited,
  onEdit,
  onAdd,
  onSearchWeb,
  onReestimateWithAi,
}: FoodResultPreviewProps) {
  return (
    <FoodEstimateCard
      result={result}
      variant={getFoodEstimateCardVariant(result, mode)}
      caloriesEdited={caloriesEdited}
      onEdit={onEdit ?? (() => {})}
      onAdd={onAdd ?? (() => {})}
      onSearchWeb={onSearchWeb}
      onReestimateWithAi={onReestimateWithAi}
    />
  );
}
