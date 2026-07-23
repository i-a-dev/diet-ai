import type { ReactNode } from "react";

interface ModalStateViewportProps {
  children: ReactNode;
}

/**
 * 食事登録モーダルの SearchState 差し替え領域（残り高さを占有）。
 */
export function ModalStateViewport({ children }: ModalStateViewportProps) {
  return <div className="modal-state-viewport">{children}</div>;
}
