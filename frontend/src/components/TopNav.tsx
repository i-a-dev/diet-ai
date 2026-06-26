interface TopNavProps {
  title: string;
}

export function TopNav({ title }: TopNavProps) {
  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        justifyContent: "space-between",
        minHeight: 44,
        padding: "0 20px",
        background: "#fff",
        borderBottom: "1px solid #F0F0F0",
      }}
    >
      <span style={{ width: 22 }} />
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
      <span style={{ width: 22 }} />
    </div>
  );
}
