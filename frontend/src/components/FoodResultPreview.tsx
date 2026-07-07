import type { FoodSearchResult } from "../types/foodSearch.ts";
import { FoodEstimateCard } from "./FoodEstimateCard.tsx";
import { FoodSearchResultCard } from "./FoodSearchResultCard.tsx";
import {
  shouldUseEstimateCard,
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
}

export function FoodResultPreview({
  result,
  mode,
  caloriesEdited,
  onEdit,
  onAdd,
  onSearchWeb,
}: FoodResultPreviewProps) {
  if (shouldUseEstimateCard(result, mode)) {
    return (
      <FoodEstimateCard
        result={result}
        variant={
          mode === "history" ? "history" : mode === "detail" ? "detail" : "estimate"
        }
        caloriesEdited={caloriesEdited}
        onEdit={onEdit ?? (() => {})}
        onAdd={onAdd ?? (() => {})}
        onSearchWeb={onSearchWeb}
      />
    );
  }

  return (
    <FoodSearchResultCard
      result={result}
      mode={mode === "register" ? "register" : "detail"}
      onAdd={mode === "register" ? onAdd : undefined}
    />
  );
}
