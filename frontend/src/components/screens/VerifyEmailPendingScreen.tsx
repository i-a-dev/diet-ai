import { useState } from "react";
import { ORANGE } from "../../constants.ts";
import { resendVerificationEmail } from "../../api/client.ts";
import { AuthShell, authLinkButtonStyle } from "../auth/AuthShell.tsx";

interface VerifyEmailPendingScreenProps {
  email: string;
  onBackToLogin: () => void;
}

export function VerifyEmailPendingScreen({
  email,
  onBackToLogin,
}: VerifyEmailPendingScreenProps) {
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const handleResend = async () => {
    setMessage(null);
    setError(null);
    setSubmitting(true);

    try {
      const result = await resendVerificationEmail(email);
      setMessage(result.message);
    } catch (resendError) {
      setError(
        resendError instanceof Error
          ? resendError.message
          : "再送に失敗しました",
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      title="メールを確認してください"
      subtitle={`${email} 宛に確認メールを送信しました。メール内のリンクをクリックして登録を完了してください。`}
      footer={
        <button type="button" onClick={onBackToLogin} style={authLinkButtonStyle}>
          ログイン画面に戻る
        </button>
      }
    >
      <p style={{ margin: "0 0 20px", fontSize: 13, color: "#666", lineHeight: 1.6 }}>
        メールが届かない場合は、迷惑メールフォルダもご確認ください。
      </p>

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
        type="button"
        disabled={submitting}
        onClick={() => void handleResend()}
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
        {submitting ? "送信中..." : "確認メールを再送する"}
      </button>
    </AuthShell>
  );
}
