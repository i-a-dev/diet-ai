import {
  memo,
  useCallback,
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
} from "react";
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
  isAbortError,
  sendChatMessageStream,
  type ChatMessage,
} from "../../api/client.ts";

interface DisplayMessage extends ChatMessage {
  createdAtDate: Date;
  animateEnter?: boolean;
  clientKey?: string;
}

const SCROLL_BOTTOM_THRESHOLD_PX = 48;
const STREAM_RESUME_THRESHOLD_PX = 96;
const INITIAL_SETTLE_DISTANCE_PX = 2;
const INITIAL_SETTLE_TIMEOUT_MS = 180;

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
}: {
  message: DisplayMessage;
  animate: boolean;
}) {
  return (
    <AssistantMessageEnter animate={animate}>
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
}: {
  message: DisplayMessage;
  animate: boolean;
}) {
  return (
    <UserMessageEnter animate={animate}>
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
  const messagesContentRef = useRef<HTMLDivElement>(null);
  const initialMessageIdsRef = useRef<Set<number> | null>(null);

  const shouldFollowBottomRef = useRef(true);
  const isInitialScrollPendingRef = useRef(true);
  const isProgrammaticScrollRef = useRef(false);
  const userInterruptedFollowRef = useRef(false);
  const isStreamingRef = useRef(false);
  const isUserInteractingRef = useRef(false);
  const isBootstrappingRef = useRef(true);

  const scrollFrameRef = useRef<number | null>(null);
  const pendingForceScrollRef = useRef(false);
  const programmaticClearFrameRef = useRef<number | null>(null);
  const lastContentHeightRef = useRef(0);
  const stableHeightFramesRef = useRef(0);
  const initialSettleTimerRef = useRef<number | null>(null);
  /** 初回履歴の初期下端追従を一度だけ開始したか */
  const initialHistoryScrollStartedRef = useRef(false);

  const streamHandleRef = useRef<StreamingAssistantHandle | null>(null);
  const streamingKeyRef = useRef<string | null>(null);
  const pendingDeltasRef = useRef<string[]>([]);
  const pendingCompleteRef = useRef<ChatMessage | null>(null);
  const abortControllerRef = useRef<AbortController | null>(null);
  const stoppedByUserRef = useRef(false);

  isBootstrappingRef.current = isBootstrapping;

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
    isStreamingRef.current = false;
    pendingDeltasRef.current = [];
    pendingCompleteRef.current = null;
  }, []);

  const resetStreamingUi = useCallback(() => {
    abortActiveStream();
    streamingKeyRef.current = null;
    setStreamingKey(null);
    setIsLoading(false);
  }, [abortActiveStream]);

  const handleStop = useCallback(() => {
    if (!isLoading && streamingKey === null) {
      return;
    }

    stoppedByUserRef.current = true;
    abortControllerRef.current?.abort();
    abortControllerRef.current = null;

    const handle = streamHandleRef.current;
    if (handle !== null) {
      const settledPartial = handle.stop();
      if (!settledPartial) {
        resetStreamingUi();
      }
      return;
    }

    // onReady 前に停止した場合: バッファ済み delta があれば履歴へ確定
    const pendingText = pendingDeltasRef.current.join("");
    pendingDeltasRef.current = [];
    pendingCompleteRef.current = null;

    if (pendingText !== "") {
      const key = streamingKeyRef.current;
      const now = new Date();
      streamingKeyRef.current = null;
      streamHandleRef.current = null;
      isStreamingRef.current = false;
      setStreamingKey(null);
      setMessages((current) => [
        ...current,
        {
          id: -Date.now(),
          role: "assistant",
          content: pendingText,
          createdAt: now.toISOString(),
          createdAtDate: now,
          animateEnter: false,
          clientKey: key ?? `assistant-stopped-${now.getTime()}`,
        },
      ]);
      setIsLoading(false);
      return;
    }

    resetStreamingUi();
  }, [isLoading, streamingKey, resetStreamingUi]);

  const markUserInteracting = useCallback(() => {
    isUserInteractingRef.current = true;
  }, []);

  /**
   * スクロール要求を破棄しない。同一フレーム内は次の rAF で1回に統合。
   * smooth / scrollIntoView は使わない。
   */
  const scheduleScrollToBottom = useCallback(
    ({ force = false }: { force?: boolean } = {}) => {
      pendingForceScrollRef.current ||= force;

      if (scrollFrameRef.current !== null) {
        return;
      }

      scrollFrameRef.current = window.requestAnimationFrame(() => {
        scrollFrameRef.current = null;

        const container = scrollRef.current;
        if (!container) {
          pendingForceScrollRef.current = false;
          return;
        }

        const shouldScroll =
          pendingForceScrollRef.current ||
          isInitialScrollPendingRef.current ||
          (isStreamingRef.current && !userInterruptedFollowRef.current) ||
          shouldFollowBottomRef.current;

        pendingForceScrollRef.current = false;

        if (!shouldScroll) {
          return;
        }

        if (programmaticClearFrameRef.current !== null) {
          window.cancelAnimationFrame(programmaticClearFrameRef.current);
          programmaticClearFrameRef.current = null;
        }

        isProgrammaticScrollRef.current = true;
        container.scrollTop = container.scrollHeight;

        programmaticClearFrameRef.current = window.requestAnimationFrame(() => {
          programmaticClearFrameRef.current = null;
          isProgrammaticScrollRef.current = false;

          const distanceFromBottom =
            container.scrollHeight -
            container.scrollTop -
            container.clientHeight;

          if (distanceFromBottom <= INITIAL_SETTLE_DISTANCE_PX) {
            shouldFollowBottomRef.current = true;

            if (
              !isStreamingRef.current &&
              !isBootstrappingRef.current &&
              isInitialScrollPendingRef.current
            ) {
              const height = container.scrollHeight;
              if (height === lastContentHeightRef.current) {
                stableHeightFramesRef.current += 1;
              } else {
                lastContentHeightRef.current = height;
                stableHeightFramesRef.current = 0;
              }

              if (stableHeightFramesRef.current >= 2) {
                isInitialScrollPendingRef.current = false;
                userInterruptedFollowRef.current = false;
              }
            }
          } else if (isInitialScrollPendingRef.current) {
            stableHeightFramesRef.current = 0;
          }
        });
      });
    },
    [],
  );

  const handleScroll = useCallback(() => {
    if (isProgrammaticScrollRef.current) {
      return;
    }

    const container = scrollRef.current;
    if (!container) {
      return;
    }

    const distanceFromBottom =
      container.scrollHeight - container.scrollTop - container.clientHeight;
    const isNearBottom = distanceFromBottom <= SCROLL_BOTTOM_THRESHOLD_PX;

    if (!isUserInteractingRef.current) {
      return;
    }

    shouldFollowBottomRef.current = isNearBottom;
    userInterruptedFollowRef.current = !isNearBottom;

    if (isNearBottom) {
      userInterruptedFollowRef.current = false;
      shouldFollowBottomRef.current = true;
      isUserInteractingRef.current = false;
    }
  }, []);

  const createWelcomeMessage = useCallback(
    (targetWeightKg: number | null): DisplayMessage => ({
      id: 0,
      role: "assistant",
      content: buildWelcomeMessage(targetWeightKg),
      createdAt: new Date().toISOString(),
      createdAtDate: new Date(),
      animateEnter: false,
    }),
    [],
  );

  // タブ再マウント時の追従状態初期化
  useEffect(() => {
    isInitialScrollPendingRef.current = true;
    shouldFollowBottomRef.current = true;
    userInterruptedFollowRef.current = false;
    isProgrammaticScrollRef.current = false;
    isStreamingRef.current = false;
    isUserInteractingRef.current = false;
    lastContentHeightRef.current = 0;
    stableHeightFramesRef.current = 0;
    initialHistoryScrollStartedRef.current = false;
  }, []);

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
          setMessages(
            historyResponse.messages.map((message) => ({
              ...toDisplayMessage(message),
              animateEnter: false,
            })),
          );
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
      initialMessageIdsRef.current = new Set(
        messages.map((message) => message.id),
      );
    }
  }, [isBootstrapping, messages]);

  // 初回履歴表示時のみ初期下端追従を開始（送信・settled の length 変化では再発火しない）
  useLayoutEffect(() => {
    if (isBootstrapping || messages.length === 0) {
      return;
    }
    if (initialHistoryScrollStartedRef.current) {
      return;
    }
    initialHistoryScrollStartedRef.current = true;
    isInitialScrollPendingRef.current = true;
    stableHeightFramesRef.current = 0;
    lastContentHeightRef.current = 0;
    scheduleScrollToBottom({ force: true });
  }, [isBootstrapping, messages.length, scheduleScrollToBottom]);

  // 履歴全体（+ストリーミング）の高さ変化を監視
  useEffect(() => {
    const content = messagesContentRef.current;
    if (!content) {
      return;
    }

    const observer = new ResizeObserver(() => {
      if (isInitialScrollPendingRef.current) {
        scheduleScrollToBottom({ force: true });
        return;
      }
      scheduleScrollToBottom();
    });

    observer.observe(content);

    return () => {
      observer.disconnect();
    };
  }, [scheduleScrollToBottom]);

  // 初期追従の最終確認（Markdown 遅延レイアウト用・初回のみ）
  useEffect(() => {
    if (isBootstrapping || messages.length === 0) {
      return;
    }
    if (!isInitialScrollPendingRef.current) {
      return;
    }

    if (initialSettleTimerRef.current !== null) {
      window.clearTimeout(initialSettleTimerRef.current);
    }

    initialSettleTimerRef.current = window.setTimeout(() => {
      initialSettleTimerRef.current = null;
      if (!isInitialScrollPendingRef.current) {
        return;
      }

      scheduleScrollToBottom({ force: true });

      window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
          const container = scrollRef.current;
          if (!container || !isInitialScrollPendingRef.current) {
            return;
          }

          const distance =
            container.scrollHeight -
            container.scrollTop -
            container.clientHeight;

          if (distance <= INITIAL_SETTLE_DISTANCE_PX) {
            isInitialScrollPendingRef.current = false;
            shouldFollowBottomRef.current = true;
            userInterruptedFollowRef.current = false;
            lastContentHeightRef.current = container.scrollHeight;
            stableHeightFramesRef.current = 2;
          }
        });
      });
    }, INITIAL_SETTLE_TIMEOUT_MS);

    return () => {
      if (initialSettleTimerRef.current !== null) {
        window.clearTimeout(initialSettleTimerRef.current);
        initialSettleTimerRef.current = null;
      }
    };
  }, [isBootstrapping, messages.length, scheduleScrollToBottom]);

  useEffect(() => {
    return () => {
      if (scrollFrameRef.current !== null) {
        window.cancelAnimationFrame(scrollFrameRef.current);
      }
      if (programmaticClearFrameRef.current !== null) {
        window.cancelAnimationFrame(programmaticClearFrameRef.current);
      }
      abortControllerRef.current?.abort();
      abortControllerRef.current = null;
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
    isStreamingRef.current = false;
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

    // AI返信開始: 下端付近なら追従を明示的に有効化
    const container = scrollRef.current;
    if (container) {
      const distanceFromBottom =
        container.scrollHeight - container.scrollTop - container.clientHeight;
      if (distanceFromBottom <= STREAM_RESUME_THRESHOLD_PX) {
        shouldFollowBottomRef.current = true;
        userInterruptedFollowRef.current = false;
      }
    } else {
      shouldFollowBottomRef.current = true;
      userInterruptedFollowRef.current = false;
    }

    const abortController = new AbortController();
    abortControllerRef.current = abortController;
    stoppedByUserRef.current = false;

    isStreamingRef.current = true;
    isUserInteractingRef.current = false;
    streamingKeyRef.current = assistantClientKey;
    streamHandleRef.current = null;
    pendingDeltasRef.current = [];
    pendingCompleteRef.current = null;
    setMessages((current) => [...current, optimisticUserMessage]);
    setStreamingKey(assistantClientKey);
    setInput("");
    setError(null);
    setIsLoading(true);
    scheduleScrollToBottom({ force: true });

    try {
      await sendChatMessageStream(
        trimmed,
        {
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
            pushDeltaToStream(text);
          },
          onAssistantMessage: (assistantMessage) => {
            if (stoppedByUserRef.current) {
              return;
            }
            completeStream(assistantMessage);
          },
          onError: (message) => {
            if (stoppedByUserRef.current) {
              return;
            }
            abortActiveStream();
            streamingKeyRef.current = null;
            setStreamingKey(null);
            setError(message);
            setIsLoading(false);
          },
        },
        { signal: abortController.signal },
      );
    } catch (sendError) {
      if (stoppedByUserRef.current || isAbortError(sendError)) {
        const userStopped = stoppedByUserRef.current;
        stoppedByUserRef.current = false;
        // ユーザー停止は handleStop 側で UI を戻す／部分確定する。
        // ここでもう一度消すと、途中までの返信確定と競合する。
        if (!userStopped && streamingKeyRef.current !== null) {
          resetStreamingUi();
        }
        return;
      }

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
    } finally {
      if (abortControllerRef.current === abortController) {
        abortControllerRef.current = null;
      }
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
        onPointerDown={markUserInteracting}
        onTouchStart={markUserInteracting}
        onWheel={markUserInteracting}
        className="chat-scroll-container"
        style={{
          flex: 1,
          padding: "14px 16px",
          overflowY: "auto",
          background: "#fff",
        }}
      >
        <div
          ref={messagesContentRef}
          style={{
            display: "flex",
            flexDirection: "column",
            gap: 14,
          }}
        >
          {messages.map((message) =>
            message.role === "assistant" ? (
              <AssistantHistoryMessage
                key={message.clientKey ?? message.id}
                message={message}
                animate={message.animateEnter === true}
              />
            ) : (
              <UserHistoryMessage
                key={message.clientKey ?? message.id}
                message={message}
                animate={message.animateEnter === true}
              />
            ),
          )}

          {streamingKey ? (
            <StreamingAssistantMessage
              key={streamingKey}
              onReady={handleStreamReady}
              onSettled={handleStreamSettled}
              onScrollRequest={scheduleScrollToBottom}
            />
          ) : null}

          {error ? (
            <div
              style={{ fontSize: 12, color: "#D64545", textAlign: "center" }}
            >
              {error}
            </div>
          ) : null}
        </div>
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
        {isLoading || streamingKey !== null ? (
          <button
            type="button"
            onClick={handleStop}
            aria-label="返信を停止"
            style={{
              display: "inline-flex",
              alignItems: "center",
              justifyContent: "center",
              background: "#5A5A5A",
              color: "#fff",
              border: "none",
              borderRadius: 14,
              width: 36,
              height: 36,
              padding: 0,
              cursor: "pointer",
              flexShrink: 0,
            }}
          >
            <span
              aria-hidden
              style={{
                display: "block",
                width: 12,
                height: 12,
                background: "#fff",
                borderRadius: 2,
              }}
            />
          </button>
        ) : (
          <button
            type="button"
            onClick={() => void handleSend()}
            disabled={isBootstrapping || input.trim() === ""}
            style={{
              background:
                isBootstrapping || input.trim() === "" ? "#F0C9A0" : ORANGE,
              color: "#fff",
              border: "none",
              borderRadius: 14,
              padding: "6px 14px",
              fontSize: 12,
              fontWeight: 600,
              cursor:
                isBootstrapping || input.trim() === ""
                  ? "not-allowed"
                  : "pointer",
              lineHeight: 1.4,
            }}
          >
            送信
          </button>
        )}
      </div>
    </div>
  );
}
