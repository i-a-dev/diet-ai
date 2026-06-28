import { Component, type CSSProperties, type ErrorInfo, type ReactNode } from 'react'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'

interface ChatMarkdownProps {
  content: string
}

interface ErrorBoundaryProps {
  content: string
  children: ReactNode
}

interface ErrorBoundaryState {
  hasError: boolean
}

class ChatMarkdownErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { hasError: false }

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { hasError: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('ChatMarkdown render failed', error, info)
  }

  render() {
    if (this.state.hasError) {
      return <PlainTextContent content={this.props.content} />
    }

    return this.props.children
  }
}

function PlainTextContent({ content }: { content: string }) {
  return (
    <div style={markdownRootStyle}>
      {content.split('\n').map((line, index, lines) => (
        <span key={`${index}-${line}`}>
          {line}
          {index < lines.length - 1 ? <br /> : null}
        </span>
      ))}
    </div>
  )
}

const markdownComponents = {
  h1: ({ children }: { children?: ReactNode }) => <div style={headingStyle}>{children}</div>,
  h2: ({ children }: { children?: ReactNode }) => <div style={headingStyle}>{children}</div>,
  h3: ({ children }: { children?: ReactNode }) => <div style={subheadingStyle}>{children}</div>,
  h4: ({ children }: { children?: ReactNode }) => <div style={subheadingStyle}>{children}</div>,
  p: ({ children }: { children?: ReactNode }) => <div style={paragraphStyle}>{children}</div>,
  ul: ({ children }: { children?: ReactNode }) => <ul style={listStyle}>{children}</ul>,
  ol: ({ children }: { children?: ReactNode }) => <ol style={listStyle}>{children}</ol>,
  li: ({ children }: { children?: ReactNode }) => <li style={listItemStyle}>{children}</li>,
  strong: ({ children }: { children?: ReactNode }) => <strong style={strongStyle}>{children}</strong>,
  hr: () => <hr style={hrStyle} />,
  table: ({ children }: { children?: ReactNode }) => (
    <div style={tableScrollStyle}>
      <table style={tableStyle}>{children}</table>
    </div>
  ),
  thead: ({ children }: { children?: ReactNode }) => <thead>{children}</thead>,
  tbody: ({ children }: { children?: ReactNode }) => <tbody>{children}</tbody>,
  tr: ({ children }: { children?: ReactNode }) => <tr>{children}</tr>,
  th: ({ children }: { children?: ReactNode }) => <th style={thStyle}>{children}</th>,
  td: ({ children }: { children?: ReactNode }) => <td style={tdStyle}>{children}</td>,
}

export function ChatMarkdown({ content }: ChatMarkdownProps) {
  const safeContent = content ?? ''

  return (
    <ChatMarkdownErrorBoundary content={safeContent}>
      <div style={markdownRootStyle}>
        <ReactMarkdown remarkPlugins={[remarkGfm]} components={markdownComponents}>
          {safeContent}
        </ReactMarkdown>
      </div>
    </ChatMarkdownErrorBoundary>
  )
}

const markdownRootStyle: CSSProperties = {
  wordBreak: 'break-word',
}

const headingStyle: CSSProperties = {
  margin: '0 0 8px',
  fontSize: 14,
  fontWeight: 700,
  lineHeight: 1.5,
  color: '#222',
}

const subheadingStyle: CSSProperties = {
  margin: '12px 0 6px',
  fontSize: 13,
  fontWeight: 700,
  lineHeight: 1.5,
  color: '#333',
}

const paragraphStyle: CSSProperties = {
  margin: '0 0 8px',
}

const listStyle: CSSProperties = {
  margin: '0 0 8px',
  paddingLeft: 18,
}

const listItemStyle: CSSProperties = {
  marginBottom: 4,
}

const strongStyle: CSSProperties = {
  fontWeight: 700,
}

const hrStyle: CSSProperties = {
  border: 'none',
  borderTop: '1px solid #F0DEC8',
  margin: '10px 0',
}

const tableScrollStyle: CSSProperties = {
  overflowX: 'auto',
  margin: '8px 0',
  WebkitOverflowScrolling: 'touch',
}

const tableStyle: CSSProperties = {
  width: '100%',
  minWidth: 280,
  borderCollapse: 'collapse',
  fontSize: 11,
  lineHeight: 1.45,
}

const thStyle: CSSProperties = {
  padding: '6px 8px',
  border: '1px solid #F0DEC8',
  background: '#FFF0E0',
  fontWeight: 700,
  textAlign: 'left',
  whiteSpace: 'nowrap',
}

const tdStyle: CSSProperties = {
  padding: '6px 8px',
  border: '1px solid #F0DEC8',
  verticalAlign: 'top',
}
