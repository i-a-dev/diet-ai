export interface SpringConfig {
  stiffness: number;
  damping: number;
  mass: number;
  precision: number;
}

export const ASSISTANT_MESSAGE_SPRING: SpringConfig = {
  stiffness: 380,
  damping: 24,
  mass: 1,
  precision: 0.004,
};

export const ASSISTANT_OPACITY_SPRING: SpringConfig = {
  stiffness: 420,
  damping: 34,
  mass: 1,
  precision: 0.004,
};

export interface SpringValues {
  opacity: number;
  translateY: number;
}

export interface SpringAnimateOptions {
  from: SpringValues;
  to: SpringValues;
  translateYConfig?: Partial<SpringConfig>;
  opacityConfig?: Partial<SpringConfig>;
  onUpdate: (values: SpringValues) => void;
  onComplete?: () => void;
  durationLimit?: number;
}

function mergeConfig(
  base: SpringConfig,
  overrides?: Partial<SpringConfig>,
): SpringConfig {
  return { ...base, ...overrides };
}

function springStep(
  current: number,
  velocity: number,
  target: number,
  config: SpringConfig,
  deltaSeconds: number,
): { current: number; velocity: number } {
  const displacement = current - target;
  const acceleration =
    (-config.stiffness * displacement - config.damping * velocity) /
    config.mass;
  const nextVelocity = velocity + acceleration * deltaSeconds;
  const nextCurrent = current + nextVelocity * deltaSeconds;
  return { current: nextCurrent, velocity: nextVelocity };
}

function isSettled(
  current: number,
  velocity: number,
  target: number,
  precision: number,
): boolean {
  return (
    Math.abs(current - target) < precision &&
    Math.abs(velocity) < precision
  );
}

export function springAnimate(options: SpringAnimateOptions): () => void {
  if (
    typeof window !== "undefined" &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches
  ) {
    options.onUpdate(options.to);
    options.onComplete?.();
    return () => {};
  }

  const translateYConfig = mergeConfig(
    ASSISTANT_MESSAGE_SPRING,
    options.translateYConfig,
  );
  const opacityConfig = mergeConfig(
    ASSISTANT_OPACITY_SPRING,
    options.opacityConfig,
  );

  let opacity = options.from.opacity;
  let translateY = options.from.translateY;
  let velocityOpacity = 0;
  let velocityTranslateY = 0;
  let animationFrameId = 0;
  let previousTimestamp = 0;
  const startedAt = performance.now();
  const durationLimit = options.durationLimit ?? 650;

  const tick = (timestamp: number) => {
    if (previousTimestamp === 0) {
      previousTimestamp = timestamp;
    }

    const deltaSeconds = Math.min((timestamp - previousTimestamp) / 1000, 0.05);
    previousTimestamp = timestamp;

    ({ current: opacity, velocity: velocityOpacity } = springStep(
      opacity,
      velocityOpacity,
      options.to.opacity,
      opacityConfig,
      deltaSeconds,
    ));
    ({ current: translateY, velocity: velocityTranslateY } = springStep(
      translateY,
      velocityTranslateY,
      options.to.translateY,
      translateYConfig,
      deltaSeconds,
    ));

    options.onUpdate({ opacity, translateY });

    const translateYSettled = isSettled(
      translateY,
      velocityTranslateY,
      options.to.translateY,
      translateYConfig.precision,
    );
    const opacitySettled = isSettled(
      opacity,
      velocityOpacity,
      options.to.opacity,
      opacityConfig.precision,
    );
    const timedOut = timestamp - startedAt > durationLimit;

    if ((translateYSettled && opacitySettled) || timedOut) {
      options.onUpdate(options.to);
      options.onComplete?.();
      return;
    }

    animationFrameId = window.requestAnimationFrame(tick);
  };

  animationFrameId = window.requestAnimationFrame(tick);

  return () => {
    window.cancelAnimationFrame(animationFrameId);
  };
}
