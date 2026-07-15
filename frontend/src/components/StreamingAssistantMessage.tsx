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
import { stripMarkdownForStreaming } from "../utils/stripMarkdownForStreaming.ts";
import type { ChatMessage } from "../api/client.ts";

export type StreamingAssistantHandle = {
  pushDelta: (text: string) => void;
  complete: (assistantMessage: ChatMessage) => void;
  cancel: () => void;
};

interface StreamingAssistantMessageProps {
  onReady: (handle: StreamingAssistantHandle) => void;
  onSettled: (assistantMessage: ChatMessage) => void;
  onScrollRequest: () => void;
}

/**
 * ChatGPT 風ストリーミングバブル。
 * - ストリーミング中は Markdown を使わずプレーンテキストのみ
 * - 記号は除去し、文字だけが増えていく見た目を維持
 * - 表示追いつき後に親へ返し、履歴側で一度だけ ReactMarkdown する
 */
export const StreamingAssistantMessage = memo(function StreamingAssistantMessage({
  onReady,
  onSettled,
  onScrollRequest,
}: StreamingAssistantMessageProps) {
  const [plainText, setPlainText] = useState("");
  const [hasStarted, setHasStarted] = useState(false);

  const revealRef = useRef<StreamRevealController | null>(null);
  const pendingMessageRef = useRef<ChatMessage | null>(null);
  const settledRef = useRef(false);

  const onSettledRef = useRef(onSettled);
  const onScrollRequestRef = useRef(onScrollRequest);
  const onReadyRef = useRef(onReady);
  onSettledRef.current = onSettled;
  onScrollRequestRef.current = onScrollRequest;
  onReadyRef.current = onReady;

  useEffect(() => {
    const reveal = createStreamReveal({
      onUpdate: (displayed) => {
        if (displayed !== "") {
          setHasStarted(true);
        }
        setPlainText(stripMarkdownForStreaming(displayed));
        onScrollRequestRef.current();
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
        // フェード等なし。親が履歴へ差し替え、そこで一度だけ Markdown 化
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

  if (!hasStarted) {
    return (
      <div style={rowStyle}>
        <CoachAvatar />
      </div>
    );
  }

  return (
    <AssistantMessageEnter
      animate
      onTick={() => onScrollRequestRef.current()}
    >
      <CoachAvatar />
      <div style={bodyStyle}>
        <BubbleCoach>
          <div className="streaming-text">{plainText}</div>
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
