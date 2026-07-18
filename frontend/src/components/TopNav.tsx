interface TopNavProps {
  title: string;
}

export function TopNav({ title }: TopNavProps) {
  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        minHeight: 50,
        padding: "5px 20px",
        background: "#fff",
        borderBottom: "1px solid #F0F0F0",
        flexShrink: 0,
      }}
    >
      <span
        style={{
          fontSize: 17,
          fontWeight: 600,
          color: "#111",
          lineHeight: "22px",
        }}
      >
        {title}
      </span>
    </div>
  );
}
