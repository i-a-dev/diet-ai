import type { LucideIcon } from "lucide-react";
import {
  CreditCard,
  FileText,
  HelpCircle,
  LogOut,
  Scale,
  Shield,
  Trash2,
  UserCog,
} from "lucide-react";
import type { LegalDocumentKey } from "../config/legalUrls.ts";

/** 設定画面内の内部画面 */
export type SettingsInternalView =
  | "profile"
  | "subscription"
  | "contact"
  | "deleteAccount";

export type SettingsItemAction =
  | { type: "internal"; view: SettingsInternalView }
  | { type: "logout" }
  | { type: "legal"; documentKey: LegalDocumentKey }
  | { type: "none" };

export interface SettingsItemDefinition {
  id: string;
  label: string;
  description?: string;
  icon: LucideIcon;
  action: SettingsItemAction;
  danger?: boolean;
  /** 外部リンクであることが分かる表示 */
  external?: boolean;
  /** 現在値（バージョンなど） */
  value?: string;
  /** 行をボタンとして扱わない（バージョン表示など） */
  interactive?: boolean;
}

export interface SettingsSectionDefinition {
  id: string;
  title: string;
  items: SettingsItemDefinition[];
}

export function buildSettingsSections(
  appVersionLabel: string,
): SettingsSectionDefinition[] {
  return [
    {
      id: "account",
      title: "アカウント",
      items: [
        {
          id: "profile",
          label: "プロフィール",
          description: "身体情報・目標の編集",
          icon: UserCog,
          action: { type: "internal", view: "profile" },
        },
        {
          id: "subscription",
          label: "サブスクリプション管理",
          description: "プラン・契約状態の確認",
          icon: CreditCard,
          action: { type: "internal", view: "subscription" },
        },
        {
          id: "logout",
          label: "ログアウト",
          icon: LogOut,
          action: { type: "logout" },
        },
        {
          id: "deleteAccount",
          label: "アカウント削除",
          description: "データの完全削除（取り消し不可）",
          icon: Trash2,
          action: { type: "internal", view: "deleteAccount" },
          danger: true,
        },
      ],
    },
    {
      id: "support",
      title: "サポート",
      items: [
        {
          id: "contact",
          label: "お問い合わせ",
          description: "不具合やご質問の送信",
          icon: HelpCircle,
          action: { type: "internal", view: "contact" },
        },
      ],
    },
    {
      id: "legal",
      title: "法務・ポリシー",
      items: [
        {
          id: "terms",
          label: "利用規約",
          icon: FileText,
          action: { type: "legal", documentKey: "termsOfService" },
          external: true,
        },
        {
          id: "privacy",
          label: "プライバシーポリシー",
          description: "健康関連データの取り扱い",
          icon: Shield,
          action: { type: "legal", documentKey: "privacyPolicy" },
          external: true,
        },
        {
          id: "tokushoho",
          label: "特定商取引法に基づく表記",
          icon: Scale,
          action: { type: "legal", documentKey: "commercialTransactions" },
          external: true,
        },
        {
          id: "aiDisclaimer",
          label: "AI利用上の注意",
          description: "医療行為の代替ではないことなど",
          icon: FileText,
          action: { type: "legal", documentKey: "aiDisclaimer" },
          external: true,
        },
      ],
    },
    {
      id: "app",
      title: "アプリ情報",
      items: [
        {
          id: "version",
          label: "アプリバージョン",
          icon: FileText,
          action: { type: "none" },
          value: appVersionLabel,
          interactive: false,
        },
      ],
    },
  ];
}

export function listSettingsItemLabels(
  sections: SettingsSectionDefinition[],
): string[] {
  return sections.flatMap((section) =>
    section.items.map((item) => item.label),
  );
}
