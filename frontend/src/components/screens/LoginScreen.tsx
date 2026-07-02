import { useState, type FormEvent } from "react";
import { ORANGE } from "../../constants.ts";
import { useAuth } from "../../contexts/AuthContext.tsx";
import {
  AuthShell,
  authInputStyle,
  authLabelStyle,
  authLinkButtonStyle,
} from "../auth/AuthShell.tsx";
import { VerifyEmailPendingScreen } from "./VerifyEmailPendingScreen.tsx";

type AuthMode = "login" | "register";

interface LoginScreenProps {
  onForgotPassword: () => void;
}

export function LoginScreen({ onForgotPassword }: LoginScreenProps) {
  const { login, register } = useAuth();
  const [mode, setMode] = useState<AuthMode>("login");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [pendingEmail, setPendingEmail] = useState<string | null>(null);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      if (mode === "login") {
        await login(email, password);
      } else {
        const result = await register(email, password);
        setPendingEmail(result.email);
      }
    } catch (submitError) {
      const message =
        submitError instanceof Error
          ? submitError.message
          : "ログインに失敗しました";
      setError(message);
    } finally {
      setSubmitting(false);
    }
  };

  if (pendingEmail) {
    return (
      <VerifyEmailPendingScreen
        email={pendingEmail}
        onBackToLogin={() => {
          setPendingEmail(null);
          setMode("login");
          setPassword("");
        }}
      />
    );
  }

  return (
    <AuthShell
      title="ダイエットアプリ"
      subtitle={
        mode === "login"
          ? "メールアドレスとパスワードでログイン"
          : "アカウントを作成して始めましょう"
      }
      footer={
        <>
          <button
            type="button"
            onClick={() => {
              setMode(mode === "login" ? "register" : "login");
              setError(null);
            }}
            style={authLinkButtonStyle}
          >
            {mode === "login"
              ? "アカウントをお持ちでない方はこちら"
              : "すでにアカウントをお持ちの方はログイン"}
          </button>
          {mode === "login" && (
            <button
              type="button"
              onClick={onForgotPassword}
              style={{ ...authLinkButtonStyle, marginTop: 12, fontWeight: 500 }}
            >
              パスワードをお忘れの方
            </button>
          )}
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <label htmlFor="email" style={authLabelStyle}>
          メールアドレス
        </label>
        <input
          id="email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          required
          style={{ ...authInputStyle, marginBottom: 16 }}
        />

        <label htmlFor="password" style={authLabelStyle}>
          パスワード
        </label>
        <input
          id="password"
          type="password"
          autoComplete={
            mode === "login" ? "current-password" : "new-password"
          }
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          required
          minLength={8}
          style={{ ...authInputStyle, marginBottom: 8 }}
        />
        {mode === "register" && (
          <p style={{ margin: "0 0 16px", fontSize: 12, color: "#999" }}>
            8文字以上で設定してください
          </p>
        )}

        {error && (
          <p
            style={{
              margin: "0 0 16px",
              fontSize: 13,
              color: "#E53935",
              lineHeight: 1.5,
            }}
          >
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
          {submitting
            ? "処理中..."
            : mode === "login"
              ? "ログイン"
              : "アカウント作成"}
        </button>
      </form>
    </AuthShell>
  );
}
