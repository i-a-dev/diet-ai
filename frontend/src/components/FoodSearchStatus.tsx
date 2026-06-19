import type { CSSProperties } from "react";
import type { FoodSearchStep } from "../types/foodSearch.ts";

interface FoodSearchStatusProps {
  title?: string;
  steps: FoodSearchStep[];
}

export function FoodSearchStatus({ title = "食品データを検索中...", steps }: FoodSearchStatusProps) {
  return (
    <div style={cardStyle}>
      <div style={titleStyle}>{title}</div>
      <div style={{ display: "grid", gap: 8 }}>
        {steps.map((step) => (
          <div key={step.key} style={{ ...stepStyle, color: statusColor(step.status) }}>
            <span>{statusIcon(step.status)}</span>
            <span>{step.label}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function statusIcon(status: FoodSearchStep["status"]): string {
  switch (status) {
    case "active":
      return "●";
    case "done":
      return "✓";
    case "skipped":
      return "–";
    default:
      return "○";
  }
}

function statusColor(status: FoodSearchStep["status"]): string {
  switch (status) {
    case "active":
      return "#2E7D32";
    case "done":
      return "#4A5568";
    case "skipped":
      return "#A0AEC0";
    default:
      return "#94A3B8";
  }
}

const cardStyle: CSSProperties = {
  border: "1px solid #E5E7EB",
  borderRadius: 12,
  padding: "12px 14px",
  marginTop: 10,
  background: "#FAFAFA",
};

const titleStyle: CSSProperties = {
  fontSize: 14,
  fontWeight: 700,
  color: "#111827",
  marginBottom: 10,
};

const stepStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  gap: 8,
  fontSize: 13,
};
