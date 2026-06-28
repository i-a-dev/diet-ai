import { useCallback, useEffect, useRef, useState } from "react";
import { BubbleCoach } from "../BubbleCoach.tsx";
import { BubbleUser } from "../BubbleUser.tsx";
import { ChatMarkdown } from "../ChatMarkdown.tsx";
import { CoachAvatar } from "../CoachAvatar.tsx";
import { TopNav } from "../TopNav.tsx";
import { ORANGE } from "../../constants.ts";
import {
  fetchChatMessages,
  fetchUserProfile,
  sendChatMessage,
  type ChatMessage,
} from "../../api/client.ts";

interface DisplayMessage extends ChatMessage {
  createdAtDate: Date;
}

function formatTime(date: Date): string {
  return date.toLocaleTimeString("ja-JP", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

function toDisplayMessage(message: ChatMessage): DisplayMessage {
  return {
    ...message,
    createdAtDate: new Date(message.createdAt),
  };
}

function buildWelcomeMessage(targetWeightKg: number | null): string {
  const goalLine =
    targetWeightKg !== null
      ? `目標体重は${targetWeightKg}kgですね。`
      : "目標体重がまだ未設定なら、設定画面から登録しておくと相談しやすくなります。";

  return `あなた専属のAIコーチです！\nいつでも気軽に相談してくださいね！\n${goalLine}\n記録した体重・食事・運動・歩数を見ながら、一緒に考えます。`;
}

function renderPlainText(content: string) {
  return content.split("\n").map((line, index, lines) => (
    <span key={`${index}-${line}`}>
      {line}
      {index < lines.length - 1 ? <br /> : null}
    </span>
  ));
}

export function ChatScreen() {
  const [messages, setMessages] = useState<DisplayMessage[]>([]);
  const [input, setInput] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [isBootstrapping, setIsBootstrapping] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  const createWelcomeMessage = useCallback(
    (targetWeightKg: number | null): DisplayMessage => ({
      id: 0,
      role: "assistant",
      content: buildWelcomeMessage(targetWeightKg),
      createdAt: new Date().toISOString(),
      createdAtDate: new Date(),
    }),
    [],
  );

  useEffect(() => {
    let cancelled = false;

    async function bootstrap() {
      try {
        const [historyResponse, profileResponse] = await Promise.all([
          fetchChatMessages(),
          fetchUserProfile(),
        ]);

        if (cancelled) {
          return;
        }

        if (historyResponse.messages.length > 0) {
          setMessages(historyResponse.messages.map(toDisplayMessage));
          return;
        }

        setMessages([
          createWelcomeMessage(profileResponse.profile.targetWeightKg),
        ]);
      } catch {
        if (!cancelled) {
          setMessages([createWelcomeMessage(null)]);
        }
      } finally {
        if (!cancelled) {
          setIsBootstrapping(false);
        }
      }
    }

    void bootstrap();

    return () => {
      cancelled = true;
    };
  }, [createWelcomeMessage]);

  useEffect(() => {
    const container = scrollRef.current;
    if (!container) {
      return;
    }
    container.scrollTop = container.scrollHeight;
  }, [messages, isLoading]);

  const handleSend = async () => {
    const trimmed = input.trim();
    if (!trimmed || isLoading || isBootstrapping) {
      return;
    }

    const optimisticUserMessage: DisplayMessage = {
      id: -Date.now(),
      role: "user",
      content: trimmed,
      createdAt: new Date().toISOString(),
      createdAtDate: new Date(),
    };

    setMessages((current) => [...current, optimisticUserMessage]);
    setInput("");
    setError(null);
    setIsLoading(true);

    try {
      const { userMessage, assistantMessage } = await sendChatMessage(trimmed);
      setMessages((current) => [
        ...current.filter((message) => message.id !== optimisticUserMessage.id),
        toDisplayMessage(userMessage),
        toDisplayMessage(assistantMessage),
      ]);
    } catch (sendError) {
      setMessages((current) =>
        current.filter((message) => message.id !== optimisticUserMessage.id),
      );
      const message =
        sendError instanceof Error
          ? sendError.message
          : "メッセージの送信に失敗しました";
      setError(message);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <>
      <TopNav title="AIコーチと相談" />
      <div
        ref={scrollRef}
        style={{
          flex: 1,
          padding: "14px 16px",
          display: "flex",
          flexDirection: "column",
          gap: 14,
          overflowY: "auto",
          background: "#fff",
        }}
      >
        {messages.map((message) =>
          message.role === "assistant" ? (
            <div
              key={message.id}
              style={{ display: "flex", gap: 10, alignItems: "flex-start" }}
            >
              <CoachAvatar />
              <div
                style={{
                  display: "flex",
                  flexDirection: "column",
                  gap: 4,
                  flex: 1,
                  minWidth: 0,
                  maxWidth: "calc(100% - 50px)",
                }}
              >
                <span style={{ fontSize: 11, color: ORANGE, fontWeight: 600 }}>
                  AIコーチ {formatTime(message.createdAtDate)}
                </span>
                <BubbleCoach>
                  <ChatMarkdown content={message.content} />
                </BubbleCoach>
              </div>
            </div>
          ) : (
            <div
              key={message.id}
              style={{
                display: "flex",
                flexDirection: "column",
                alignItems: "flex-end",
                gap: 3,
              }}
            >
              <BubbleUser>{renderPlainText(message.content)}</BubbleUser>
              <span style={{ fontSize: 11, color: "#C0C0C0" }}>
                {formatTime(message.createdAtDate)}
              </span>
            </div>
          ),
        )}

        {isLoading ? (
          <div style={{ display: "flex", gap: 10, alignItems: "flex-start" }}>
            <CoachAvatar />
            <div
              style={{
                display: "flex",
                flexDirection: "column",
                gap: 4,
                flex: 1,
                minWidth: 0,
                maxWidth: "calc(100% - 50px)",
              }}
            >
              <span style={{ fontSize: 11, color: ORANGE, fontWeight: 600 }}>
                AIコーチ
              </span>
              <BubbleCoach>考え中...</BubbleCoach>
            </div>
          </div>
        ) : null}

        {error ? (
          <div style={{ fontSize: 12, color: "#D64545", textAlign: "center" }}>
            {error}
          </div>
        ) : null}
      </div>

      <div
        style={{
          display: "flex",
          alignItems: "flex-end",
          gap: 8,
          padding: "5px 16px 4px",
          background: "#fff",
          borderTop: "1px solid #F0F0F0",
        }}
      >
        <textarea
          value={input}
          onChange={(event) => setInput(event.target.value)}
          placeholder="メッセージを入力..."
          rows={1}
          disabled={isLoading || isBootstrapping}
          style={{
            flex: 1,
            background: "#F5F5F5",
            borderRadius: 14,
            border: "none",
            padding: "8px 12px",
            fontSize: 12,
            color: "#222",
            lineHeight: 1.4,
            resize: "none",
            fontFamily: "inherit",
            outline: "none",
            minHeight: 36,
            maxHeight: 120,
          }}
        />
        <button
          type="button"
          onClick={() => void handleSend()}
          disabled={isLoading || isBootstrapping || input.trim() === ""}
          style={{
            background:
              isLoading || isBootstrapping || input.trim() === ""
                ? "#F0C9A0"
                : ORANGE,
            color: "#fff",
            border: "none",
            borderRadius: 14,
            padding: "6px 14px",
            fontSize: 12,
            fontWeight: 600,
            cursor:
              isLoading || isBootstrapping || input.trim() === ""
                ? "not-allowed"
                : "pointer",
            lineHeight: 1.4,
          }}
        >
          送信
        </button>
      </div>
    </>
  );
}
