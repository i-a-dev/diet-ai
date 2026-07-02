import { useEffect, useRef, useState } from "react";
import { ORANGE } from "../../constants.ts";
import { useAuth } from "../../contexts/AuthContext.tsx";
import { clearAuthToken } from "../../auth/tokenStorage.ts";
import { AuthShell, authLinkButtonStyle } from "../auth/AuthShell.tsx";

interface VerifyEmailScreenProps {
  token: string;
  onDone: () => void;
}

export function VerifyEmailScreen({ token, onDone }: VerifyEmailScreenProps) {
  const { verifyEmail } = useAuth();
  const [status, setStatus] = useState<"loading" | "success" | "error">("loading");
  const [message, setMessage] = useState("");
  const verificationStarted = useRef(false);

  useEffect(() => {
    if (verificationStarted.current) {
      return;
    }
    verificationStarted.current = true;

    verifyEmail(token)
      .then(() => {
        setStatus("success");
        setMessage("メール認証が完了しました。アプリをご利用いただけます。");
        window.history.replaceState({}, "", "/");
      })
      .catch((error: unknown) => {
        setStatus("error");
        setMessage(
          error instanceof Error
            ? error.message
            : "メール認証に失敗しました",
        );
        clearAuthToken();
        window.history.replaceState({}, "", "/");
      });
  }, [token, verifyEmail]);

  const handleDone = () => {
    if (status === "error") {
      clearAuthToken();
    }
    onDone();
  };

  return (
    <AuthShell
      title="メール認証"
      subtitle={
        status === "loading"
          ? "認証を確認しています..."
          : status === "success"
            ? "認証完了"
            : "認証できませんでした"
      }
      footer={
        status !== "loading" ? (
          <button type="button" onClick={handleDone} style={authLinkButtonStyle}>
            {status === "success" ? "アプリをはじめる" : "ログイン画面に戻る"}
          </button>
        ) : undefined
      }
    >
      {status === "loading" && (
        <p style={{ textAlign: "center", color: "#888", fontSize: 14 }}>少々お待ちください</p>
      )}
      {status !== "loading" && (
        <p
          style={{
            margin: 0,
            fontSize: 14,
            color: status === "success" ? "#2E7D32" : "#E53935",
            lineHeight: 1.6,
            textAlign: "center",
          }}
        >
          {message}
        </p>
      )}
      {status === "success" && (
        <button
          type="button"
          onClick={handleDone}
          style={{
            width: "100%",
            marginTop: 24,
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
          アプリをはじめる
        </button>
      )}
    </AuthShell>
  );
}
