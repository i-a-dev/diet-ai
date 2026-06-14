import type { ReactNode } from "react";
import { Menu } from "lucide-react";

interface TopNavProps {
  title: string;
  rightIcon: ReactNode;
}

export function TopNav({ title, rightIcon }: TopNavProps) {
  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        justifyContent: "space-between",
        minHeight: 44,
        padding: "0 20px",
        background: "#fff",
        borderBottom: "1px solid #F0F0F0",
      }}
    >
      <Menu size={22} color="#C0C0C0" style={{ display: "block" }} />
      <span
        style={{
          fontSize: 17,
          fontWeight: 600,
          color: "#111",
          lineHeight: "22px",
        }}
      >
        {title}
      </span>
      <span style={{ display: "flex", alignItems: "center" }}>{rightIcon}</span>
    </div>
  );
}
