/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_FOOD_SEARCH_DEBUG_MODE?: "true" | "false";
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
