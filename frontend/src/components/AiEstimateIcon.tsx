const ESTIMATE_ICON = "#F5A623";

function FourPointStar({
  cx,
  cy,
  outer,
  inner,
}: {
  cx: number;
  cy: number;
  outer: number;
  inner: number;
}) {
  const points = [
    [cx, cy - outer],
    [cx + inner, cy - inner],
    [cx + outer, cy],
    [cx + inner, cy + inner],
    [cx, cy + outer],
    [cx - inner, cy + inner],
    [cx - outer, cy],
    [cx - inner, cy - inner],
  ]
    .map(([x, y]) => `${x},${y}`)
    .join(" ");

  return <polygon points={points} />;
}

/** 食事・運動チップ共通の AI 推定キラキラアイコン */
export function AiEstimateIcon({ size = 12 }: { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 16 16"
      fill={ESTIMATE_ICON}
      aria-hidden
    >
      <FourPointStar cx={6.2} cy={8} outer={5.2} inner={2} />
      <FourPointStar cx={12.2} cy={4.2} outer={2.6} inner={1} />
      <FourPointStar cx={12.4} cy={11.2} outer={2.2} inner={0.85} />
    </svg>
  );
}
