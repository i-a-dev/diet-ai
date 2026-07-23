import type { CSSProperties } from "react";
import { ORANGE } from "../constants.ts";

/** 食事登録モーダルの操作ボタン共通スタイル（カード外配置用） */
export const modalPrimaryActionStyle: CSSProperties = {
  width: "100%",
  minHeight: 48,
  border: "none",
  borderRadius: 10,
  background: ORANGE,
  color: "#fff",
  fontWeight: 700,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
  boxSizing: "border-box",
};

export const modalSecondaryActionStyle: CSSProperties = {
  width: "100%",
  border: "1px solid #FDBA74",
  borderRadius: 10,
  background: "#fff",
  color: "#C2410C",
  fontWeight: 700,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
};

export const modalOutlineActionStyle: CSSProperties = {
  width: "100%",
  border: "1px solid #FDBA74",
  borderRadius: 10,
  background: "#fff",
  color: "#C2410C",
  fontWeight: 700,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
};

export const modalNeutralActionStyle: CSSProperties = {
  width: "100%",
  border: "1px solid #E5E7EB",
  borderRadius: 10,
  background: "#fff",
  color: "#4B5563",
  fontWeight: 600,
  fontSize: 14,
  padding: "11px 12px",
  cursor: "pointer",
};

export const modalCancelActionStyle: CSSProperties = {
  width: "100%",
  border: "1px solid #D1D5DB",
  background: "#fff",
  borderRadius: 10,
  padding: "11px 12px",
  color: "#374151",
  fontWeight: 700,
  fontSize: 14,
  cursor: "pointer",
};

export const modalTextActionStyle: CSSProperties = {
  width: "100%",
  border: "none",
  borderRadius: 10,
  background: "transparent",
  color: "#6B7280",
  fontWeight: 600,
  fontSize: 13,
  padding: "8px 12px",
  cursor: "pointer",
};

export const modalLinkActionStyle: CSSProperties = {
  width: "100%",
  border: "none",
  background: "transparent",
  color: "#6B7280",
  fontSize: 13,
  fontWeight: 600,
  padding: "8px 4px",
  cursor: "pointer",
  textDecoration: "underline",
  textUnderlineOffset: 2,
};
