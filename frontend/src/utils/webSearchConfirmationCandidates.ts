import type { FoodConfirmationCandidate, FoodSearchCandidate } from "../types/foodSearch.ts";

export function buildWebCandidateDedupeKey(candidate: FoodSearchCandidate): string {
  const baseName = candidate.base_product_name ?? candidate.product_name;
  const variantLabel = candidate.variant_label ?? candidate.package_size ?? "通常サイズ";
  const productName = stripBrandFromProductName(baseName, candidate.brand);

  return `${productName}-${variantLabel}-${candidate.kcal}-${candidate.source_url ?? "no-url"}`;
}

export function filterVisibleWebSearchCandidates(
  candidates: FoodSearchCandidate[],
): FoodSearchCandidate[] {
  const seen = new Set<string>();
  const visible: FoodSearchCandidate[] = [];

  for (const candidate of candidates) {
    if (!Number.isFinite(candidate.kcal) || candidate.kcal <= 0) {
      continue;
    }

    const key = buildWebCandidateDedupeKey(candidate);
    if (seen.has(key)) {
      continue;
    }

    seen.add(key);
    visible.push(candidate);
  }

  return visible;
}

export function toWebConfirmationCandidates(
  candidates: FoodSearchCandidate[],
): FoodConfirmationCandidate[] {
  return filterVisibleWebSearchCandidates(candidates).map((candidate) => {
    const baseName = candidate.base_product_name ?? candidate.product_name;
    const variantLabel = candidate.variant_label ?? candidate.package_size ?? "通常サイズ";
    const productName = stripBrandFromProductName(baseName, candidate.brand);
    const label = candidate.brand
      ? `${candidate.brand} ${productName}`
      : productName;

    return {
      key: buildWebCandidateDedupeKey(candidate),
      label,
      kcal: candidate.kcal,
      badge: variantLabel !== "通常サイズ" ? variantLabel : null,
      sourceUrl: candidate.source_url?.trim() || null,
      webCandidate: candidate,
    };
  });
}

function stripBrandFromProductName(productName: string, brand?: string): string {
  const trimmed = productName.trim();
  if (!brand) return trimmed;
  const brandPrefix = `${brand} `;
  return trimmed.startsWith(brandPrefix)
    ? trimmed.slice(brandPrefix.length).trim()
    : trimmed;
}
