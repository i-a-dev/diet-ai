import { describe, expect, it, vi, afterEach } from "vitest";
import {
  formatAppVersionLabel,
  APP_VERSION,
} from "../config/appVersion.ts";
import {
  getLegalDocuments,
  openExternalUrl,
} from "../config/legalUrls.ts";
import {
  buildSettingsSections,
  listSettingsItemLabels,
} from "../settings/settingsCatalog.ts";

describe("appVersion", () => {
  it("package.json 由来のバージョンを表示する", () => {
    expect(APP_VERSION).toBeTruthy();
    expect(formatAppVersionLabel("1.2.3", null)).toBe("バージョン 1.2.3");
    expect(formatAppVersionLabel("1.2.3", "100")).toBe(
      "バージョン 1.2.3（100）",
    );
  });
});

describe("legalUrls", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("URL 未設定でもドキュメント定義を取得でき、クラッシュしない", () => {
    const docs = getLegalDocuments();
    expect(docs.length).toBeGreaterThanOrEqual(3);
    expect(docs.map((d) => d.key)).toEqual(
      expect.arrayContaining([
        "termsOfService",
        "privacyPolicy",
        "commercialTransactions",
      ]),
    );
  });

  it("不正な URL や未設定では openExternalUrl が false を返す", () => {
    const open = vi.fn();
    vi.stubGlobal("open", open);

    expect(openExternalUrl(null)).toBe(false);
    expect(openExternalUrl("")).toBe(false);
    expect(openExternalUrl("javascript:alert(1)")).toBe(false);
    expect(openExternalUrl("not a url")).toBe(false);
    expect(open).not.toHaveBeenCalled();
  });

  it("https URL は noopener,noreferrer で開く", () => {
    const open = vi.fn();
    vi.stubGlobal("open", open);

    expect(openExternalUrl("https://example.com/privacy")).toBe(true);
    expect(open).toHaveBeenCalledWith(
      "https://example.com/privacy",
      "_blank",
      "noopener,noreferrer",
    );
  });
});

describe("settingsCatalog", () => {
  it("設定画面に必要な項目がすべて含まれる", () => {
    const labels = listSettingsItemLabels(
      buildSettingsSections("バージョン 0.0.0"),
    );

    expect(labels).toEqual(
      expect.arrayContaining([
        "プロフィール",
        "サブスクリプション管理",
        "ログアウト",
        "アカウント削除",
        "お問い合わせ",
        "利用規約",
        "プライバシーポリシー",
        "特定商取引法に基づく表記",
        "アプリバージョン",
      ]),
    );
  });

  it("プロフィールは既存の内部画面アクションである", () => {
    const sections = buildSettingsSections("バージョン 0.0.0");
    const profile = sections
      .flatMap((s) => s.items)
      .find((item) => item.id === "profile");

    expect(profile?.action).toEqual({ type: "internal", view: "profile" });
  });

  it("アプリバージョンは非インタラクティブで値が表示される", () => {
    const sections = buildSettingsSections("バージョン 1.0.0（10）");
    const version = sections
      .flatMap((s) => s.items)
      .find((item) => item.id === "version");

    expect(version?.interactive).toBe(false);
    expect(version?.value).toBe("バージョン 1.0.0（10）");
    expect(version?.action.type).toBe("none");
  });
});
