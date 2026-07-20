import type { CSSProperties } from "react";
import { SettingsSubScreen } from "../settings/SettingsSubScreen.tsx";

interface SubscriptionManageScreenProps {
  open: boolean;
  onClose: () => void;
}

/**
 * サブスクリプション管理画面。
 *
 * TODO（差し替えポイント）:
 * - Stripe Customer Portal を使う場合: バックエンドで Billing Portal Session を作成し、
 *   返却された URL へ遷移させる処理に置き換える。
 * - App Store / Google Play のアプリ内課金の場合: 各プラットフォームの正規の
 *   サブスクリプション管理画面（例: iOS の subscriptions URL、Play の subscriptions）を開く。
 * - 現状のコードベースには Stripe / IAP 実装が存在しないため、架空の決済処理は置かない。
 */
export function SubscriptionManageScreen({
  open,
  onClose,
}: SubscriptionManageScreenProps) {
  if (!open) {
    return null;
  }

  return (
    <SettingsSubScreen title="サブスクリプション管理" onClose={onClose}>
      <p style={leadStyle}>現在のプランと契約状態</p>

      <dl style={cardStyle}>
        <div style={rowStyle}>
          <dt style={dtStyle}>プラン</dt>
          <dd style={ddStyle}>未設定</dd>
        </div>
        <div style={rowStyle}>
          <dt style={dtStyle}>契約状態</dt>
          <dd style={ddStyle}>課金未連携</dd>
        </div>
        <div style={rowStyle}>
          <dt style={dtStyle}>次回更新日</dt>
          <dd style={ddStyle}>—</dd>
        </div>
      </dl>

      <p style={paragraphStyle}>
        このアプリには、まだサブスクリプション決済（Stripe やアプリストア課金）が実装されていません。課金機能を追加した際は、この画面からプラン確認・解約・ポータル遷移を行えるようにしてください。
      </p>

      <div style={todoBoxStyle}>
        <strong style={{ display: "block", marginBottom: 6 }}>
          開発者向け TODO
        </strong>
        <ul style={todoListStyle}>
          <li>決済プロバイダ確定後、現在プラン API を追加する</li>
          <li>Web 課金ならカスタマーポータル URL 取得 API を接続する</li>
          <li>ストア課金ならプラットフォームの管理画面 URL を開く</li>
        </ul>
      </div>
    </SettingsSubScreen>
  );
}

const leadStyle: CSSProperties = {
  margin: "0 0 12px",
  fontSize: 14,
  fontWeight: 600,
  color: "#111",
};

const cardStyle: CSSProperties = {
  margin: "0 0 20px",
  padding: "4px 0",
  background: "#fff",
  borderRadius: 12,
  border: "1px solid #EEE",
};

const rowStyle: CSSProperties = {
  display: "flex",
  justifyContent: "space-between",
  gap: 12,
  padding: "12px 16px",
  borderBottom: "1px solid #F5F5F5",
};

const dtStyle: CSSProperties = {
  margin: 0,
  fontSize: 13,
  color: "#888",
};

const ddStyle: CSSProperties = {
  margin: 0,
  fontSize: 14,
  color: "#111",
  fontWeight: 500,
  textAlign: "right",
};

const paragraphStyle: CSSProperties = {
  margin: "0 0 16px",
  fontSize: 14,
  color: "#555",
  lineHeight: 1.7,
};

const todoBoxStyle: CSSProperties = {
  background: "#FFF8F0",
  border: "1px solid #F0D9BF",
  borderRadius: 10,
  padding: "12px 14px",
  fontSize: 12,
  color: "#7A5A2E",
  lineHeight: 1.6,
};

const todoListStyle: CSSProperties = {
  margin: 0,
  paddingLeft: 18,
};
