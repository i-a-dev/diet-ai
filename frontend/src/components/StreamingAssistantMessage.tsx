import {
  memo,
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type CSSProperties,
} from "react";
import { AssistantMessageEnter } from "./AssistantMessageEnter.tsx";
import { BubbleCoach } from "./BubbleCoach.tsx";
import { ChatMarkdown } from "./ChatMarkdown.tsx";
import { CoachAvatar } from "./CoachAvatar.tsx";
import {
  partitionStreamMarkdown,
  sanitizeActivePlainText,
  syncFinalizedBlocks,
  type FinalizedBlock,
} from "../utils/streamMarkdownPartition.ts";
import {
  createStreamReveal,
  type StreamRevealController,
} from "../utils/streamReveal.ts";
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

const MemoizedMarkdownBlock = memo(function MemoizedMarkdownBlock({
  content,
}: {
  content: string;
}) {
  return <ChatMarkdown content={content} />;
});

/**
 * ChatGPT 風ストリーミングバブル。
 * - 書記素 1 つずつ公開
 * - 未確定 Markdown はプレーン表示（ReactMarkdown に渡さない）
 * - 確定ブロックだけ memo 付きで Markdown 描画
 */
export const StreamingAssistantMessage = memo(function StreamingAssistantMessage({
  onReady,
  onSettled,
  onScrollRequest,
}: StreamingAssistantMessageProps) {
  const [finalizedBlocks, setFinalizedBlocks] = useState<FinalizedBlock[]>([]);
  const [activePlainText, setActivePlainText] = useState("");
  const [finalMarkdown, setFinalMarkdown] = useState<string | null>(null);
  const [hasStarted, setHasStarted] = useState(false);

  const revealRef = useRef<StreamRevealController | null>(null);
  const pendingMessageRef = useRef<ChatMessage | null>(null);
  const settledRef = useRef(false);
  const blockIdRef = useRef(0);
  const streamBodyRef = useRef<HTMLDivElement>(null);
  const measureRef = useRef<HTMLDivElement>(null);
  const pendingFullTextRef = useRef<string | null>(null);

  const onSettledRef = useRef(onSettled);
  const onScrollRequestRef = useRef(onScrollRequest);
  const onReadyRef = useRef(onReady);
  onSettledRef.current = onSettled;
  onScrollRequestRef.current = onScrollRequest;
  onReadyRef.current = onReady;

  const createBlockId = () => {
    blockIdRef.current += 1;
    return `md-block-${blockIdRef.current}`;
  };

  useEffect(() => {
    const reveal = createStreamReveal({
      onUpdate: (displayed) => {
        if (displayed !== "") {
          setHasStarted(true);
        }

        const { blocks, active } = partitionStreamMarkdown(displayed);
        setFinalizedBlocks((previous) =>
          syncFinalizedBlocks(previous, blocks, createBlockId),
        );
        setActivePlainText(sanitizeActivePlainText(active));
        onScrollRequestRef.current();
      },
      onCaughtUp: (fullText) => {
        if (settledRef.current) {
          return;
        }
        settledRef.current = true;
        setHasStarted(true);
        pendingFullTextRef.current = fullText;
        // ストリーミング表示の高さに合わせて最終 Markdown へ切替
        setFinalMarkdown(fullText);
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

  // 最終 Markdown 切替後、タイポ共有済みのまま履歴へコミット
  useLayoutEffect(() => {
    if (finalMarkdown === null) {
      return;
    }

    const message = pendingMessageRef.current;
    const fullText = pendingFullTextRef.current ?? finalMarkdown;
    if (!message) {
      return;
    }

    // 計測用ノードと表示ノードの差を確認（高さアニメはしない）
    const streamHeight = streamBodyRef.current?.offsetHeight ?? 0;
    const measureHeight = measureRef.current?.offsetHeight ?? streamHeight;
    void Math.abs(measureHeight - streamHeight);

    setFinalizedBlocks([]);
    setActivePlainText("");

    const timer = window.setTimeout(() => {
      onSettledRef.current({
        ...message,
        content: fullText,
      });
    }, 0);

    return () => window.clearTimeout(timer);
  }, [finalMarkdown]);

  if (!hasStarted && finalMarkdown === null) {
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
          <div ref={streamBodyRef} className="chat-stream-body" style={{ position: "relative" }}>
            {finalMarkdown !== null ? (
              <ChatMarkdown content={finalMarkdown} />
            ) : (
              <>
                {finalizedBlocks.map((block) => (
                  <MemoizedMarkdownBlock
                    key={block.id}
                    content={block.markdown}
                  />
                ))}
                {activePlainText !== "" ? (
                  <span className="streaming-plain-text">{activePlainText}</span>
                ) : null}
              </>
            )}
            {finalMarkdown !== null ? (
              <div
                ref={measureRef}
                className="chat-stream-body"
                aria-hidden
                style={measureStyle}
              >
                <ChatMarkdown content={finalMarkdown} />
              </div>
            ) : null}
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

const measureStyle: CSSProperties = {
  position: "absolute",
  visibility: "hidden",
  pointerEvents: "none",
  height: "auto",
  width: "100%",
  left: 0,
  top: 0,
};
