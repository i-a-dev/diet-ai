import { useState, type FormEvent } from "react";
import { ORANGE } from "../../constants.ts";
import { requestPasswordReset } from "../../api/client.ts";
import {
  AuthShell,
  authInputStyle,
  authLabelStyle,
  authLinkButtonStyle,
} from "../auth/AuthShell.tsx";

interface ForgotPasswordScreenProps {
  onBackToLogin: () => void;
}

export function ForgotPasswordScreen({ onBackToLogin }: ForgotPasswordScreenProps) {
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setMessage(null);
    setError(null);
    setSubmitting(true);

    try {
      const result = await requestPasswordReset(email);
      setMessage(result.message);
    } catch (submitError) {
      setError(
        submitError instanceof Error
          ? submitError.message
          : "送信に失敗しました",
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      title="パスワードをお忘れの方"
      subtitle="登録済みのメールアドレスを入力してください。パスワード再設定用のリンクをお送りします。"
      footer={
        <button type="button" onClick={onBackToLogin} style={authLinkButtonStyle}>
          ログイン画面に戻る
        </button>
      }
    >
      <form onSubmit={handleSubmit}>
        <label htmlFor="forgot-email" style={authLabelStyle}>
          メールアドレス
        </label>
        <input
          id="forgot-email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          required
          style={{ ...authInputStyle, marginBottom: 16 }}
        />

        {message && (
          <p style={{ margin: "0 0 16px", fontSize: 13, color: "#2E7D32", lineHeight: 1.5 }}>
            {message}
          </p>
        )}
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
          {submitting ? "送信中..." : "再設定メールを送信"}
        </button>
      </form>
    </AuthShell>
  );
}
