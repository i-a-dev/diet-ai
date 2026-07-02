import { useState, type FormEvent } from "react";
import { ORANGE } from "../../constants.ts";
import { resetPassword } from "../../api/client.ts";
import {
  AuthShell,
  authInputStyle,
  authLabelStyle,
  authLinkButtonStyle,
} from "../auth/AuthShell.tsx";

interface ResetPasswordScreenProps {
  token: string;
  onDone: () => void;
}

export function ResetPasswordScreen({ token, onDone }: ResetPasswordScreenProps) {
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setMessage(null);
    setError(null);

    if (password !== confirmPassword) {
      setError("パスワードが一致しません");
      return;
    }

    setSubmitting(true);

    try {
      const result = await resetPassword(token, password);
      setMessage(result.message);
      window.history.replaceState({}, "", "/");
    } catch (submitError) {
      setError(
        submitError instanceof Error
          ? submitError.message
          : "パスワードの再設定に失敗しました",
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      title="新しいパスワードの設定"
      subtitle="新しいパスワードを入力してください"
      footer={
        <button type="button" onClick={onDone} style={authLinkButtonStyle}>
          ログイン画面に戻る
        </button>
      }
    >
      {message ? (
        <p style={{ margin: "0 0 16px", fontSize: 14, color: "#2E7D32", lineHeight: 1.6 }}>
          {message}
        </p>
      ) : (
        <form onSubmit={handleSubmit}>
          <label htmlFor="new-password" style={authLabelStyle}>
            新しいパスワード
          </label>
          <input
            id="new-password"
            type="password"
            autoComplete="new-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
            minLength={8}
            style={{ ...authInputStyle, marginBottom: 16 }}
          />

          <label htmlFor="confirm-password" style={authLabelStyle}>
            新しいパスワード（確認）
          </label>
          <input
            id="confirm-password"
            type="password"
            autoComplete="new-password"
            value={confirmPassword}
            onChange={(event) => setConfirmPassword(event.target.value)}
            required
            minLength={8}
            style={{ ...authInputStyle, marginBottom: 8 }}
          />
          <p style={{ margin: "0 0 16px", fontSize: 12, color: "#999" }}>
            8文字以上で設定してください
          </p>

          {error && (
            <p style={{ margin: "0 0 16px", fontSize: 13, color: "#E53935", lineHeight: 1.5 }}>
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            style={{
              width: "100%",
              padding: "14px 0",
              fontSize: 16,
              fontWeight: 600,
              color: "#fff",
              background: submitting ? "#ccc" : ORANGE,
              border: "none",
              borderRadius: 12,
              cursor: submitting ? "default" : "pointer",
            }}
          >
            {submitting ? "設定中..." : "パスワードを再設定"}
          </button>
        </form>
      )}

      {message && (
        <button
          type="button"
          onClick={onDone}
          style={{
            width: "100%",
            marginTop: 8,
            padding: "14px 0",
            fontSize: 16,
            fontWeight: 600,
            color: "#fff",
            background: ORANGE,
            border: "none",
            borderRadius: 12,
            cursor: "pointer",
          }}
        >
          ログイン画面へ
        </button>
      )}
    </AuthShell>
  );
}
