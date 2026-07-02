import type { CSSProperties, ReactNode } from "react";
import { useMediaQuery } from "../hooks/useMediaQuery.ts";

export const APP_FONT_FAMILY =
  "-apple-system,BlinkMacSystemFont,'Hiragino Sans','Noto Sans JP',sans-serif";

/** モック内・実機共通のアプリ本体ラッパー */
export const phoneAppShellStyle: CSSProperties = {
  position: "relative",
  flex: 1,
  display: "flex",
  flexDirection: "column",
  overflow: "hidden",
  minHeight: 0,
};

interface PhoneMockFrameProps {
  children: ReactNode;
}

export function PhoneMockFrame({ children }: PhoneMockFrameProps) {
  const isDesktopPreview = useMediaQuery("(min-width: 768px)");

  if (isDesktopPreview) {
    return (
      <div
        style={{
          fontFamily: APP_FONT_FAMILY,
          background: "#F2F2F7",
          minHeight: "100vh",
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
          justifyContent: "center",
          padding: "40px 20px",
        }}
      >
        <div
          style={{
            fontSize: 12,
            color: "#999",
            letterSpacing: "0.05em",
            marginBottom: 16,
          }}
        >
          ダイエットアプリ - UIモックアップ
        </div>

        <div
          style={{
            width: 375,
            background: "#fff",
            borderRadius: 50,
            border: "8px solid #1a1a1a",
            overflow: "hidden",
            display: "flex",
            flexDirection: "column",
            boxShadow: "0 24px 64px rgba(0,0,0,0.20)",
            height: 800,
            position: "relative",
          }}
        >
          {children}
        </div>
      </div>
    );
  }

  return (
    <div
      style={{
        fontFamily: APP_FONT_FAMILY,
        background: "#fff",
        height: "100dvh",
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
        position: "relative",
      }}
    >
      {children}
    </div>
  );
}
