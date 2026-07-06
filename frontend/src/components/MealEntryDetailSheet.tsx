import type { CSSProperties } from "react";
import type { MealItemInput } from "./AddFoodModal.tsx";
import { BottomSheet } from "./BottomSheet.tsx";
import { FoodResultPreview } from "./FoodResultPreview.tsx";
import { mealItemToSearchResult } from "../utils/mealFoodResult.ts";

interface MealEntryDetailSheetProps {
  open: boolean;
  mealTitle: string;
  item: MealItemInput | null;
  isDeleting?: boolean;
  onClose: () => void;
  onDelete: () => void;
}

export function MealEntryDetailSheet({
  open,
  mealTitle,
  item,
  isDeleting = false,
  onClose,
  onDelete,
}: MealEntryDetailSheetProps) {
  const result = item ? mealItemToSearchResult(item) : null;

  return (
    <BottomSheet
      open={open}
      title={item ? `${mealTitle}を記録` : "食事を記録"}
      onClose={onClose}
    >
      {result && (
        <>
          <FoodResultPreview
            result={result}
            mode="detail"
            caloriesEdited={item?.caloriesEdited}
          />
          <button
            type="button"
            onClick={onDelete}
            disabled={isDeleting}
            style={{
              ...deleteButtonStyle,
              opacity: isDeleting ? 0.6 : 1,
            }}
          >
            {isDeleting ? "削除しています..." : "削除する"}
          </button>
        </>
      )}
    </BottomSheet>
  );
}

const deleteButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 12,
  border: "1px solid #FECACA",
  borderRadius: 10,
  background: "#FEF2F2",
  color: "#B91C1C",
  fontWeight: 700,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
};
