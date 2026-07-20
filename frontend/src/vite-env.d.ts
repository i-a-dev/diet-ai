/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_FOOD_SEARCH_DEBUG_MODE?: "true" | "false";
  readonly VITE_API_BASE_URL?: string;
  readonly VITE_APP_BUILD_NUMBER?: string;
  /** TERMS_OF_SERVICE_URL 相当 */
  readonly VITE_TERMS_OF_SERVICE_URL?: string;
  /** PRIVACY_POLICY_URL 相当 */
  readonly VITE_PRIVACY_POLICY_URL?: string;
  /** COMMERCIAL_TRANSACTIONS_URL 相当 */
  readonly VITE_COMMERCIAL_TRANSACTIONS_URL?: string;
  /** AI利用上の注意（任意） */
  readonly VITE_AI_DISCLAIMER_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

declare const __APP_VERSION__: string;
