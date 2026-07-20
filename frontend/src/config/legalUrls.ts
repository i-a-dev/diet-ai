/**
 * 法務・ポリシー系の外部 URL。
 * 正式な本文はここに書かず、環境変数で URL のみ設定する。
 *
 * TODO: 本番公開前に以下を設定すること
 * - VITE_TERMS_OF_SERVICE_URL
 * - VITE_PRIVACY_POLICY_URL
 * - VITE_COMMERCIAL_TRANSACTIONS_URL
 * - VITE_AI_DISCLAIMER_URL（任意・AI利用上の注意）
 */

export type LegalDocumentKey =
  | "termsOfService"
  | "privacyPolicy"
  | "commercialTransactions"
  | "aiDisclaimer";

export interface LegalDocumentConfig {
  key: LegalDocumentKey;
  label: string;
  description: string;
  /** 環境変数名（ドキュメント用） */
  envKey: string;
  url: string | null;
}

function readUrl(envValue: string | undefined): string | null {
  if (typeof envValue !== "string") {
    return null;
  }
  const trimmed = envValue.trim();
  return trimmed === "" ? null : trimmed;
}

export function getLegalDocuments(): LegalDocumentConfig[] {
  return [
    {
      key: "termsOfService",
      label: "利用規約",
      description: "サービスの利用条件",
      envKey: "VITE_TERMS_OF_SERVICE_URL",
      // TERMS_OF_SERVICE_URL 相当（フロントは VITE_ プレフィックス）
      url: readUrl(import.meta.env.VITE_TERMS_OF_SERVICE_URL),
    },
    {
      key: "privacyPolicy",
      label: "プライバシーポリシー",
      description: "健康関連データを含む取り扱いについて",
      envKey: "VITE_PRIVACY_POLICY_URL",
      // PRIVACY_POLICY_URL 相当
      url: readUrl(import.meta.env.VITE_PRIVACY_POLICY_URL),
    },
    {
      key: "commercialTransactions",
      label: "特定商取引法に基づく表記",
      description: "販売事業者情報など",
      envKey: "VITE_COMMERCIAL_TRANSACTIONS_URL",
      // COMMERCIAL_TRANSACTIONS_URL 相当
      url: readUrl(import.meta.env.VITE_COMMERCIAL_TRANSACTIONS_URL),
    },
    {
      key: "aiDisclaimer",
      label: "AI利用上の注意",
      description: "医療行為の代替ではないことなど",
      envKey: "VITE_AI_DISCLAIMER_URL",
      url: readUrl(import.meta.env.VITE_AI_DISCLAIMER_URL),
    },
  ];
}

export function getLegalDocument(
  key: LegalDocumentKey,
): LegalDocumentConfig | null {
  return getLegalDocuments().find((doc) => doc.key === key) ?? null;
}

/**
 * http(s) のみ許可して外部 URL を開く。
 * @returns 開けた場合 true
 */
export function openExternalUrl(url: string | null | undefined): boolean {
  if (url == null || url.trim() === "") {
    return false;
  }

  let parsed: URL;
  try {
    parsed = new URL(url.trim());
  } catch {
    return false;
  }

  if (parsed.protocol !== "https:" && parsed.protocol !== "http:") {
    return false;
  }

  const openFn =
    typeof globalThis.open === "function"
      ? globalThis.open.bind(globalThis)
      : null;
  if (openFn === null) {
    return false;
  }

  openFn(parsed.toString(), "_blank", "noopener,noreferrer");
  return true;
}
