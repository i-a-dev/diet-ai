import type { CSSProperties, ReactNode } from "react";
import { ChevronLeft, X } from "lucide-react";

interface SettingsSubScreenProps {
  title: string;
  onClose: () => void;
  children: ReactNode;
  footer?: ReactNode;
}

/**
 * 設定内のフルスクリーン下層画面（ProfileSettingsSheet と同系統の見た目）。
 */
export function SettingsSubScreen({
  title,
  onClose,
  children,
  footer,
}: SettingsSubScreenProps) {
  return (
    <div style={rootStyle}>
      <div style={headerStyle}>
        <button
          type="button"
          onClick={onClose}
          aria-label="戻る"
          style={headerBtnStyle}
        >
          <ChevronLeft size={22} color="#111" strokeWidth={2} />
        </button>
        <span style={titleStyle}>{title}</span>
        <button
          type="button"
          onClick={onClose}
          aria-label="閉じる"
          style={headerBtnStyle}
        >
          <X size={22} color="#AAA" strokeWidth={2} />
        </button>
      </div>
      <div style={bodyStyle}>{children}</div>
      {footer ? <div style={footerStyle}>{footer}</div> : null}
    </div>
  );
}

const rootStyle: CSSProperties = {
  position: "absolute",
  inset: 0,
  zIndex: 50,
  background: "#F5F5F7",
  display: "flex",
  flexDirection: "column",
  overflow: "hidden",
};

const headerStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  minHeight: 44,
  padding: "0 12px",
  background: "#fff",
  borderBottom: "1px solid #F0F0F0",
  flexShrink: 0,
};

const headerBtnStyle: CSSProperties = {
  border: "none",
  background: "transparent",
  padding: 8,
  cursor: "pointer",
  display: "flex",
  alignItems: "center",
  minWidth: 44,
  minHeight: 44,
  justifyContent: "center",
};

const titleStyle: CSSProperties = {
  fontSize: 17,
  fontWeight: 600,
  color: "#111",
  lineHeight: "22px",
  textAlign: "center",
  flex: 1,
  minWidth: 0,
  padding: "0 4px",
  overflow: "hidden",
  textOverflow: "ellipsis",
  whiteSpace: "nowrap",
};

const bodyStyle: CSSProperties = {
  flex: 1,
  overflowY: "auto",
  padding: "20px 16px 24px",
};

const footerStyle: CSSProperties = {
  flexShrink: 0,
  padding: "12px 16px 20px",
  background: "#fff",
  borderTop: "1px solid #F0F0F0",
};
