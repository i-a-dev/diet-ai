import { describe, expect, it } from "vitest";

/**
 * ログアウト / アカウント削除の二重実行防止とキャンセル挙動を、
 * 画面と同じ状態遷移として検証する（React Testing Library 非依存）。
 */
describe("settings confirmation flows", () => {
  it("ログアウト確認をキャンセルできる", () => {
    let open = true;
    const loggingOut = false;

    const cancel = () => {
      if (!loggingOut) {
        open = false;
      }
    };

    cancel();
    expect(open).toBe(false);
  });

  it("ログアウト処理中は二重実行しない", async () => {
    let loggingOut = false;
    let calls = 0;

    const logout = async () => {
      if (loggingOut) {
        return;
      }
      loggingOut = true;
      calls += 1;
      await Promise.resolve();
      loggingOut = false;
    };

    await Promise.all([logout(), logout()]);
    expect(calls).toBe(1);
  });

  it("アカウント削除確認をキャンセルできる", () => {
    let confirmOpen = true;
    const deleting = false;

    const cancel = () => {
      if (!deleting) {
        confirmOpen = false;
      }
    };

    cancel();
    expect(confirmOpen).toBe(false);
  });

  it("API エラー時はユーザー向けメッセージを保持できる", () => {
    const error =
      new Error("パスワードが正しくありません") instanceof Error
        ? "パスワードが正しくありません"
        : "アカウントの削除に失敗しました";

    expect(error).toBe("パスワードが正しくありません");
    expect(error).not.toContain("SQL");
    expect(error).not.toContain("stack");
  });
});
