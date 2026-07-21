import { MessageCircle, PenLine, Settings, TrendingUp } from "lucide-react";
import { ORANGE } from "../constants.ts";

interface TabBarProps {
  active: number;
  onChange: (index: number) => void;
}

export function TabBar({ active, onChange }: TabBarProps) {
  const tabs = [
    { label: "相談する", icon: <MessageCircle size={20} /> },
    { label: "記録する", icon: <PenLine size={20} /> },
    { label: "記録を見る", icon: <TrendingUp size={20} /> },
    { label: "設定", icon: <Settings size={20} /> },
  ];

  return (
    <div
      style={{
        display: "flex",
        borderTop: "1px solid #F0F0F0",
        background: "#fff",
        paddingBottom: 25,
        paddingTop: 5,
        paddingLeft: 5,
        paddingRight: 7,
      }}
    >
      {tabs.map((tab, index) => (
        <div
          key={tab.label}
          onClick={() => onChange(index)}
          role="button"
          tabIndex={0}
          aria-label={tab.label}
          aria-current={index === active ? "page" : undefined}
          onKeyDown={(event) => {
            if (event.key === "Enter" || event.key === " ") {
              event.preventDefault();
              onChange(index);
            }
          }}
          style={{
            flex: 1,
            display: "flex",
            flexDirection: "column",
            alignItems: "center",
            paddingTop: 6,
            paddingBottom: 2,
            gap: 2,
            fontSize: 10,
            lineHeight: 1.2,
            color: index === active ? ORANGE : "#B0B0B0",
            cursor: "pointer",
            userSelect: "none",
          }}
        >
          <div style={{ color: index === active ? ORANGE : "#B0B0B0" }}>
            {tab.icon}
          </div>
          {tab.label}
        </div>
      ))}
    </div>
  );
}
