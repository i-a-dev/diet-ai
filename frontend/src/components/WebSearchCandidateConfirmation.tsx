import type { FoodConfirmationCandidate, VariantDimension } from "../types/foodSearch.ts";
import { getCandidateConfirmationHeading } from "../utils/candidateConfirmationHeading.ts";
import { ModalStateLayout } from "./ModalStateLayout.tsx";
import { ProductConfirmationCard } from "./ProductConfirmationCard.tsx";
import { SingleCandidateConfirmationCard } from "./SingleCandidateConfirmationCard.tsx";
import {
  modalNeutralActionStyle,
  modalPrimaryActionStyle,
  modalTextActionStyle,
} from "./foodModalActionStyles.ts";

interface WebSearchCandidateConfirmationProps {
  variantDimension?: VariantDimension | string;
  candidates: FoodConfirmationCandidate[];
  confirmationReason?: "variant_ambiguous" | "identity_ambiguous" | null;
  allowEstimatedAdd?: boolean;
  selectedKey?: string | null;
  onSelect: (candidate: FoodConfirmationCandidate) => void;
  onConfirmSingle: (candidate: FoodConfirmationCandidate) => void;
  onEditSingle: (candidate: FoodConfirmationCandidate) => void;
  onConfirmSelected?: () => void;
  onManualInput: () => void;
  onUnknown?: () => void;
  isSubmitting?: boolean;
}

export function WebSearchCandidateConfirmation({
  variantDimension = "unknown",
  candidates,
  confirmationReason,
  allowEstimatedAdd = true,
  selectedKey = null,
  onSelect,
  onConfirmSingle,
  onEditSingle,
  onConfirmSelected,
  onManualInput,
  onUnknown,
  isSubmitting = false,
}: WebSearchCandidateConfirmationProps) {
  const candidateCount = candidates.length;
  const heading = getCandidateConfirmationHeading(variantDimension, candidateCount);

  if (candidateCount === 1) {
    const candidate = candidates[0];
    return (
      <ModalStateLayout
        contentMode="top"
        content={
          <SingleCandidateConfirmationCard
            heading={heading}
            candidate={candidate}
          />
        }
        actions={
          <div className="modal-result-actions modal-result-actions--add-edit">
            <button
              type="button"
              onClick={() => onConfirmSingle(candidate)}
              disabled={isSubmitting}
              className="modal-primary-action"
              style={{
                ...modalPrimaryActionStyle,
                opacity: isSubmitting ? 0.6 : 1,
              }}
            >
              追加する
            </button>
            <button
              type="button"
              onClick={() => onEditSingle(candidate)}
              disabled={isSubmitting}
              className="modal-edit-action"
            >
              編集
            </button>
          </div>
        }
      />
    );
  }

  const isVariantAmbiguous = confirmationReason === "variant_ambiguous";
  const showUnknown = allowEstimatedAdd !== false && onUnknown;

  return (
    <ModalStateLayout
      contentMode="fill"
      content={
        <ProductConfirmationCard
          title={heading}
          candidates={candidates}
          selectedKey={selectedKey}
          onSelect={onSelect}
          fillAvailableSpace
        />
      }
      actions={
        <>
          <button type="button" onClick={onManualInput} style={modalTextActionStyle}>
            編集
          </button>
          {showUnknown && (
            <button type="button" onClick={onUnknown} style={modalNeutralActionStyle}>
              わからない
            </button>
          )}
          {selectedKey && onConfirmSelected && isVariantAmbiguous && (
            <button
              type="button"
              onClick={onConfirmSelected}
              style={modalPrimaryActionStyle}
            >
              追加する
            </button>
          )}
        </>
      }
    />
  );
}
