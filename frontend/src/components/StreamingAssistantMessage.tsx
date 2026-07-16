import {
  memo,
  useEffect,
  useRef,
  useState,
  type CSSProperties,
} from "react";
import { AssistantMessageEnter } from "./AssistantMessageEnter.tsx";
import { BubbleCoach } from "./BubbleCoach.tsx";
import { ChatMarkdown } from "./ChatMarkdown.tsx";
import { CoachAvatar } from "./CoachAvatar.tsx";
import {
  createStreamReveal,
  type StreamRevealController,
} from "../utils/streamReveal.ts";
import {
  partitionAllBlocksOnComplete,
  partitionCompletedBlocks,
  syncFinalizedBlocks,
  type FinalizedMarkdownBlock,
} from "../utils/streamBlockParser.ts";
import { createStreamingTextFilter } from "../utils/streamingTextFilter.ts";
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

const MemoizedMarkdownBlock = memo(function MemoizedMarkdownBlock({
  source,
}: {
  source: string;
}) {
  return <ChatMarkdown content={source} />;
});

function visibleFromRaw(raw: string): string {
  if (raw === "") {
    return "";
  }
  const filter = createStreamingTextFilter();
  let visible = "";
  for (const unit of Array.from(raw)) {
    visible += filter.push(unit);
  }
  return visible;
}

/**
 * ChatGPT 風ストリーミングバブル。
 * - 完成ブロックだけ ReactMarkdown（memo）
 * - 末尾の未完成部分だけプレーン（記号はフィルターで非表示）
 * - 返信完了時は全文差し替えせず、残りの active を最終ブロック確定するだけ
 */
export const StreamingAssistantMessage = memo(function StreamingAssistantMessage({
  onReady,
  onSettled,
  onScrollRequest,
}: StreamingAssistantMessageProps) {
  const [finalizedBlocks, setFinalizedBlocks] = useState<
    FinalizedMarkdownBlock[]
  >([]);
  const [activePlainText, setActivePlainText] = useState("");
  const [hasStarted, setHasStarted] = useState(false);
  const [isComplete, setIsComplete] = useState(false);

  const revealRef = useRef<StreamRevealController | null>(null);
  const prevDisplayedRef = useRef("");
  const pendingMessageRef = useRef<ChatMessage | null>(null);
  const settledRef = useRef(false);
  const blockIdRef = useRef(0);
  const bubbleRef = useRef<HTMLDivElement>(null);
  const streamBodyRef = useRef<HTMLDivElement>(null);
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
    prevDisplayedRef.current = "";
    blockIdRef.current = 0;

    const reveal = createStreamReveal({
      onUpdate: (displayed) => {
        prevDisplayedRef.current = displayed;

        const { blocks, active } = partitionCompletedBlocks(displayed);
        setFinalizedBlocks((previous) =>
          syncFinalizedBlocks(previous, blocks, createBlockId),
        );
        setActivePlainText(visibleFromRaw(active));

        if (displayed !== "") {
          setHasStarted(true);
        }
      },
      onCaughtUp: (fullText) => {
        if (settledRef.current) {
          return;
        }
        settledRef.current = true;
        pendingFullTextRef.current = fullText;

        // 全文差し替え禁止: 残りの active だけ最終ブロックへ確定
        const allSources = partitionAllBlocksOnComplete(fullText);
        setFinalizedBlocks((previous) =>
          syncFinalizedBlocks(previous, allSources, createBlockId),
        );
        setActivePlainText("");
        setHasStarted(true);
        setIsComplete(true);
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

  // 最終ブロック確定後、同じ構造のまま履歴へコミット（コンテナ差し替えは親の責務だが MD 一括切替はしない）
  useEffect(() => {
    if (!isComplete) {
      return;
    }

    const message = pendingMessageRef.current;
    const fullText = pendingFullTextRef.current;
    if (!message || fullText === null) {
      return;
    }

    const timer = window.setTimeout(() => {
      onSettledRef.current({
        ...message,
        content: fullText,
      });
    }, 0);

    return () => window.clearTimeout(timer);
  }, [isComplete]);

  useEffect(() => {
    if (!hasStarted) {
      return;
    }

    const element = bubbleRef.current ?? streamBodyRef.current;
    if (!element) {
      return;
    }

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
    <AssistantMessageEnter animate={!isComplete}>
      <CoachAvatar />
      <div style={bodyStyle}>
        <BubbleCoach ref={bubbleRef}>
          <div
            ref={streamBodyRef}
            className="streaming-message-body chat-stream-body"
          >
            {finalizedBlocks.map((block) => (
              <MemoizedMarkdownBlock key={block.id} source={block.source} />
            ))}
            {activePlainText !== "" ? (
              <div className="streaming-text">{activePlainText}</div>
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
