/**
 * アプリバージョンの単一ソース。
 * package.json の version を Vite がビルド時に埋め込む（vite.config.ts の define）。
 */
export const APP_VERSION: string =
  typeof __APP_VERSION__ !== "undefined" ? __APP_VERSION__ : "0.0.0";

/** 任意のビルド番号（CI 等で VITE_APP_BUILD_NUMBER を設定） */
export const APP_BUILD_NUMBER: string | null = (() => {
  const raw = import.meta.env.VITE_APP_BUILD_NUMBER;
  if (typeof raw !== "string") {
    return null;
  }
  const trimmed = raw.trim();
  return trimmed === "" ? null : trimmed;
})();

export function formatAppVersionLabel(
  version: string = APP_VERSION,
  buildNumber: string | null = APP_BUILD_NUMBER,
): string {
  if (buildNumber) {
    return `バージョン ${version}（${buildNumber}）`;
  }
  return `バージョン ${version}`;
}
