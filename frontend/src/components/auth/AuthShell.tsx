import type { CSSProperties, ReactNode } from "react";
import { APP_FONT_FAMILY } from "../PhoneMockFrame.tsx";

interface AuthShellProps {
  title: string;
  subtitle: string;
  children: ReactNode;
  footer?: ReactNode;
}

export function AuthShell({ title, subtitle, children, footer }: AuthShellProps) {
  return (
    <div
      style={{
        fontFamily: APP_FONT_FAMILY,
        flex: 1,
        height: "100%",
        minHeight: 0,
        background: "#fff",
        display: "flex",
        flexDirection: "column",
        alignItems: "center",
        justifyContent: "center",
        padding: "24px 20px",
        overflowY: "auto",
      }}
    >
      <div style={{ width: "100%", maxWidth: 360 }}>
        <h1
          style={{
            margin: "0 0 8px",
            fontSize: 24,
            fontWeight: 700,
            color: "#111",
            textAlign: "center",
          }}
        >
          {title}
        </h1>
        <p
          style={{
            margin: "0 0 32px",
            fontSize: 14,
            color: "#888",
            textAlign: "center",
            lineHeight: 1.5,
          }}
        >
          {subtitle}
        </p>
        {children}
        {footer}
      </div>
    </div>
  );
}

export const authInputStyle: CSSProperties = {
  width: "100%",
  boxSizing: "border-box",
  padding: "12px 14px",
  fontSize: 16,
  border: "1px solid #E0E0E0",
  borderRadius: 10,
  outline: "none",
};

export const authLabelStyle: CSSProperties = {
  display: "block",
  fontSize: 13,
  fontWeight: 600,
  color: "#555",
  marginBottom: 6,
};

export const authLinkButtonStyle: CSSProperties = {
  width: "100%",
  marginTop: 20,
  padding: 0,
  border: "none",
  background: "transparent",
  color: "#E8892B",
  fontSize: 14,
  fontWeight: 600,
  cursor: "pointer",
};
