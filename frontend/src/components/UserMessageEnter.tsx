import {
  useLayoutEffect,
  useRef,
  type CSSProperties,
  type ReactNode,
} from "react";
import { springAnimate } from "../utils/springAnimate.ts";

interface UserMessageEnterProps {
  children: ReactNode;
  animate: boolean;
  onTick?: () => void;
  style?: CSSProperties;
}

export function UserMessageEnter({
  children,
  animate,
  onTick,
  style,
}: UserMessageEnterProps) {
  const ref = useRef<HTMLDivElement>(null);
  const onTickRef = useRef(onTick);
  const hasAnimatedRef = useRef(false);
  onTickRef.current = onTick;

  useLayoutEffect(() => {
    const element = ref.current;
    if (!element) {
      return;
    }

    if (!animate || hasAnimatedRef.current) {
      element.style.opacity = "1";
      element.style.transform = "translate3d(0, 0, 0)";
      element.style.willChange = "auto";
      return;
    }

    hasAnimatedRef.current = true;
    element.style.opacity = "0";
    element.style.transform = "translate3d(0, 20px, 0)";
    element.style.willChange = "opacity, transform";

    const cancel = springAnimate({
      from: { opacity: 0, translateY: 20 },
      to: { opacity: 1, translateY: 0 },
      durationLimit: 400,
      onUpdate: ({ opacity, translateY }) => {
        element.style.opacity = String(opacity);
        element.style.transform = `translate3d(0, ${translateY}px, 0)`;
        onTickRef.current?.();
      },
      onComplete: () => {
        element.style.opacity = "1";
        element.style.transform = "translate3d(0, 0, 0)";
        element.style.willChange = "auto";
        onTickRef.current?.();
      },
    });

    return cancel;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [animate]);

  return (
    <div
      ref={ref}
      style={{
        display: "flex",
        flexDirection: "column",
        alignItems: "flex-end",
        gap: 3,
        ...style,
      }}
    >
      {children}
    </div>
  );
}
