import { useState, type CSSProperties, type FormEvent } from "react";
import { SettingsSubScreen } from "../settings/SettingsSubScreen.tsx";
import { ConfirmDialog } from "../ConfirmDialog.tsx";
import { deleteAccount } from "../../api/client.ts";
import { clearAuthToken } from "../../auth/tokenStorage.ts";
import { useAuth } from "../../contexts/AuthContext.tsx";

interface DeleteAccountScreenProps {
  open: boolean;
  onClose: () => void;
  onDeleted: () => void;
}

const CONFIRMATION_PHRASE = "削除する";

export function DeleteAccountScreen({
  open,
  onClose,
  onDeleted,
}: DeleteAccountScreenProps) {
  const { user } = useAuth();
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  if (!open) {
    return null;
  }

  const reset = () => {
    setPassword("");
    setConfirmation("");
    setError(null);
    setConfirmOpen(false);
  };

  const handleClose = () => {
    if (deleting) {
      return;
    }
    reset();
    onClose();
  };

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    setError(null);

    if (password === "") {
      setError("パスワードを入力してください");
      return;
    }
    if (confirmation.trim() !== CONFIRMATION_PHRASE) {
      setError(`確認のため「${CONFIRMATION_PHRASE}」と入力してください`);
      return;
    }
    setConfirmOpen(true);
  };

  const handleDelete = async () => {
    if (deleting) {
      return;
    }
    setDeleting(true);
    setError(null);

    try {
      await deleteAccount({ password, confirmation: confirmation.trim() });
      clearAuthToken();
      onDeleted();
    } catch (deleteError) {
      setError(
        deleteError instanceof Error
          ? deleteError.message
          : "アカウントの削除に失敗しました",
      );
      setConfirmOpen(false);
    } finally {
      setDeleting(false);
    }
  };

  return (
    <>
      <SettingsSubScreen title="アカウント削除" onClose={handleClose}>
        <div style={dangerBannerStyle} role="note">
          危険な操作です。アカウント削除は取り消せません。
        </div>

        <p style={paragraphStyle}>
          アカウントを削除すると、プロフィール、食事記録、運動記録、AIチャット履歴などのデータが削除されます。この操作は取り消せません。
        </p>

        <h3 style={headingStyle}>削除されるデータ</h3>
        <ul style={listStyle}>
          <li>プロフィール情報</li>
          <li>体重・食事・運動・歩数の記録</li>
          <li>AIチャット履歴</li>
          <li>お問い合わせ履歴（アカウントに紐づくもの）</li>
        </ul>

        <h3 style={headingStyle}>サブスクリプションについて</h3>
        <p style={paragraphStyle}>
          アカウントを削除しても、App Store、Google Play、または外部決済で契約中のサブスクリプションが自動的に解約されない場合があります。削除前にサブスクリプションの状態をご確認ください。
        </p>
        <p style={noteStyle}>
          ※ 現時点でアプリ内の課金連携は未実装です。課金を導入した場合は、解約手順をこの画面に追記してください（TODO）。
        </p>

        <form onSubmit={handleSubmit} style={formStyle}>
          <p style={accountStyle}>対象アカウント: {user?.email ?? "—"}</p>

          <label htmlFor="delete-password" style={labelStyle}>
            パスワード（本人確認）
          </label>
          <input
            id="delete-password"
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="current-password"
            disabled={deleting}
            style={inputStyle}
            required
          />

          <label htmlFor="delete-confirmation" style={labelStyle}>
            確認のため「{CONFIRMATION_PHRASE}」と入力
          </label>
          <input
            id="delete-confirmation"
            type="text"
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            disabled={deleting}
            style={inputStyle}
            required
            autoComplete="off"
          />

          {error ? (
            <p role="alert" style={errorStyle}>
              {error}
            </p>
          ) : null}

          <button
            type="submit"
            disabled={deleting}
            style={{
              ...dangerButtonStyle,
              opacity: deleting ? 0.6 : 1,
            }}
          >
            アカウントを削除する
          </button>
        </form>
      </SettingsSubScreen>

      <ConfirmDialog
        open={confirmOpen}
        title="最終確認"
        message="本当にアカウントを削除しますか？この操作は取り消せません。"
        confirmLabel="削除する"
        confirmDanger
        confirming={deleting}
        onCancel={() => {
          if (!deleting) {
            setConfirmOpen(false);
          }
        }}
        onConfirm={() => void handleDelete()}
      />
    </>
  );
}

const dangerBannerStyle: CSSProperties = {
  background: "#FDECEA",
  color: "#C0392B",
  borderRadius: 10,
  padding: "12px 14px",
  fontSize: 13,
  fontWeight: 600,
  lineHeight: 1.5,
  marginBottom: 16,
};

const paragraphStyle: CSSProperties = {
  margin: "0 0 14px",
  fontSize: 14,
  color: "#444",
  lineHeight: 1.7,
};

const headingStyle: CSSProperties = {
  margin: "8px 0 8px",
  fontSize: 14,
  fontWeight: 700,
  color: "#111",
};

const listStyle: CSSProperties = {
  margin: "0 0 16px",
  paddingLeft: 20,
  fontSize: 14,
  color: "#444",
  lineHeight: 1.7,
};

const noteStyle: CSSProperties = {
  margin: "0 0 20px",
  fontSize: 12,
  color: "#888",
  lineHeight: 1.6,
};

const formStyle: CSSProperties = {
  display: "flex",
  flexDirection: "column",
};

const accountStyle: CSSProperties = {
  margin: "0 0 14px",
  fontSize: 13,
  color: "#666",
};

const labelStyle: CSSProperties = {
  fontSize: 13,
  fontWeight: 600,
  color: "#444",
  marginBottom: 6,
};

const inputStyle: CSSProperties = {
  width: "100%",
  boxSizing: "border-box",
  border: "1px solid #DDD",
  borderRadius: 10,
  padding: "12px 14px",
  fontSize: 15,
  marginBottom: 14,
  background: "#fff",
  minHeight: 44,
};

const dangerButtonStyle: CSSProperties = {
  marginTop: 8,
  minHeight: 48,
  border: "none",
  borderRadius: 12,
  background: "#C0392B",
  color: "#fff",
  fontSize: 16,
  fontWeight: 700,
  cursor: "pointer",
};

const errorStyle: CSSProperties = {
  margin: "0 0 12px",
  color: "#C0392B",
  fontSize: 13,
  lineHeight: 1.5,
};
