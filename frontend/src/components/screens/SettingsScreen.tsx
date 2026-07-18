import { useState } from "react";
import { LogOut, User } from "lucide-react";
import { ProfileSettingsSheet } from "../ProfileSettingsSheet.tsx";
import { TopNav } from "../TopNav.tsx";
import { useAuth } from "../../contexts/AuthContext.tsx";

interface SettingsScreenProps {
  onProfileUpdated?: () => void;
}

export function SettingsScreen({ onProfileUpdated }: SettingsScreenProps) {
  const [settingsOpen, setSettingsOpen] = useState(false);
  const { user, logout } = useAuth();

  return (
    <div
      style={{
        flex: 1,
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
        minHeight: 0,
        background: "#fff",
      }}
    >
      <TopNav title="設定" />

      <div style={{ flex: 1, display: "flex", flexDirection: "column", overflowY: "auto" }}>
        <div style={{ padding: "8px 0" }}>
          <button
            type="button"
            onClick={() => setSettingsOpen(true)}
            style={{
              width: "100%",
              display: "flex",
              alignItems: "center",
              gap: 12,
              padding: "14px 16px",
              border: "none",
              background: "transparent",
              cursor: "pointer",
              textAlign: "left",
            }}
          >
            <User size={20} color="#666" />
            <span style={{ fontSize: 15, color: "#111", fontWeight: 500 }}>プロフィール</span>
          </button>
        </div>

        {user?.email && (
          <div
            style={{
              padding: "12px 16px",
              fontSize: 12,
              color: "#999",
              borderTop: "1px solid #F0F0F0",
            }}
          >
            {user.email}
          </div>
        )}

        <div style={{ marginTop: "auto", padding: "8px 0", borderTop: "1px solid #F0F0F0" }}>
          <button
            type="button"
            onClick={() => void logout()}
            style={{
              width: "100%",
              display: "flex",
              alignItems: "center",
              gap: 12,
              padding: "14px 16px",
              border: "none",
              background: "transparent",
              cursor: "pointer",
              textAlign: "left",
            }}
          >
            <LogOut size={20} color="#666" />
            <span style={{ fontSize: 15, color: "#111", fontWeight: 500 }}>ログアウト</span>
          </button>
        </div>
      </div>

      <ProfileSettingsSheet
        open={settingsOpen}
        onClose={() => setSettingsOpen(false)}
        onSaved={() => {
          setSettingsOpen(false);
          onProfileUpdated?.();
        }}
      />
    </div>
  );
}
