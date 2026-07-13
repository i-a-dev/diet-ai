import {
  useLayoutEffect,
  useRef,
  type CSSProperties,
  type ReactNode,
} from "react";
import { springAnimate } from "../utils/springAnimate.ts";

interface AssistantMessageEnterProps {
  children: ReactNode;
  animate: boolean;
  onTick?: () => void;
  style?: CSSProperties;
}

export function AssistantMessageEnter({
  children,
  animate,
  onTick,
  style,
}: AssistantMessageEnterProps) {
  const ref = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const element = ref.current;
    if (!element) {
      return;
    }

    if (!animate) {
      element.style.opacity = "1";
      element.style.transform = "translate3d(0, 0, 0)";
      element.style.willChange = "auto";
      return;
    }

    element.style.opacity = "0";
    element.style.transform = "translate3d(0, 20px, 0)";
    element.style.willChange = "opacity, transform";

    const cancel = springAnimate({
      from: { opacity: 0, translateY: 20 },
      to: { opacity: 1, translateY: 0 },
      onUpdate: ({ opacity, translateY }) => {
        element.style.opacity = String(opacity);
        element.style.transform = `translate3d(0, ${translateY}px, 0)`;
        onTick?.();
      },
      onComplete: () => {
        element.style.opacity = "1";
        element.style.transform = "translate3d(0, 0, 0)";
        element.style.willChange = "auto";
        onTick?.();
      },
    });

    return cancel;
  }, [animate, onTick]);

  return (
    <div
      ref={ref}
      style={{
        display: "flex",
        gap: 10,
        alignItems: "flex-start",
        ...style,
      }}
    >
      {children}
    </div>
  );
}
