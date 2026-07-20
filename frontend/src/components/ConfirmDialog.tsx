import { useEffect, useId, useRef, type CSSProperties, type ReactNode } from "react";

interface ConfirmDialogProps {
  open: boolean;
  title: string;
  message: string;
  confirmLabel: string;
  cancelLabel?: string;
  confirmDanger?: boolean;
  confirming?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  children?: ReactNode;
}

export function ConfirmDialog({
  open,
  title,
  message,
  confirmLabel,
  cancelLabel = "キャンセル",
  confirmDanger = false,
  confirming = false,
  onConfirm,
  onCancel,
  children,
}: ConfirmDialogProps) {
  const titleId = useId();
  const messageId = useId();
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) {
      return;
    }
    cancelRef.current?.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !confirming) {
        onCancel();
      }
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [open, confirming, onCancel]);

  if (!open) {
    return null;
  }

  return (
    <div style={overlayStyle} role="presentation">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={messageId}
        style={dialogStyle}
      >
        <h2 id={titleId} style={titleStyle}>
          {title}
        </h2>
        <p id={messageId} style={messageStyle}>
          {message}
        </p>
        {children}
        <div style={actionsStyle}>
          <button
            ref={cancelRef}
            type="button"
            onClick={onCancel}
            disabled={confirming}
            style={cancelBtnStyle}
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={confirming}
            style={{
              ...confirmBtnStyle,
              ...(confirmDanger ? dangerConfirmStyle : {}),
              opacity: confirming ? 0.6 : 1,
            }}
          >
            {confirming ? "処理中…" : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

const overlayStyle: CSSProperties = {
  position: "absolute",
  inset: 0,
  zIndex: 80,
  background: "rgba(0,0,0,0.4)",
  display: "flex",
  alignItems: "center",
  justifyContent: "center",
  padding: 24,
};

const dialogStyle: CSSProperties = {
  width: "100%",
  maxWidth: 320,
  background: "#fff",
  borderRadius: 14,
  padding: "20px 18px 16px",
  boxShadow: "0 8px 28px rgba(0,0,0,0.18)",
};

const titleStyle: CSSProperties = {
  margin: "0 0 10px",
  fontSize: 17,
  fontWeight: 700,
  color: "#111",
};

const messageStyle: CSSProperties = {
  margin: "0 0 18px",
  fontSize: 14,
  lineHeight: 1.6,
  color: "#444",
};

const actionsStyle: CSSProperties = {
  display: "flex",
  gap: 10,
};

const cancelBtnStyle: CSSProperties = {
  flex: 1,
  minHeight: 44,
  border: "1px solid #DDD",
  borderRadius: 10,
  background: "#fff",
  color: "#333",
  fontSize: 15,
  fontWeight: 600,
  cursor: "pointer",
};

const confirmBtnStyle: CSSProperties = {
  flex: 1,
  minHeight: 44,
  border: "none",
  borderRadius: 10,
  background: "#E8892B",
  color: "#fff",
  fontSize: 15,
  fontWeight: 600,
  cursor: "pointer",
};

const dangerConfirmStyle: CSSProperties = {
  background: "#C0392B",
};
