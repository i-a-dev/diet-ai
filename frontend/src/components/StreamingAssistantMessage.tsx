import {
  memo,
  useEffect,
  useRef,
  useState,
  type CSSProperties,
} from "react";
import { AssistantMessageEnter } from "./AssistantMessageEnter.tsx";
import { BubbleCoach } from "./BubbleCoach.tsx";
import { CoachAvatar } from "./CoachAvatar.tsx";
import {
  createStreamReveal,
  type StreamRevealController,
} from "../utils/streamReveal.ts";
import {
  createStreamingTextFilter,
  type StreamingTextFilter,
} from "../utils/streamingTextFilter.ts";
import type { ChatMessage } from "../api/client.ts";

export type StreamingAssistantHandle = {
  pushDelta: (text: string) => void;
  complete: (assistantMessage: ChatMessage) => void;
  cancel: () => void;
};

interface StreamingAssistantMessageProps {
  onReady: (handle: StreamingAssistantHandle) => void;
  onSettled: (assistantMessage: ChatMessage) => void;
  onScrollRequest: (options?: { force?: boolean }) => void;
}

/**
 * ChatGPT 風ストリーミングバブル。
 * - ストリーミング中は Markdown を使わずプレーンテキストのみ（append-only）
 * - 高さ変化は ResizeObserver → 親の rAF スクロールへ委譲
 * - 返信完了後に親へ返し、履歴側で一度だけ ReactMarkdown する
 */
export const StreamingAssistantMessage = memo(function StreamingAssistantMessage({
  onReady,
  onSettled,
  onScrollRequest,
}: StreamingAssistantMessageProps) {
  const [plainText, setPlainText] = useState("");
  const [hasStarted, setHasStarted] = useState(false);

  const revealRef = useRef<StreamRevealController | null>(null);
  const filterRef = useRef<StreamingTextFilter>(createStreamingTextFilter());
  const prevDisplayedRef = useRef("");
  const pendingMessageRef = useRef<ChatMessage | null>(null);
  const settledRef = useRef(false);
  const bubbleRef = useRef<HTMLDivElement>(null);
  const streamBodyRef = useRef<HTMLDivElement>(null);

  const onSettledRef = useRef(onSettled);
  const onScrollRequestRef = useRef(onScrollRequest);
  const onReadyRef = useRef(onReady);
  onSettledRef.current = onSettled;
  onScrollRequestRef.current = onScrollRequest;
  onReadyRef.current = onReady;

  useEffect(() => {
    const filter = createStreamingTextFilter();
    filterRef.current = filter;
    prevDisplayedRef.current = "";

    const reveal = createStreamReveal({
      onUpdate: (displayed) => {
        const prev = prevDisplayedRef.current;
        const chunk = displayed.slice(prev.length);
        prevDisplayedRef.current = displayed;

        let appended = "";
        for (const unit of Array.from(chunk)) {
          appended += filter.push(unit);
        }

        if (appended !== "" || displayed !== "") {
          setHasStarted(true);
        }
        if (appended !== "") {
          // append-only。一度出した文字は書き換えない
          setPlainText((current) => current + appended);
        }
        // スクロールは ResizeObserver / 入場開始時のみ。ここでは呼ばない
      },
      onCaughtUp: (fullText) => {
        if (settledRef.current) {
          return;
        }
        settledRef.current = true;
        const message = pendingMessageRef.current;
        if (!message) {
          return;
        }
        onSettledRef.current({
          ...message,
          content: fullText,
        });
      },
    });

    revealRef.current = reveal;

    onReadyRef.current({
      pushDelta: (text) => {
        reveal.push(text);
      },
      complete: (assistantMessage) => {
        pendingMessageRef.current = assistantMessage;
        const buffered = reveal.getBuffer();
        if (assistantMessage.content && assistantMessage.content !== buffered) {
          const suffix = assistantMessage.content.slice(buffered.length);
          if (suffix) {
            reveal.push(suffix);
          }
        }
        reveal.complete();
      },
      cancel: () => {
        reveal.cancel();
      },
    });

    return () => {
      reveal.cancel();
    };
  }, []);

  // 高さ変化のみでスクロール予約（改行・折り返し・フォント描画）
  useEffect(() => {
    if (!hasStarted) {
      return;
    }

    const element = bubbleRef.current ?? streamBodyRef.current;
    if (!element) {
      return;
    }

    // 入場開始時に1回
    onScrollRequestRef.current();

    const observer = new ResizeObserver(() => {
      onScrollRequestRef.current();
    });
    observer.observe(element);

    return () => {
      observer.disconnect();
    };
  }, [hasStarted]);

  if (!hasStarted) {
    return (
      <div style={rowStyle}>
        <CoachAvatar />
      </div>
    );
  }

  return (
    <AssistantMessageEnter animate>
      <CoachAvatar />
      <div style={bodyStyle}>
        <BubbleCoach ref={bubbleRef}>
          <div ref={streamBodyRef} className="streaming-text">
            {plainText}
          </div>
        </BubbleCoach>
      </div>
    </AssistantMessageEnter>
  );
});

const rowStyle: CSSProperties = {
  display: "flex",
  gap: 10,
  alignItems: "flex-start",
};

const bodyStyle: CSSProperties = {
  flex: 1,
  minWidth: 0,
  maxWidth: "calc(100% - 50px)",
};
