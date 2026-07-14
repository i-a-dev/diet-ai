import { Component, memo, type ErrorInfo, type ReactNode } from "react";
import ReactMarkdown from "react-markdown";
import remarkBreaks from "remark-breaks";
import remarkGfm from "remark-gfm";

interface ChatMarkdownProps {
  content: string;
}

interface ErrorBoundaryProps {
  content: string;
  children: ReactNode;
}

interface ErrorBoundaryState {
  hasError: boolean;
}

class ChatMarkdownErrorBoundary extends Component<
  ErrorBoundaryProps,
  ErrorBoundaryState
> {
  state: ErrorBoundaryState = { hasError: false };

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { hasError: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error("ChatMarkdown render failed", error, info);
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="chat-markdown streaming-plain-text">
          {this.props.content}
        </div>
      );
    }

    return this.props.children;
  }
}

const markdownComponents = {
  h1: ({ children }: { children?: ReactNode }) => <h1>{children}</h1>,
  h2: ({ children }: { children?: ReactNode }) => <h2>{children}</h2>,
  h3: ({ children }: { children?: ReactNode }) => <h3>{children}</h3>,
  h4: ({ children }: { children?: ReactNode }) => <h4>{children}</h4>,
  p: ({ children }: { children?: ReactNode }) => <p>{children}</p>,
  ul: ({ children }: { children?: ReactNode }) => <ul>{children}</ul>,
  ol: ({ children }: { children?: ReactNode }) => <ol>{children}</ol>,
  li: ({ children }: { children?: ReactNode }) => <li>{children}</li>,
  strong: ({ children }: { children?: ReactNode }) => <strong>{children}</strong>,
  code: ({
    children,
    className,
  }: {
    children?: ReactNode;
    className?: string;
  }) => {
    const isBlock = Boolean(className);
    if (isBlock) {
      return <code className={className}>{children}</code>;
    }
    return <code className="chat-inline-code">{children}</code>;
  },
  pre: ({ children }: { children?: ReactNode }) => <pre>{children}</pre>,
  blockquote: ({ children }: { children?: ReactNode }) => (
    <blockquote>{children}</blockquote>
  ),
  hr: () => <hr />,
  table: ({ children }: { children?: ReactNode }) => (
    <div className="chat-table-scroll">
      <table>{children}</table>
    </div>
  ),
};

export const ChatMarkdown = memo(function ChatMarkdown({
  content,
}: ChatMarkdownProps) {
  const safeContent = content ?? "";

  return (
    <ChatMarkdownErrorBoundary content={safeContent}>
      <div className="chat-markdown">
        <ReactMarkdown
          remarkPlugins={[remarkGfm, remarkBreaks]}
          components={markdownComponents}
        >
          {safeContent}
        </ReactMarkdown>
      </div>
    </ChatMarkdownErrorBoundary>
  );
});
