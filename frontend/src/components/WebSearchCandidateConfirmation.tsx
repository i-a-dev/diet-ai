import type { FoodConfirmationCandidate, VariantDimension } from "../types/foodSearch.ts";
import { getCandidateConfirmationHeading } from "../utils/candidateConfirmationHeading.ts";
import { ProductConfirmationCard } from "./ProductConfirmationCard.tsx";
import { SingleCandidateConfirmationCard } from "./SingleCandidateConfirmationCard.tsx";

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
    return (
      <SingleCandidateConfirmationCard
        heading={heading}
        candidate={candidates[0]}
        onConfirm={() => onConfirmSingle(candidates[0])}
        onEdit={() => onEditSingle(candidates[0])}
        isSubmitting={isSubmitting}
      />
    );
  }

  const isVariantAmbiguous = confirmationReason === "variant_ambiguous";

  return (
    <ProductConfirmationCard
      title={heading}
      candidates={candidates}
      selectedKey={selectedKey}
      onSelect={onSelect}
      onManualInput={onManualInput}
      onUnknown={allowEstimatedAdd === false ? undefined : onUnknown}
      onConfirmSelected={isVariantAmbiguous ? onConfirmSelected : undefined}
    />
  );
}
