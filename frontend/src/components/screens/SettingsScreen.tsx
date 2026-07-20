import { useCallback, useMemo, useState } from "react";
import { ProfileSettingsSheet } from "../ProfileSettingsSheet.tsx";
import { TopNav } from "../TopNav.tsx";
import { ConfirmDialog } from "../ConfirmDialog.tsx";
import { SettingsRow, SettingsSection } from "../settings/SettingsList.tsx";
import { ContactScreen } from "./ContactScreen.tsx";
import { DeleteAccountScreen } from "./DeleteAccountScreen.tsx";
import { SubscriptionManageScreen } from "./SubscriptionManageScreen.tsx";
import { LegalLinkUnavailableScreen } from "./LegalLinkUnavailableScreen.tsx";
import { useAuth } from "../../contexts/AuthContext.tsx";
import { formatAppVersionLabel } from "../../config/appVersion.ts";
import {
  getLegalDocument,
  openExternalUrl,
  type LegalDocumentConfig,
  type LegalDocumentKey,
} from "../../config/legalUrls.ts";
import {
  buildSettingsSections,
  type SettingsInternalView,
  type SettingsItemDefinition,
} from "../../settings/settingsCatalog.ts";

interface SettingsScreenProps {
  onProfileUpdated?: () => void;
}

export function SettingsScreen({ onProfileUpdated }: SettingsScreenProps) {
  const { user, logout } = useAuth();
  const [profileOpen, setProfileOpen] = useState(false);
  const [contactOpen, setContactOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [subscriptionOpen, setSubscriptionOpen] = useState(false);
  const [logoutConfirmOpen, setLogoutConfirmOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [logoutError, setLogoutError] = useState<string | null>(null);
  const [legalUnavailable, setLegalUnavailable] =
    useState<LegalDocumentConfig | null>(null);

  const versionLabel = useMemo(() => formatAppVersionLabel(), []);
  const sections = useMemo(
    () => buildSettingsSections(versionLabel),
    [versionLabel],
  );

  const openInternal = useCallback((view: SettingsInternalView) => {
    if (view === "profile") {
      setProfileOpen(true);
      return;
    }
    if (view === "contact") {
      setContactOpen(true);
      return;
    }
    if (view === "deleteAccount") {
      setDeleteOpen(true);
      return;
    }
    if (view === "subscription") {
      setSubscriptionOpen(true);
    }
  }, []);

  const openLegal = useCallback((documentKey: LegalDocumentKey) => {
    const document = getLegalDocument(documentKey);
    if (document === null) {
      return;
    }
    if (document.url && openExternalUrl(document.url)) {
      return;
    }
    setLegalUnavailable(document);
  }, []);

  const handleItemClick = useCallback(
    (item: SettingsItemDefinition) => {
      const { action } = item;
      if (action.type === "internal") {
        openInternal(action.view);
        return;
      }
      if (action.type === "logout") {
        setLogoutError(null);
        setLogoutConfirmOpen(true);
        return;
      }
      if (action.type === "legal") {
        openLegal(action.documentKey);
      }
    },
    [openInternal, openLegal],
  );

  const handleLogoutConfirm = useCallback(async () => {
    if (loggingOut) {
      return;
    }
    setLoggingOut(true);
    setLogoutError(null);
    try {
      await logout();
      setLogoutConfirmOpen(false);
    } catch (error) {
      setLogoutError(
        error instanceof Error ? error.message : "ログアウトに失敗しました",
      );
    } finally {
      setLoggingOut(false);
    }
  }, [loggingOut, logout]);

  const handleAccountDeleted = useCallback(() => {
    // トークンは DeleteAccountScreen 側でクリア済み。Auth 状態を合わせて未認証画面へ。
    void logout();
  }, [logout]);

  return (
    <div
      style={{
        flex: 1,
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
        minHeight: 0,
        background: "#F5F5F7",
        position: "relative",
      }}
    >
      <TopNav title="設定" />

      <div style={{ flex: 1, overflowY: "auto", paddingBottom: 24 }}>
        {user?.email ? (
          <div
            style={{
              padding: "12px 16px 4px",
              fontSize: 12,
              color: "#999",
            }}
          >
            {user.email}
          </div>
        ) : null}

        {sections.map((section) => (
          <SettingsSection key={section.id} id={section.id} title={section.title}>
            {section.items.map((item) => (
              <SettingsRow
                key={item.id}
                label={item.label}
                description={item.description}
                icon={item.icon}
                value={item.value}
                danger={item.danger}
                external={item.external}
                interactive={item.interactive !== false}
                onClick={() => handleItemClick(item)}
              />
            ))}
          </SettingsSection>
        ))}
      </div>

      <ConfirmDialog
        open={logoutConfirmOpen}
        title="ログアウト"
        message="ログアウトしますか？"
        confirmLabel="ログアウト"
        confirming={loggingOut}
        onCancel={() => {
          if (!loggingOut) {
            setLogoutConfirmOpen(false);
            setLogoutError(null);
          }
        }}
        onConfirm={() => void handleLogoutConfirm()}
      >
        {logoutError ? (
          <p
            role="alert"
            style={{
              margin: "-8px 0 14px",
              color: "#C0392B",
              fontSize: 13,
            }}
          >
            {logoutError}
          </p>
        ) : null}
      </ConfirmDialog>

      <ProfileSettingsSheet
        open={profileOpen}
        onClose={() => setProfileOpen(false)}
        onSaved={() => {
          setProfileOpen(false);
          onProfileUpdated?.();
        }}
      />

      <ContactScreen
        open={contactOpen}
        onClose={() => setContactOpen(false)}
      />

      <DeleteAccountScreen
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onDeleted={handleAccountDeleted}
      />

      <SubscriptionManageScreen
        open={subscriptionOpen}
        onClose={() => setSubscriptionOpen(false)}
      />

      <LegalLinkUnavailableScreen
        open={legalUnavailable !== null}
        document={legalUnavailable}
        onClose={() => setLegalUnavailable(null)}
      />
    </div>
  );
}
