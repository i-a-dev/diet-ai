import type { ReactNode } from "react";

export type StateContentMode =
  | "top"
  | "center"
  | "searching"
  | "fill"
  | "scroll";

interface ModalStateLayoutProps {
  content: ReactNode;
  actions?: ReactNode;
  /** stateContent 内の配置 */
  contentMode?: StateContentMode;
  /**
   * true: 操作を下部固定（stateActions）
   * false: 操作を content 末尾に置き、一緒にスクロール（操作が多い画面向け）
   */
  stickyActions?: boolean;
}

/**
 * 食事登録モーダルの状態表示: 情報領域 + 操作領域。
 */
export function ModalStateLayout({
  content,
  actions,
  contentMode = "top",
  stickyActions = true,
}: ModalStateLayoutProps) {
  const hasStickyActions = stickyActions && actions != null;
  const wrapCenter = contentMode === "center" || contentMode === "searching";
  const innerClassName =
    contentMode === "searching"
      ? "modal-state-content-searching-inner"
      : "modal-state-content-center-inner";

  return (
    <div className="modal-state-layout">
      <div
        className={`modal-state-content modal-state-content--${contentMode}`}
      >
        {wrapCenter ? (
          <div className={innerClassName}>{content}</div>
        ) : (
          content
        )}
        {!stickyActions && actions != null ? (
          <div className="modal-state-actions modal-state-actions--inline">
            {actions}
          </div>
        ) : null}
      </div>
      {hasStickyActions ? (
        <div className="modal-state-actions">{actions}</div>
      ) : null}
    </div>
  );
}
