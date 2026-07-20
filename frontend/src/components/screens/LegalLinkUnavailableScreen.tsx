import type { CSSProperties } from "react";
import { SettingsSubScreen } from "../settings/SettingsSubScreen.tsx";
import type { LegalDocumentConfig } from "../../config/legalUrls.ts";

interface LegalLinkUnavailableScreenProps {
  open: boolean;
  document: LegalDocumentConfig | null;
  onClose: () => void;
}

/**
 * 法務ページの URL が未設定のときに表示する画面。
 * 仮の法的文章・事業者情報は生成しない。
 */
export function LegalLinkUnavailableScreen({
  open,
  document,
  onClose,
}: LegalLinkUnavailableScreenProps) {
  if (!open || document === null) {
    return null;
  }

  return (
    <SettingsSubScreen title={document.label} onClose={onClose}>
      <p style={paragraphStyle}>
        「{document.label}」の正式なページ URL がまだ設定されていません。
      </p>
      <p style={paragraphStyle}>
        {document.description}
        の本文をアプリ内に仮作成することはできません。公開前に環境変数で URL
        を設定してください。
      </p>
      <div style={todoBoxStyle}>
        <strong>TODO</strong>
        <p style={{ margin: "8px 0 0" }}>
          環境変数 <code style={codeStyle}>{document.envKey}</code>{" "}
          に https の URL を設定してください。
        </p>
      </div>
    </SettingsSubScreen>
  );
}

const paragraphStyle: CSSProperties = {
  margin: "0 0 14px",
  fontSize: 14,
  color: "#444",
  lineHeight: 1.7,
};

const todoBoxStyle: CSSProperties = {
  background: "#FFF8F0",
  border: "1px solid #F0D9BF",
  borderRadius: 10,
  padding: "12px 14px",
  fontSize: 13,
  color: "#7A5A2E",
  lineHeight: 1.6,
};

const codeStyle: CSSProperties = {
  fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace",
  fontSize: 12,
  background: "#F5E6D3",
  padding: "1px 4px",
  borderRadius: 4,
};
