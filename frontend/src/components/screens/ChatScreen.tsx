import { memo, useCallback, useEffect, useRef, useState } from "react";
import { AssistantMessageEnter } from "../AssistantMessageEnter.tsx";
import { BubbleCoach } from "../BubbleCoach.tsx";
import { BubbleUser } from "../BubbleUser.tsx";
import { ChatMarkdown } from "../ChatMarkdown.tsx";
import { CoachAvatar } from "../CoachAvatar.tsx";
import {
  StreamingAssistantMessage,
  type StreamingAssistantHandle,
} from "../StreamingAssistantMessage.tsx";
import { TopNav } from "../TopNav.tsx";
import { UserMessageEnter } from "../UserMessageEnter.tsx";
import { ORANGE } from "../../constants.ts";
import {
  fetchChatMessages,
  fetchUserProfile,
  sendChatMessageStream,
  type ChatMessage,
} from "../../api/client.ts";

interface DisplayMessage extends ChatMessage {
  createdAtDate: Date;
  animateEnter?: boolean;
  clientKey?: string;
}

const SCROLL_BOTTOM_THRESHOLD_PX = 48;
const SCROLL_MIN_INTERVAL_MS = 60;

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

const AssistantHistoryMessage = memo(function AssistantHistoryMessage({
  message,
  animate,
  onScrollRequest,
}: {
  message: DisplayMessage;
  animate: boolean;
  onScrollRequest: () => void;
}) {
  return (
    <AssistantMessageEnter animate={animate} onTick={onScrollRequest}>
      <CoachAvatar />
      <div
        style={{
          flex: 1,
          minWidth: 0,
          maxWidth: "calc(100% - 50px)",
        }}
      >
        <BubbleCoach>
          <ChatMarkdown content={message.content} />
        </BubbleCoach>
      </div>
    </AssistantMessageEnter>
  );
});

const UserHistoryMessage = memo(function UserHistoryMessage({
  message,
  animate,
  onScrollRequest,
}: {
  message: DisplayMessage;
  animate: boolean;
  onScrollRequest: () => void;
}) {
  return (
    <UserMessageEnter animate={animate} onTick={onScrollRequest}>
      <BubbleUser>{renderPlainText(message.content)}</BubbleUser>
      <span style={{ fontSize: 11, color: "#C0C0C0" }}>
        {formatTime(message.createdAtDate)}
      </span>
    </UserMessageEnter>
  );
});

