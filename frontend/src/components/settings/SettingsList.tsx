import type { CSSProperties, ReactNode } from "react";
import { ChevronRight, ExternalLink } from "lucide-react";
import type { LucideIcon } from "lucide-react";

interface SettingsRowProps {
  label: string;
  description?: string;
  icon: LucideIcon;
  value?: string;
  danger?: boolean;
  external?: boolean;
  interactive?: boolean;
  onClick?: () => void;
  disabled?: boolean;
}

export function SettingsRow({
  label,
  description,
  icon: Icon,
  value,
  danger = false,
  external = false,
  interactive = true,
  onClick,
  disabled = false,
}: SettingsRowProps) {
  const labelColor = danger ? "#C0392B" : "#111";
  const iconColor = danger ? "#C0392B" : "#666";

  const content = (
    <>
      <Icon size={20} color={iconColor} aria-hidden />
      <span style={textWrapStyle}>
        <span style={{ ...labelStyle, color: labelColor }}>{label}</span>
        {description ? (
          <span style={descriptionStyle}>{description}</span>
        ) : null}
      </span>
      {value ? <span style={valueStyle}>{value}</span> : null}
      {interactive ? (
        external ? (
          <ExternalLink size={16} color="#BBB" aria-hidden />
        ) : (
          <ChevronRight size={18} color="#CCC" aria-hidden />
        )
      ) : null}
    </>
  );

  if (!interactive) {
    return (
      <div
        role="group"
        aria-label={value ? `${label}: ${value}` : label}
        style={rowStyle}
      >
        {content}
      </div>
    );
  }

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={external ? `${label}（外部サイト）` : label}
      style={{
        ...rowStyle,
        ...buttonResetStyle,
        cursor: disabled ? "not-allowed" : "pointer",
        opacity: disabled ? 0.55 : 1,
      }}
    >
      {content}
    </button>
  );
}

interface SettingsSectionProps {
  id?: string;
  title: string;
  children: ReactNode;
}

export function SettingsSection({ id, title, children }: SettingsSectionProps) {
  const headingId = `settings-section-${id ?? title}`;
  return (
    <section style={sectionStyle} aria-labelledby={headingId}>
      <h2 id={headingId} style={sectionTitleStyle}>
        {title}
      </h2>
      <div style={sectionBodyStyle}>{children}</div>
    </section>
  );
}

const buttonResetStyle: CSSProperties = {
  border: "none",
  background: "transparent",
  textAlign: "left",
  font: "inherit",
  width: "100%",
};

const rowStyle: CSSProperties = {
  width: "100%",
  display: "flex",
  alignItems: "center",
  gap: 12,
  padding: "14px 16px",
  minHeight: 52,
  boxSizing: "border-box",
};

const textWrapStyle: CSSProperties = {
  flex: 1,
  minWidth: 0,
  display: "flex",
  flexDirection: "column",
  gap: 2,
};

const labelStyle: CSSProperties = {
  fontSize: 15,
  fontWeight: 500,
  lineHeight: 1.4,
  wordBreak: "break-word",
};

const descriptionStyle: CSSProperties = {
  fontSize: 12,
  color: "#999",
  lineHeight: 1.4,
  wordBreak: "break-word",
};

const valueStyle: CSSProperties = {
  fontSize: 13,
  color: "#888",
  flexShrink: 0,
  maxWidth: "42%",
  textAlign: "right",
  wordBreak: "break-word",
};

const sectionStyle: CSSProperties = {
  marginBottom: 8,
};

const sectionTitleStyle: CSSProperties = {
  margin: 0,
  padding: "16px 16px 8px",
  fontSize: 13,
  fontWeight: 600,
  color: "#888",
  letterSpacing: "0.02em",
};

const sectionBodyStyle: CSSProperties = {
  background: "#fff",
  borderTop: "1px solid #F0F0F0",
  borderBottom: "1px solid #F0F0F0",
};
