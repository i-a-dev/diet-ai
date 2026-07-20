import { useMemo, useState, type CSSProperties, type FormEvent } from "react";
import { SettingsSubScreen } from "../settings/SettingsSubScreen.tsx";
import { submitContactInquiry, type ContactCategory } from "../../api/client.ts";
import { useAuth } from "../../contexts/AuthContext.tsx";
import { ORANGE } from "../../constants.ts";

interface ContactScreenProps {
  open: boolean;
  onClose: () => void;
}

const CATEGORY_OPTIONS: Array<{ value: ContactCategory; label: string }> = [
  { value: "app_usage", label: "アプリの使い方" },
  { value: "bug", label: "不具合" },
  { value: "billing", label: "課金・サブスクリプション" },
  { value: "account", label: "アカウント" },
  { value: "ai_coach", label: "AIコーチの回答" },
  { value: "other", label: "その他" },
];

const MAX_SUBJECT = 100;
const MAX_BODY = 2000;

export function ContactScreen({ open, onClose }: ContactScreenProps) {
  const { user } = useAuth();
  const [category, setCategory] = useState<ContactCategory | "">("");
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");
  const [replyEmail, setReplyEmail] = useState(user?.email ?? "");
  const [honeypot, setHoneypot] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const canSubmit = useMemo(() => {
    return (
      category !== "" &&
      subject.trim() !== "" &&
      body.trim() !== "" &&
      replyEmail.trim() !== "" &&
      !submitting
    );
  }, [category, subject, body, replyEmail, submitting]);

  if (!open) {
    return null;
  }

  const resetForm = () => {
    setCategory("");
    setSubject("");
    setBody("");
    setReplyEmail(user?.email ?? "");
    setHoneypot("");
    setError(null);
    setSuccess(null);
  };

  const handleClose = () => {
    if (submitting) {
      return;
    }
    resetForm();
    onClose();
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!canSubmit || category === "") {
      return;
    }

    setError(null);
    setSuccess(null);
    setSubmitting(true);

    try {
      const result = await submitContactInquiry({
        category,
        subject: subject.trim(),
        body: body.trim(),
        replyEmail: replyEmail.trim(),
        honeypot,
      });
      setSuccess(result.message);
      setSubject("");
      setBody("");
      setCategory("");
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
    <SettingsSubScreen title="お問い合わせ" onClose={handleClose}>
      <p style={leadStyle}>
        ご質問や不具合のご報告をお送りください。内容を確認のうえ、返信先メールアドレスへご連絡します。
      </p>

      <form onSubmit={(event) => void handleSubmit(event)} style={formStyle}>
        {/* スパム対策 honeypot（視覚的に隠す） */}
        <label style={honeypotStyle} aria-hidden="true">
          会社名
          <input
            tabIndex={-1}
            autoComplete="off"
            value={honeypot}
            onChange={(event) => setHoneypot(event.target.value)}
          />
        </label>

        <label htmlFor="contact-category" style={labelStyle}>
          お問い合わせ種別
        </label>
        <select
          id="contact-category"
          value={category}
          onChange={(event) =>
            setCategory(event.target.value as ContactCategory | "")
          }
          required
          disabled={submitting}
          style={inputStyle}
        >
          <option value="">選択してください</option>
          {CATEGORY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        <label htmlFor="contact-subject" style={labelStyle}>
          件名（{MAX_SUBJECT}文字以内）
        </label>
        <input
          id="contact-subject"
          type="text"
          value={subject}
          maxLength={MAX_SUBJECT}
          onChange={(event) => setSubject(event.target.value)}
          required
          disabled={submitting}
          style={inputStyle}
        />

        <label htmlFor="contact-body" style={labelStyle}>
          お問い合わせ内容（{MAX_BODY}文字以内）
        </label>
        <textarea
          id="contact-body"
          value={body}
          maxLength={MAX_BODY}
          onChange={(event) => setBody(event.target.value)}
          required
          disabled={submitting}
          rows={6}
          style={{ ...inputStyle, resize: "vertical", minHeight: 120 }}
        />
        <div style={counterStyle}>
          {body.length} / {MAX_BODY}
        </div>

        <label htmlFor="contact-reply-email" style={labelStyle}>
          返信先メールアドレス
        </label>
        <input
          id="contact-reply-email"
          type="email"
          value={replyEmail}
          onChange={(event) => setReplyEmail(event.target.value)}
          required
          disabled={submitting}
          style={inputStyle}
          autoComplete="email"
        />

        {error ? (
          <p role="alert" style={errorStyle}>
            {error}
          </p>
        ) : null}
        {success ? (
          <p role="status" style={successStyle}>
            {success}
          </p>
        ) : null}

        <button
          type="submit"
          disabled={!canSubmit}
          style={{
            ...submitStyle,
            opacity: canSubmit ? 1 : 0.55,
            cursor: canSubmit ? "pointer" : "not-allowed",
          }}
        >
          {submitting ? "送信中…" : "送信する"}
        </button>
      </form>
    </SettingsSubScreen>
  );
}

const leadStyle: CSSProperties = {
  margin: "0 0 20px",
  fontSize: 14,
  color: "#666",
  lineHeight: 1.7,
};

const formStyle: CSSProperties = {
  display: "flex",
  flexDirection: "column",
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
  color: "#111",
  minHeight: 44,
};

const counterStyle: CSSProperties = {
  marginTop: -8,
  marginBottom: 14,
  fontSize: 12,
  color: "#999",
  textAlign: "right",
};

const submitStyle: CSSProperties = {
  marginTop: 8,
  minHeight: 48,
  border: "none",
  borderRadius: 12,
  background: ORANGE,
  color: "#fff",
  fontSize: 16,
  fontWeight: 700,
};

const errorStyle: CSSProperties = {
  margin: "0 0 12px",
  color: "#C0392B",
  fontSize: 13,
  lineHeight: 1.5,
};

const successStyle: CSSProperties = {
  margin: "0 0 12px",
  color: "#1F7A4D",
  fontSize: 13,
  lineHeight: 1.5,
};

const honeypotStyle: CSSProperties = {
  position: "absolute",
  left: -9999,
  width: 1,
  height: 1,
  overflow: "hidden",
};