export function ChatScreen() {
  const [messages, setMessages] = useState<DisplayMessage[]>([]);
  const [input, setInput] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [isBootstrapping, setIsBootstrapping] = useState(true);
  const [error, setError] = useState<string | null>(null);
  /** ストリーミング中は一覧 content を更新せず、専用コンポーネントだけ描画する */
  const [streamingKey, setStreamingKey] = useState<string | null>(null);

  const scrollRef = useRef<HTMLDivElement>(null);
  const initialMessageIdsRef = useRef<Set<number> | null>(null);
  const shouldAutoScrollRef = useRef(true);
  const scrollFrameRef = useRef<number | null>(null);
  const lastScrollAtRef = useRef(0);
  const streamHandleRef = useRef<StreamingAssistantHandle | null>(null);
  const streamingKeyRef = useRef<string | null>(null);
  const pendingDeltasRef = useRef<string[]>([]);
  const pendingCompleteRef = useRef<ChatMessage | null>(null);

  const pushDeltaToStream = useCallback((text: string) => {
    if (streamHandleRef.current) {
      streamHandleRef.current.pushDelta(text);
      return;
    }
    pendingDeltasRef.current.push(text);
  }, []);

  const completeStream = useCallback((assistantMessage: ChatMessage) => {
    if (streamHandleRef.current) {
      streamHandleRef.current.complete(assistantMessage);
      return;
    }
    pendingCompleteRef.current = assistantMessage;
  }, []);

  const abortActiveStream = useCallback(() => {
    const handle = streamHandleRef.current;
    if (handle !== null) {
      handle.cancel();
    }
    streamHandleRef.current = null;
    pendingDeltasRef.current = [];
    pendingCompleteRef.current = null;
  }, []);

  const isNearBottom = useCallback(() => {
    const container = scrollRef.current;
    if (!container) {
      return true;
    }
    const distanceFromBottom =
      container.scrollHeight - container.scrollTop - container.clientHeight;
    return distanceFromBottom <= SCROLL_BOTTOM_THRESHOLD_PX;
  }, []);

  /**
   * 生成中のスクロールは instant のみ（smooth 不使用）。
   * 文字更新とは分離し、最大 60ms に1回。
   */
  const scrollToBottom = useCallback(
    (options?: { force?: boolean }) => {
      const force = options?.force === true;

      if (!force && !shouldAutoScrollRef.current) {
        return;
      }

      const now = performance.now();
      if (!force && now - lastScrollAtRef.current < SCROLL_MIN_INTERVAL_MS) {
        return;
      }

      const container = scrollRef.current;
      if (!container) {
        return;
      }

      if (scrollFrameRef.current !== null) {
        window.cancelAnimationFrame(scrollFrameRef.current);
      }

      scrollFrameRef.current = window.requestAnimationFrame(() => {
        scrollFrameRef.current = null;
        const el = scrollRef.current;
        if (!el) {
          return;
        }
        if (!force && !shouldAutoScrollRef.current) {
          return;
        }
        lastScrollAtRef.current = performance.now();
        el.scrollTop = el.scrollHeight;
      });
    },
    [],
  );

  const handleScroll = useCallback(() => {
    shouldAutoScrollRef.current = isNearBottom();
  }, [isNearBottom]);

  const shouldAnimateAssistantMessage = useCallback((messageId: number) => {
    if (initialMessageIdsRef.current === null) {
      return false;
    }
    return !initialMessageIdsRef.current.has(messageId);
  }, []);

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
    if (!isBootstrapping && initialMessageIdsRef.current === null) {
      initialMessageIdsRef.current = new Set(messages.map((message) => message.id));
    }
  }, [isBootstrapping, messages]);

  useEffect(() => {
    scrollToBottom({ force: true });
  }, [isBootstrapping, scrollToBottom]);

  useEffect(() => {
    return () => {
      if (scrollFrameRef.current !== null) {
        window.cancelAnimationFrame(scrollFrameRef.current);
      }
      abortActiveStream();
    };
  }, [abortActiveStream]);

  const handleStreamReady = useCallback((handle: StreamingAssistantHandle) => {
    streamHandleRef.current = handle;
    if (pendingDeltasRef.current.length > 0) {
      for (const delta of pendingDeltasRef.current) {
        handle.pushDelta(delta);
      }
      pendingDeltasRef.current = [];
    }
    if (pendingCompleteRef.current) {
      const message = pendingCompleteRef.current;
      pendingCompleteRef.current = null;
      handle.complete(message);
    }
  }, []);

  const handleStreamSettled = useCallback((assistantMessage: ChatMessage) => {
    initialMessageIdsRef.current?.add(assistantMessage.id);
    const key = streamingKeyRef.current;
    streamingKeyRef.current = null;
    streamHandleRef.current = null;
    setStreamingKey(null);
    setMessages((current) => [
      ...current,
      {
        ...toDisplayMessage(assistantMessage),
        animateEnter: false,
        clientKey: key ?? `assistant-${assistantMessage.id}`,
      },
    ]);
    setIsLoading(false);
    // 返信完了時は強制スクロールしない
  }, []);

  const handleSend = async () => {
    const trimmed = input.trim();
    if (!trimmed || isLoading || isBootstrapping || streamingKey !== null) {
      return;
    }

    const optimisticUserId = -Date.now();
    const userClientKey = `user-${optimisticUserId}`;
    const assistantClientKey = `assistant-${optimisticUserId}`;
    const now = new Date();
    const optimisticUserMessage: DisplayMessage = {
      id: optimisticUserId,
      role: "user",
      content: trimmed,
      createdAt: now.toISOString(),
      createdAtDate: now,
      animateEnter: true,
      clientKey: userClientKey,
    };

    shouldAutoScrollRef.current = true;
    streamingKeyRef.current = assistantClientKey;
    streamHandleRef.current = null;
    pendingDeltasRef.current = [];
    pendingCompleteRef.current = null;
    setMessages((current) => [...current, optimisticUserMessage]);
    setStreamingKey(assistantClientKey);
    setInput("");
    setError(null);
    setIsLoading(true);
    scrollToBottom({ force: true });

    try {
      await sendChatMessageStream(trimmed, {
        onUserMessage: (userMessage) => {
          initialMessageIdsRef.current?.add(userMessage.id);
          setMessages((current) =>
            current.map((message) =>
              message.clientKey === userClientKey
                ? {
                    ...toDisplayMessage(userMessage),
                    animateEnter: false,
                    clientKey: userClientKey,
                  }
                : message,
            ),
          );
        },
        onDelta: (text) => {
          // 親 setState はしない。専用バッファ／ハンドルへ積むだけ
          pushDeltaToStream(text);
        },
        onAssistantMessage: (assistantMessage) => {
          // 全文への即時切替はしない。表示追いつき後に settled で一覧へ反映
          completeStream(assistantMessage);
        },
        onError: (message) => {
          abortActiveStream();
          streamingKeyRef.current = null;
          setStreamingKey(null);
          setError(message);
          setIsLoading(false);
        },
      });
    } catch (sendError) {
      abortActiveStream();
      streamingKeyRef.current = null;
      setStreamingKey(null);
      setMessages((current) =>
        current.filter((message) => message.clientKey !== userClientKey),
      );
      const message =
        sendError instanceof Error
          ? sendError.message
          : "メッセージの送信に失敗しました";
      setError(message);
      setIsLoading(false);
    }
  };

  return (
    <div
      style={{
        position: "relative",
        flex: 1,
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
        minHeight: 0,
      }}
    >
      <TopNav title="AIコーチと相談" />
      <div
        ref={scrollRef}
        onScroll={handleScroll}
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
            <AssistantHistoryMessage
              key={message.clientKey ?? message.id}
              message={message}
              animate={
                message.animateEnter === true ||
                (message.animateEnter !== false &&
                  shouldAnimateAssistantMessage(message.id))
              }
              onScrollRequest={scrollToBottom}
            />
          ) : (
            <UserHistoryMessage
              key={message.clientKey ?? message.id}
              message={message}
              animate={message.animateEnter === true}
              onScrollRequest={scrollToBottom}
            />
          ),
        )}

        {streamingKey ? (
          <StreamingAssistantMessage
            key={streamingKey}
            onReady={handleStreamReady}
            onSettled={handleStreamSettled}
            onScrollRequest={scrollToBottom}
          />
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
          alignItems: "center",
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
            fontSize: 16,
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
    </div>
  );
}
