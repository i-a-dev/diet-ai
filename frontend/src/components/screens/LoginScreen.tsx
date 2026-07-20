import { useEffect, useState, type CSSProperties, type FormEvent } from "react";
import { Eye, EyeOff } from "lucide-react";
import splashLogo from "../../assets/splash-logo.png";
import { ORANGE } from "../../constants.ts";
import { useAuth } from "../../contexts/AuthContext.tsx";
import { APP_FONT_FAMILY } from "../PhoneMockFrame.tsx";
import { VerifyEmailPendingScreen } from "./VerifyEmailPendingScreen.tsx";

type AuthMode = "login" | "register";

interface LoginScreenProps {
  onForgotPassword: () => void;
}

const REMEMBER_EMAIL_KEY = "movi_remember_email";
const SAVED_EMAIL_KEY = "movi_saved_email";

export function LoginScreen({ onForgotPassword }: LoginScreenProps) {
  const { login, register } = useAuth();
  const [mode, setMode] = useState<AuthMode>("login");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [rememberPassword, setRememberPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [pendingEmail, setPendingEmail] = useState<string | null>(null);

  useEffect(() => {
    try {
      const shouldRemember = localStorage.getItem(REMEMBER_EMAIL_KEY) === "1";
      const savedEmail = localStorage.getItem(SAVED_EMAIL_KEY) ?? "";
      if (shouldRemember && savedEmail) {
        setRememberPassword(true);
        setEmail(savedEmail);
      }
    } catch {
      // localStorage が使えない環境では無視
    }
  }, []);

  const persistRememberPreference = (nextEmail: string, remember: boolean) => {
    try {
      if (remember) {
        localStorage.setItem(REMEMBER_EMAIL_KEY, "1");
        localStorage.setItem(SAVED_EMAIL_KEY, nextEmail);
      } else {
        localStorage.removeItem(REMEMBER_EMAIL_KEY);
        localStorage.removeItem(SAVED_EMAIL_KEY);
      }
    } catch {
      // localStorage が使えない環境では無視
    }
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      if (mode === "login") {
        persistRememberPreference(email, rememberPassword);
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

  const switchMode = () => {
    if (mode === "login") {
      setPassword("");
    } else {
      setEmail("");
      setPassword("");
    }
    setMode(mode === "login" ? "register" : "login");
    setShowPassword(false);
    setError(null);
  };

  if (pendingEmail) {
    return (
      <VerifyEmailPendingScreen
        email={pendingEmail}
        onBackToLogin={() => {
          setPendingEmail(null);
          setMode("login");
          setEmail("");
          setPassword("");
          setError(null);
        }}
      />
    );
  }

  return (
    <div style={pageStyle}>
      <div style={contentStyle}>
        <header style={brandHeaderStyle}>
          <img src={splashLogo} alt="Movi" style={brandLogoStyle} />
        </header>

        <form onSubmit={handleSubmit}>
          <label htmlFor="email" style={labelStyle}>
            メールアドレス
          </label>
          <input
            id="email"
            type="email"
            autoComplete="email"
            placeholder="例) you@example.com"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
            style={{ ...inputStyle, marginBottom: 12 }}
          />

          <label htmlFor="password" style={labelStyle}>
            パスワード
          </label>
          <div
            style={{
              position: "relative",
              marginBottom: mode === "register" ? 6 : 10,
            }}
          >
            <input
              id="password"
              type={showPassword ? "text" : "password"}
              autoComplete={
                mode === "login" ? "current-password" : "new-password"
              }
              placeholder="パスワードを入力"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
              minLength={8}
              style={{ ...inputStyle, paddingRight: 44 }}
            />
            <button
              type="button"
              aria-label={
                showPassword ? "パスワードを隠す" : "パスワードを表示"
              }
              onClick={() => setShowPassword((prev) => !prev)}
              style={eyeButtonStyle}
            >
              {showPassword ? (
                <EyeOff size={18} strokeWidth={1.8} color="#B0B0B0" />
              ) : (
                <Eye size={18} strokeWidth={1.8} color="#B0B0B0" />
              )}
            </button>
          </div>

          {mode === "register" && (
            <p style={hintStyle}>8文字以上で設定してください</p>
          )}

          {mode === "login" && (
            <label style={checkboxRowStyle}>
              <input
                type="checkbox"
                checked={rememberPassword}
                onChange={(event) => {
                  const checked = event.target.checked;
                  setRememberPassword(checked);
                  if (!checked) {
                    persistRememberPreference(email, false);
                  }
                }}
                style={checkboxStyle}
              />
              <span>パスワードを保存する</span>
            </label>
          )}

          {error && <p style={errorStyle}>{error}</p>}

          <button
            type="submit"
            disabled={submitting}
            style={{
              ...primaryButtonStyle,
              background: submitting ? "#ccc" : ORANGE,
              cursor: submitting ? "default" : "pointer",
              marginTop: mode === "login" ? 14 : 6,
            }}
          >
            {submitting
              ? "処理中..."
              : mode === "login"
                ? "ログイン"
                : "アカウント作成"}
          </button>
        </form>

        <button type="button" onClick={switchMode} style={linkButtonStyle}>
          {mode === "login"
            ? "アカウントをお持ちでない方はこちら"
            : "すでにアカウントをお持ちの方はログイン"}
        </button>

        {mode === "login" && (
          <>
            <div style={dividerStyle}>
              <span style={dividerLineStyle} />
              <span style={dividerTextStyle}>または</span>
              <span style={dividerLineStyle} />
            </div>

            <button
              type="button"
              onClick={() =>
                setError("Appleでサインインは近日対応予定です")
              }
              style={appleButtonStyle}
            >
              <AppleLogo />
              <span>Appleでサインイン</span>
            </button>

            <button
              type="button"
              onClick={onForgotPassword}
              style={{ ...linkButtonStyle, marginTop: 14 }}
            >
              パスワードをお忘れの方
            </button>
          </>
        )}
      </div>
    </div>
  );
}

function AppleLogo() {
  return (
    <svg
      width="18"
      height="18"
      viewBox="0 0 24 24"
      aria-hidden="true"
      focusable="false"
    >
      <path
        fill="#111"
        d="M16.365 1.43c0 1.14-.42 2.2-1.2 3.02-.9.95-2.12 1.55-3.28 1.45-.1-1.1.45-2.25 1.2-3.08.9-.98 2.3-1.62 3.28-1.39zm3.5 12.2c-.05 2.35 1.95 3.15 2 3.18-.03.1-.32 1.1-1.05 2.18-.63.92-1.3 1.83-2.35 1.85-1.03.02-1.36-.62-2.54-.62-1.18 0-1.55.6-2.53.64-1.02.04-1.8-.99-2.44-1.9-1.3-1.88-2.3-5.32-.96-7.64.66-1.15 1.84-1.88 3.12-1.9 1.02-.02 1.98.7 2.54.7.55 0 1.72-.86 2.9-.74.5.02 1.9.2 2.8 1.52-.07.05-1.67 1-1.64 2.73z"
      />
    </svg>
  );
}

const pageStyle: CSSProperties = {
  fontFamily: APP_FONT_FAMILY,
  flex: 1,
  height: "100%",
  minHeight: 0,
  background: "#fff",
  display: "flex",
  flexDirection: "column",
  alignItems: "center",
  justifyContent: "center",
  padding: "16px 24px",
  overflow: "hidden",
};

const contentStyle: CSSProperties = {
  width: "100%",
  maxWidth: 340,
};

const brandHeaderStyle: CSSProperties = {
  display: "flex",
  flexDirection: "column",
  alignItems: "center",
  marginBottom: 20,
};

const brandLogoStyle: CSSProperties = {
  width: "58%",
  maxWidth: 168,
  height: "auto",
  display: "block",
};

const labelStyle: CSSProperties = {
  display: "block",
  fontSize: 13,
  fontWeight: 600,
  color: "#555",
  marginBottom: 6,
};

const inputStyle: CSSProperties = {
  width: "100%",
  boxSizing: "border-box",
  padding: "12px 14px",
  fontSize: 15,
  color: "#222",
  border: "1px solid #E2E2E2",
  borderRadius: 10,
  outline: "none",
  background: "#fff",
};

const eyeButtonStyle: CSSProperties = {
  position: "absolute",
  top: "50%",
  right: 10,
  transform: "translateY(-50%)",
  width: 32,
  height: 32,
  padding: 0,
  border: "none",
  background: "transparent",
  display: "inline-flex",
  alignItems: "center",
  justifyContent: "center",
  cursor: "pointer",
};

const checkboxRowStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  gap: 8,
  fontSize: 13,
  color: "#666",
  cursor: "pointer",
  userSelect: "none",
};

const checkboxStyle: CSSProperties = {
  width: 16,
  height: 16,
  margin: 0,
  accentColor: ORANGE,
  cursor: "pointer",
};

const hintStyle: CSSProperties = {
  margin: "0 0 12px",
  fontSize: 12,
  color: "#999",
};

const errorStyle: CSSProperties = {
  margin: "12px 0 0",
  fontSize: 13,
  color: "#E53935",
  lineHeight: 1.5,
};

const primaryButtonStyle: CSSProperties = {
  width: "100%",
  padding: "13px 0",
  fontSize: 16,
  fontWeight: 700,
  color: "#fff",
  border: "none",
  borderRadius: 10,
};

const linkButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 12,
  padding: 0,
  border: "none",
  background: "transparent",
  color: ORANGE,
  fontSize: 13,
  fontWeight: 600,
  cursor: "pointer",
  textAlign: "center",
};

const dividerStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  gap: 12,
  margin: "16px 0 14px",
};

const dividerLineStyle: CSSProperties = {
  flex: 1,
  height: 1,
  background: "#E8E8E8",
};

const dividerTextStyle: CSSProperties = {
  fontSize: 12,
  color: "#B0B0B0",
  flexShrink: 0,
};

const appleButtonStyle: CSSProperties = {
  width: "100%",
  display: "flex",
  alignItems: "center",
  justifyContent: "center",
  gap: 8,
  padding: "12px 0",
  fontSize: 15,
  fontWeight: 600,
  color: "#111",
  background: "#fff",
  border: "1px solid #E0E0E0",
  borderRadius: 10,
  cursor: "pointer",
};
