import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import {
  Calendar,
  Footprints,
  PersonStanding,
  UtensilsCrossed,
  Weight,
} from "lucide-react";
import { SecIcon } from "../SecIcon.tsx";
import { TopNav } from "../TopNav.tsx";
import { ORANGE } from "../../constants.ts";
import {
  fetchWeightTimeline,
  type WeightTimelinePoint,
} from "../../api/client.ts";

const METRIC_TABS = ["体重", "食事", "運動", "歩数"] as const;
const PERIOD_TABS = ["週", "月", "3ヶ月", "半年", "1年", "3年"] as const;

const STEP_GREEN = "#2EAA72";
const STEP_GREEN_BG = "#D6F5E8";
const STEP_GREEN_LIGHT = "#EDF9F3";

const kcalH = [44, 60, 38, 52, 36, 66, 54];
const exerciseH = [28, 42, 20, 36, 24, 48, 32];
const stepH = [48, 64, 34, 56, 42, 68, 52];
const BAR_WIDTH = 13;
const CHART_HEIGHT = 130;
const CHART_PLOT_WIDTH = 310;
const Y_AXIS_WIDTH = 30;
const CHART_AXIS_GAP = 2;
const CHART_BOTTOM_Y = CHART_HEIGHT;
const KCAL_AXIS_TICKS = [0, 43, 86, CHART_HEIGHT] as const;
const STEP_AXIS_TICKS = [0, 43, 86, CHART_HEIGHT] as const;

const CHART_GRID_COLOR = "#ECECEC";
const WEIGHT_AXIS_STEP_THRESHOLD_KG = 15;
const TIMELINE_SLOT_WIDTH = 44;
const TIMELINE_FIXED_START_DATE = "2026-01-01";
const PERIOD_DAY_WINDOWS = [7, 30, 90, 180, 365, 1095] as const;

function buildChartGridLines(dayCount: number, horizontalTicks: number[]) {
  const columnWidth = CHART_PLOT_WIDTH / dayCount;
  const verticalLines = Array.from(
    { length: dayCount + 1 },
    (_, index) => index * columnWidth,
  );

  return { horizontalTicks, verticalLines };
}

const mockDays = ["4/18", "4/19", "4/20", "4/21", "4/22", "4/23", "4/24"];
const xsBar = [22.5, 65.5, 108.5, 151.5, 194.5, 237.5, 280.5];

function valueToY(value: number, max: number) {
  return CHART_HEIGHT - (value / max) * CHART_HEIGHT;
}

function weightToY(weight: number, min: number, max: number) {
  if (max <= min) {
    return CHART_HEIGHT / 2;
  }
  return ((max - weight) / (max - min)) * CHART_HEIGHT;
}

function buildWeightAxis(chartMin: number, chartMax: number) {
  const min = Math.floor(chartMin);
  let max = Math.round(chartMax);
  if (max <= min) {
    max = min + 10;
  }

  const range = max - min;
  const step = range >= WEIGHT_AXIS_STEP_THRESHOLD_KG ? 2 : 1;
  const integerWeights: number[] = [];

  for (let weight = max; weight >= min; weight -= step) {
    integerWeights.push(weight);
  }

  const lastWeight = integerWeights[integerWeights.length - 1];
  if (lastWeight !== min) {
    integerWeights.push(min);
  }

  const ticks = integerWeights.map((weight) => weightToY(weight, min, max));
  const labels = integerWeights.map((weight) => weight.toFixed(1));

  return {
    labels,
    ticks,
    chartMin: min,
    chartMax: max,
  };
}

function buildWeightLineSegments(
  xs: number[],
  points: { value: number | null }[],
  chartMin: number,
  chartMax: number,
) {
  const segments: string[] = [];
  let current: string[] = [];

  points.forEach((point, index) => {
    if (point.value === null) {
      if (current.length > 0) {
        segments.push(current.join(" "));
        current = [];
      }
      return;
    }

    const y = weightToY(point.value, chartMin, chartMax);
    current.push(`${xs[index]},${y}`);
  });

  if (current.length > 0) {
    segments.push(current.join(" "));
  }

  return segments;
}

function formatSignedKg(value: number | null) {
  if (value === null) {
    return "--";
  }
  const sign = value > 0 ? "+" : "";
  return `${sign}${value.toFixed(1)} kg`;
}

function roundToOneDecimal(value: number) {
  return Math.round(value * 10) / 10;
}

function formatDateToYmd(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export function GraphScreen() {
  const [metricTab, setMetricTab] = useState(0);
  const [periodTab, setPeriodTab] = useState(0);
  const [timelinePoints, setTimelinePoints] = useState<WeightTimelinePoint[]>([]);
  const [timelineBounds, setTimelineBounds] = useState<{
    chartMin: number;
    chartMax: number;
    targetWeightKg: number | null;
  } | null>(null);
  const accent = metricTab >= 2 ? STEP_GREEN : ORANGE;
  const todayYmdRef = useRef(formatDateToYmd(new Date()));
  const visibleWindowDays = PERIOD_DAY_WINDOWS[periodTab] ?? 7;

  useEffect(() => {
    let cancelled = false;
    const endDate = todayYmdRef.current;
    const startDate = TIMELINE_FIXED_START_DATE;

    fetchWeightTimeline(startDate, endDate)
      .then((response) => {
        if (cancelled) {
          return;
        }
        const payload = response.weight;
        setTimelinePoints(payload.points);
        setTimelineBounds({
          chartMin: payload.chartMin,
          chartMax: payload.chartMax,
          targetWeightKg: payload.targetWeightKg,
        });
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [visibleWindowDays]);

  return (
    <div
      style={{
        flex: 1,
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
        minHeight: 0,
      }}
    >
      <TopNav
        title="記録を見る"
        rightIcon={<Calendar size={22} color="#C0C0C0" />}
      />

      <div
        style={{
          display: "flex",
          background: "#fff",
          borderBottom: "1px solid #F0F0F0",
          flexShrink: 0,
        }}
      >
        {METRIC_TABS.map((label, index) => {
          const active = metricTab === index;
          const tabColor = index >= 2 ? STEP_GREEN : ORANGE;
          return (
            <button
              key={label}
              type="button"
              onClick={() => setMetricTab(index)}
              style={{
                flex: 1,
                padding: "5px 0",
                fontSize: 14,
                fontWeight: active ? 700 : 500,
                color: active ? tabColor : "#888",
                border: "none",
                borderBottom: active
                  ? `2px solid ${tabColor}`
                  : "2px solid transparent",
                background: "transparent",
                cursor: "pointer",
              }}
            >
              {label}
            </button>
          );
        })}
      </div>

      <div
        style={{
          flex: 1,
          display: "flex",
          flexDirection: "column",
          overflow: "hidden",
          background: "#F7F7F7",
          minHeight: 0,
        }}
      >
        <div
          style={{
            display: "flex",
            background: "#F0F0F0",
            borderRadius: 10,
            padding: 3,
            margin: "10px 16px 8px",
            flexShrink: 0,
          }}
        >
          {PERIOD_TABS.map((text, index) => (
            <button
              key={text}
              type="button"
              onClick={() => setPeriodTab(index)}
              style={{
                flex: 1,
                padding: "3px 0",
                fontSize: 13,
                fontWeight: periodTab === index ? 700 : 500,
                textAlign: "center",
                color: periodTab === index ? "#fff" : "#888",
                borderRadius: 8,
                background: periodTab === index ? accent : "transparent",
                border: "none",
                cursor: "pointer",
              }}
            >
              {text}
            </button>
          ))}
        </div>

        <div
          style={{
            flex: 1,
            display: "flex",
            flexDirection: "column",
            overflow: "hidden",
            padding: "0 16px 8px",
            minHeight: 0,
          }}
        >
          {metricTab === 0 && (
            <WeightGraphCard
              timelinePoints={timelinePoints}
              timelineBounds={timelineBounds}
              visibleWindowDays={visibleWindowDays}
            />
          )}
          {metricTab === 1 && <MealGraphCard />}
          {metricTab === 2 && <ExerciseGraphCard />}
          {metricTab === 3 && <StepsGraphCard />}
        </div>
      </div>
    </div>
  );
}

function CardShell({ children }: { children: ReactNode }) {
  return (
    <div
      style={{
        flex: 1,
        background: "#fff",
        borderRadius: 16,
        padding: "10px 8px",
        display: "flex",
        flexDirection: "column",
        minHeight: 0,
        overflow: "hidden",
      }}
    >
      {children}
    </div>
  );
}

function CardHeader({ icon, label }: { icon: ReactNode; label: string }) {
  return (
    <div
      style={{
        flexShrink: 0,
      }}
    >
      <div
        style={{
          display: "flex",
          alignItems: "center",
          gap: 8,
        }}
      >
        {icon}
        <span style={{ fontSize: 15, fontWeight: 600, color: "#222" }}>
          {label}
        </span>
      </div>
      <div style={{ height: 20 }} aria-hidden="true" />
    </div>
  );
}

function StatBoxes({
  items,
  accent,
  boxBg,
}: {
  items: { value: string; label: string; highlight?: boolean }[];
  accent: string;
  boxBg: string;
}) {
  return (
    <div style={{ display: "flex", gap: 8, marginTop: 6, flexShrink: 0 }}>
      {items.map((item) => (
        <div
          key={item.label}
          style={{
            flex: 1,
            background: boxBg,
            borderRadius: 10,
            padding: "6px 4px",
            textAlign: "center",
          }}
        >
          <div
            style={{
              fontSize: 13,
              fontWeight: 700,
              color: item.highlight ? accent : "#111",
            }}
          >
            {item.value}
          </div>
          <div style={{ fontSize: 11, color: "#AAA", marginTop: 3 }}>
            {item.label}
          </div>
        </div>
      ))}
    </div>
  );
}

function ChartGrid({
  horizontalTicks,
  verticalLines,
  plotWidth = CHART_PLOT_WIDTH,
}: {
  horizontalTicks: number[];
  verticalLines: number[];
  plotWidth?: number;
}) {
  return (
    <>
      {horizontalTicks.map((y) => (
        <line
          key={`h-${y}`}
          x1="0"
          y1={y}
          x2={plotWidth}
          y2={y}
          stroke={CHART_GRID_COLOR}
          strokeWidth="1"
        />
      ))}
      {verticalLines.map((x) => (
        <line
          key={`v-${x}`}
          x1={x}
          y1="0"
          x2={x}
          y2={CHART_HEIGHT}
          stroke={CHART_GRID_COLOR}
          strokeWidth="1"
        />
      ))}
    </>
  );
}

function GraphChart({
  children,
  days,
  yAxisLabels,
  yAxisTicks,
  goalOverlay,
  plotMarkers,
}: {
  children: ReactNode;
  days: string[];
  yAxisLabels?: string[];
  yAxisTicks?: number[];
  goalOverlay?: { label: string; color: string; y: number };
  plotMarkers?: { x: number; y: number; color: string }[];
}) {
  const showYAxis = Boolean(
    yAxisLabels && yAxisTicks && yAxisLabels.length === yAxisTicks.length,
  );

  const dateRow = (
    <div
      style={{
        display: "flex",
        flex: 1,
        minWidth: 0,
      }}
    >
      {days.map((day, index) => (
        <span
          key={`${day}-${index}`}
          style={{
            fontSize: 11,
            color: "#888",
            flex: 1,
            textAlign: "center",
            fontWeight: 500,
          }}
        >
          {day}
        </span>
      ))}
    </div>
  );

  if (!showYAxis) {
    return (
      <div
        style={{
          flex: 1,
          display: "flex",
          flexDirection: "column",
          minHeight: 0,
          width: "100%",
        }}
      >
        <div style={{ flex: 1, minHeight: 0, width: "100%" }}>
          <svg
            width="100%"
            height="100%"
            viewBox={`0 0 ${CHART_PLOT_WIDTH} ${CHART_HEIGHT}`}
            preserveAspectRatio="xMidYMid meet"
            style={{ display: "block", overflow: "visible" }}
          >
            {children}
          </svg>
        </div>
        {dateRow}
      </div>
    );
  }

  return (
    <div
      style={{
        flex: 1,
        display: "flex",
        flexDirection: "column",
        minHeight: 0,
        width: "100%",
      }}
    >
      <div
        style={{
          flex: 1,
          minHeight: 0,
          width: "100%",
          display: "flex",
          gap: CHART_AXIS_GAP,
        }}
      >
        <div
          style={{
            width: Y_AXIS_WIDTH,
            position: "relative",
            flexShrink: 0,
            alignSelf: "stretch",
          }}
        >
          {yAxisLabels!.map((label, index) => (
            <span
              key={`${label}-${index}`}
              style={{
                position: "absolute",
                paddingLeft: 2,
                top: `${(yAxisTicks![index] / CHART_HEIGHT) * 100}%`,
                transform: "translateY(-50%)",
                fontSize: yAxisLabels!.length > 5 ? 9 : 11,
                color: "#7A7A7A",
                fontWeight: 500,
                lineHeight: 1,
                textAlign: "right",
              }}
            >
              {label}
            </span>
          ))}
        </div>
        <div style={{ flex: 1, minHeight: 0, position: "relative" }}>
          {goalOverlay && (
            <div
              style={{
                position: "absolute",
                right: 4,
                top: `${(goalOverlay.y / CHART_HEIGHT) * 100}%`,
                transform: "translateY(3px)",
                textAlign: "right",
                zIndex: 1,
                pointerEvents: "none",
                lineHeight: 1.3,
              }}
            >
              <div style={{ fontSize: 9, color: goalOverlay.color }}>目標</div>
              <div
                style={{
                  fontSize: 10,
                  color: goalOverlay.color,
                  fontWeight: 600,
                }}
              >
                {goalOverlay.label}
              </div>
            </div>
          )}
          <svg
            width="100%"
            height="100%"
            viewBox={`0 0 ${CHART_PLOT_WIDTH} ${CHART_HEIGHT}`}
            preserveAspectRatio="none"
            style={{ display: "block" }}
          >
            {children}
          </svg>
          {plotMarkers?.map((marker, index) => (
            <div
              key={index}
              style={{
                position: "absolute",
                left: `${(marker.x / CHART_PLOT_WIDTH) * 100}%`,
                top: `${(marker.y / CHART_HEIGHT) * 100}%`,
                transform: "translate(-50%, -50%)",
                width: 7,
                height: 7,
                borderRadius: "50%",
                background: "#fff",
                border: `2px solid ${marker.color}`,
                boxSizing: "border-box",
                pointerEvents: "none",
              }}
            />
          ))}
        </div>
      </div>
      <div
        style={{
          display: "flex",
          gap: CHART_AXIS_GAP,
          flexShrink: 0,
          paddingTop: 2,
        }}
      >
        <div
          style={{ width: Y_AXIS_WIDTH, flexShrink: 0 }}
          aria-hidden="true"
        />
        {dateRow}
      </div>
    </div>
  );
}

function WeightGraphCard({
  timelinePoints,
  timelineBounds,
  visibleWindowDays,
}: {
  timelinePoints: WeightTimelinePoint[];
  timelineBounds: {
    chartMin: number;
    chartMax: number;
    targetWeightKg: number | null;
  } | null;
  visibleWindowDays: number;
}) {
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const dateScrollRef = useRef<HTMLDivElement | null>(null);
  const [viewport, setViewport] = useState({ startIndex: 0, endIndex: 0 });
  const [dateLabelPhase, setDateLabelPhase] = useState(0);
  const [viewportWidth, setViewportWidth] = useState(CHART_PLOT_WIDTH);
  const effectiveVisibleDays = useMemo(
    () => Math.max(1, Math.min(visibleWindowDays, timelinePoints.length || 1)),
    [timelinePoints.length, visibleWindowDays],
  );
  const slotWidth = useMemo(() => {
    if (effectiveVisibleDays <= 0) {
      return TIMELINE_SLOT_WIDTH;
    }
    // 表示期間に対して1日幅を厳密に合わせ、グリッド端のズレを防ぐ
    return viewportWidth / effectiveVisibleDays;
  }, [effectiveVisibleDays, viewportWidth]);
  const dateLabelStep = useMemo(() => {
    if (visibleWindowDays <= 7) return 1;
    if (visibleWindowDays <= 30) return 4;
    if (visibleWindowDays <= 90) return 13;
    if (visibleWindowDays <= 180) return 26;
    if (visibleWindowDays <= 365) {
      // 1画面内で8つ前後（7区間）になるように動的計算
      return Math.max(1, Math.floor((effectiveVisibleDays - 1) / 7));
    }
    return 60;
  }, [effectiveVisibleDays, visibleWindowDays]);
  const rightLabelOffset = useMemo(() => {
    if (visibleWindowDays <= 7) {
      return 0;
    }
    if (visibleWindowDays <= 90) {
      return 5;
    }
    if (visibleWindowDays <= 180) {
      return 10;
    }
    if (visibleWindowDays <= 365) {
      return 15;
    }
    return Math.min(2, dateLabelStep - 1);
  }, [dateLabelStep, visibleWindowDays]);

  const chart = useMemo(() => {
    if (!timelineBounds || timelinePoints.length === 0) {
      return null;
    }

    const axis = buildWeightAxis(timelineBounds.chartMin, timelineBounds.chartMax);
    const plotMin = axis.chartMin;
    const plotMax = axis.chartMax;
    const xs = timelinePoints.map((_, index) => slotWidth * index + slotWidth / 2);
    const lineSegments = buildWeightLineSegments(xs, timelinePoints, plotMin, plotMax);
    const plotMarkers = timelinePoints.flatMap((point, index) => {
      if (point.value === null) {
        return [];
      }

      return [
        {
          x: xs[index],
          y: weightToY(point.value, plotMin, plotMax),
          color: ORANGE,
        },
      ];
    });

    return {
      axis,
      xs,
      lineSegments,
      plotMarkers,
      goalY:
        timelineBounds.targetWeightKg === null
          ? null
          : weightToY(timelineBounds.targetWeightKg, plotMin, plotMax),
      chartWidth: timelinePoints.length * slotWidth,
    };
  }, [slotWidth, timelineBounds, timelinePoints]);

  useEffect(() => {
    const element = scrollRef.current;
    if (!element || timelinePoints.length === 0) {
      return;
    }

    const updateViewport = () => {
      const nextViewportWidth = Math.max(1, element.clientWidth);
      setViewportWidth(nextViewportWidth);
      const visibleCount = Math.max(1, effectiveVisibleDays);
      const maxStart = Math.max(0, timelinePoints.length - visibleCount);
      const maxScrollLeft = Math.max(0, element.scrollWidth - element.clientWidth);
      const isNearRightEdge = element.scrollLeft >= maxScrollLeft - 1;
      const rawStart = isNearRightEdge
        ? maxStart
        : Math.round(element.scrollLeft / slotWidth);
      const startIndex = Math.min(maxStart, Math.max(0, rawStart));
      const endIndex = Math.min(timelinePoints.length - 1, startIndex + visibleCount - 1);
      setViewport({ startIndex, endIndex });
      if (dateScrollRef.current) {
        dateScrollRef.current.scrollLeft = element.scrollLeft;
      }
    };

    const alignToLatest = () => {
      const maxStart = Math.max(0, timelinePoints.length - effectiveVisibleDays);
      const endIndex = Math.min(
        timelinePoints.length - 1,
        maxStart + effectiveVisibleDays - 1,
      );
      const preferredRightLabelIndex = Math.max(0, endIndex - rightLabelOffset);
      setDateLabelPhase(preferredRightLabelIndex % dateLabelStep);
      element.scrollLeft = Math.round(maxStart * slotWidth);
      updateViewport();
    };

    const onWheel = (event: WheelEvent) => {
      const dominantDelta =
        Math.abs(event.deltaX) >= Math.abs(event.deltaY)
          ? event.deltaX
          : event.deltaY;

      if (Math.abs(dominantDelta) < 0.5) {
        return;
      }

      // ブラウザの履歴スワイプを抑止して、グラフ横スクロールに専念させる
      event.preventDefault();
      event.stopPropagation();
      element.scrollLeft += dominantDelta;
    };

    requestAnimationFrame(() => {
      requestAnimationFrame(alignToLatest);
    });

    element.addEventListener("scroll", updateViewport, { passive: true });
    element.addEventListener("wheel", onWheel, { passive: false });
    window.addEventListener("resize", updateViewport);

    return () => {
      element.removeEventListener("scroll", updateViewport);
      element.removeEventListener("wheel", onWheel);
      window.removeEventListener("resize", updateViewport);
    };
  }, [
    dateLabelStep,
    effectiveVisibleDays,
    rightLabelOffset,
    slotWidth,
    timelinePoints,
  ]);

  const stats = useMemo(() => {
    if (timelinePoints.length === 0) {
      return { average: null, diff: null, targetDiff: null };
    }

    const points = timelinePoints.slice(viewport.startIndex, viewport.endIndex + 1);
    const values = points
      .map((point) => point.value)
      .filter((value): value is number => value !== null);
    const first = values.length > 0 ? values[0] : null;
    const last = values.length > 0 ? values[values.length - 1] : null;

    return {
      average:
        values.length > 0
          ? roundToOneDecimal(values.reduce((sum, value) => sum + value, 0) / values.length)
          : null,
      diff:
        first !== null && last !== null ? roundToOneDecimal(last - first) : null,
      targetDiff:
        timelineBounds?.targetWeightKg !== null &&
        timelineBounds?.targetWeightKg !== undefined &&
        last !== null
          ? roundToOneDecimal(timelineBounds.targetWeightKg - last)
          : null,
    };
  }, [timelineBounds?.targetWeightKg, timelinePoints, viewport.endIndex, viewport.startIndex]);

  if (!chart || !timelineBounds) {
    return (
      <CardShell>
        <CardHeader
          icon={
            <SecIcon bg="#FDE8C8" color={ORANGE} size={26}>
              <Weight size={14} />
            </SecIcon>
          }
          label="体重"
        />
        <div
          style={{
            flex: 1,
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            color: "#AAA",
            fontSize: 13,
          }}
        >
          体重データがありません
        </div>
      </CardShell>
    );
  }

  const shouldShowDateLabel = (index: number) => {
    if (index < viewport.startIndex || index > viewport.endIndex) {
      return false;
    }
    return (index - dateLabelPhase) % dateLabelStep === 0;
  };
  const verticalLines = timelinePoints.flatMap((_, index) => {
    if (!shouldShowDateLabel(index)) {
      return [];
    }
    return [index * slotWidth + slotWidth / 2];
  });

  return (
    <CardShell>
      <CardHeader
        icon={
          <SecIcon bg="#FDE8C8" color={ORANGE} size={26}>
            <Weight size={14} />
          </SecIcon>
        }
        label="体重"
      />

      <div
        style={{
          flex: 1,
          minHeight: 0,
          width: "100%",
          display: "flex",
          flexDirection: "column",
        }}
      >
        <div
          style={{
            flex: 1,
            minHeight: 0,
            width: "100%",
            display: "flex",
            gap: CHART_AXIS_GAP,
            minWidth: 0,
          }}
        >
          <div
            style={{
              width: Y_AXIS_WIDTH,
              position: "relative",
              flexShrink: 0,
            }}
          >
            {chart.axis.labels.map((label, index) => (
              <span
                key={`${label}-${index}`}
                style={{
                  position: "absolute",
                  paddingLeft: 2,
                  top: `${(chart.axis.ticks[index] / CHART_HEIGHT) * 100}%`,
                  transform: "translateY(-50%)",
                  fontSize: chart.axis.labels.length > 5 ? 9 : 11,
                  color: "#7A7A7A",
                  fontWeight: 500,
                  lineHeight: 1,
                }}
              >
                {label}
              </span>
            ))}
          </div>
          <div style={{ flex: 1, minHeight: 0, minWidth: 0, position: "relative" }}>
            {chart.goalY !== null && timelineBounds.targetWeightKg !== null && (
              <div
                style={{
                  position: "absolute",
                  right: 4,
                  top: `${(chart.goalY / CHART_HEIGHT) * 100}%`,
                  transform: "translateY(3px)",
                  textAlign: "right",
                  zIndex: 2,
                  pointerEvents: "none",
                  lineHeight: 1.3,
                }}
              >
                <div style={{ fontSize: 9, color: ORANGE }}>目標</div>
                <div style={{ fontSize: 10, color: ORANGE, fontWeight: 600 }}>
                  {timelineBounds.targetWeightKg.toFixed(1)}kg
                </div>
              </div>
            )}
            <div
              ref={scrollRef}
              style={{
                width: "100%",
                height: "100%",
                overflowX: "auto",
                overflowY: "hidden",
                WebkitOverflowScrolling: "touch",
                scrollbarWidth: "thin",
                position: "relative",
                overscrollBehaviorX: "contain",
                overscrollBehaviorY: "contain",
              }}
            >
              <div
                style={{
                  position: "relative",
                  width: Math.max(CHART_PLOT_WIDTH, chart.chartWidth),
                  height: "100%",
                }}
              >
                <svg
                  width={Math.max(CHART_PLOT_WIDTH, chart.chartWidth)}
                  height="100%"
                  viewBox={`0 0 ${Math.max(CHART_PLOT_WIDTH, chart.chartWidth)} ${CHART_HEIGHT}`}
                  preserveAspectRatio="none"
                  style={{ display: "block" }}
                >
                  <ChartGrid
                    horizontalTicks={chart.axis.ticks}
                    verticalLines={verticalLines}
                    plotWidth={Math.max(CHART_PLOT_WIDTH, chart.chartWidth)}
                  />
                  {chart.goalY !== null && (
                    <line
                      x1="0"
                      y1={chart.goalY}
                      x2={Math.max(CHART_PLOT_WIDTH, chart.chartWidth)}
                      y2={chart.goalY}
                      stroke={ORANGE}
                      strokeWidth="1"
                      strokeDasharray="4 3"
                      opacity="0.5"
                    />
                  )}
                  {chart.lineSegments.map((segment, index) => (
                    <polyline
                      key={index}
                      points={segment}
                      fill="none"
                      stroke={ORANGE}
                      strokeWidth="2.5"
                      strokeLinejoin="round"
                      strokeLinecap="round"
                      vectorEffect="non-scaling-stroke"
                    />
                  ))}
                </svg>
                {chart.plotMarkers.map((marker, index) => (
                  <div
                    key={index}
                    style={{
                      position: "absolute",
                      left: marker.x,
                      top: `${(marker.y / CHART_HEIGHT) * 100}%`,
                      transform: "translate(-50%, -50%)",
                      width: 10,
                      height: 10,
                      borderRadius: "50%",
                      background: "#fff",
                      border: `2px solid ${marker.color}`,
                      boxSizing: "border-box",
                      pointerEvents: "none",
                    }}
                  />
                ))}
              </div>
            </div>
          </div>
        </div>
        <div
          style={{
            display: "flex",
            gap: CHART_AXIS_GAP,
            flexShrink: 0,
            paddingTop: 2,
          }}
        >
          <div style={{ width: Y_AXIS_WIDTH, flexShrink: 0 }} aria-hidden="true" />
          <div
            ref={dateScrollRef}
            style={{
              flex: 1,
              minWidth: 0,
              overflowX: "auto",
              overflowY: "hidden",
              scrollbarWidth: "none",
              pointerEvents: "none",
            }}
          >
            <div
              style={{
                width: Math.max(CHART_PLOT_WIDTH, chart.chartWidth),
                position: "relative",
                height: 20,
              }}
            >
              {timelinePoints.map((point, index) => (
                <span
                  key={`${point.date}-${index}`}
                  style={{
                    position: "absolute",
                    left: index * slotWidth + slotWidth / 2,
                    transform: "translateX(-50%)",
                    fontSize: 11,
                    color: "#888",
                    fontWeight: 500,
                    lineHeight: "20px",
                    whiteSpace: "nowrap",
                  }}
                >
                  {shouldShowDateLabel(index) ? point.label : ""}
                </span>
              ))}
            </div>
          </div>
        </div>
      </div>

      <StatBoxes
        accent={ORANGE}
        boxBg="#FFF5EB"
        items={[
          {
            value: stats.average === null ? "--" : `${stats.average.toFixed(1)} kg`,
            label: "平均",
          },
          {
            value: formatSignedKg(stats.diff),
            label: "変化量",
            highlight: true,
          },
          {
            value: formatSignedKg(stats.targetDiff),
            label: "目標まで",
            highlight: true,
          },
        ]}
      />
    </CardShell>
  );
}

function GoalLineSvg({ y, color }: { y: number; color: string }) {
  return (
    <line
      x1="0"
      y1={y}
      x2={CHART_PLOT_WIDTH}
      y2={y}
      stroke={color}
      strokeWidth="1"
      strokeDasharray="4 3"
      opacity="0.5"
    />
  );
}

function BarGraphCard({
  icon,
  label,
  barHeights,
  statItems,
  yAxisLabels,
  yAxisTicks,
  accent = ORANGE,
  boxBg = "#FFF5EB",
  goalLine,
}: {
  icon: ReactNode;
  label: string;
  barHeights: number[];
  statItems: { value: string; label: string; highlight?: boolean }[];
  yAxisLabels: string[];
  yAxisTicks: number[];
  accent?: string;
  boxBg?: string;
  goalLine?: { value: number; max: number; label: string };
}) {
  const goalY = goalLine ? valueToY(goalLine.value, goalLine.max) : undefined;
  const grid = buildChartGridLines(barHeights.length, yAxisTicks);

  return (
    <CardShell>
      <CardHeader icon={icon} label={label} />

      <GraphChart
        days={mockDays}
        yAxisLabels={yAxisLabels}
        yAxisTicks={yAxisTicks}
        goalOverlay={
          goalLine && goalY !== undefined
            ? { label: goalLine.label, color: accent, y: goalY }
            : undefined
        }
      >
        <ChartGrid
          horizontalTicks={grid.horizontalTicks}
          verticalLines={grid.verticalLines}
        />
        {goalLine && goalY !== undefined && (
          <GoalLineSvg y={goalY} color={accent} />
        )}
        {barHeights.map((height, index) => (
          <rect
            key={mockDays[index]}
            x={xsBar[index]}
            y={CHART_BOTTOM_Y - height * 1.4}
            width={BAR_WIDTH}
            height={height * 1.4}
            rx="2"
            fill={accent}
          />
        ))}
      </GraphChart>

      <StatBoxes accent={accent} boxBg={boxBg} items={statItems} />
    </CardShell>
  );
}

function MealGraphCard() {
  return (
    <BarGraphCard
      icon={
        <SecIcon bg="#FDE8C8" color={ORANGE} size={26}>
          <UtensilsCrossed size={14} />
        </SecIcon>
      }
      label="食事"
      goalLine={{ value: 1800, max: 3000, label: "1,800kcal" }}
      barHeights={kcalH}
      yAxisLabels={["3000", "2000", "1000", "0"]}
      yAxisTicks={[...KCAL_AXIS_TICKS]}
      statItems={[
        { value: "1,582 kcal", label: "平均" },
        { value: "-218 kcal", label: "目標差", highlight: true },
        { value: "85%", label: "達成率", highlight: true },
      ]}
    />
  );
}

function ExerciseGraphCard() {
  return (
    <BarGraphCard
      icon={
        <SecIcon bg={STEP_GREEN_BG} color={STEP_GREEN} size={26}>
          <PersonStanding size={14} />
        </SecIcon>
      }
      label="運動"
      barHeights={exerciseH}
      yAxisLabels={["3000", "2000", "1000", "0"]}
      yAxisTicks={[...KCAL_AXIS_TICKS]}
      accent={STEP_GREEN}
      boxBg={STEP_GREEN_LIGHT}
      statItems={[
        { value: "180 kcal", label: "平均" },
        { value: "-120 kcal", label: "目標差", highlight: true },
        { value: "60%", label: "達成率", highlight: true },
      ]}
    />
  );
}

function StepsGraphCard() {
  return (
    <BarGraphCard
      icon={
        <SecIcon bg={STEP_GREEN_BG} color={STEP_GREEN} size={26}>
          <Footprints size={14} />
        </SecIcon>
      }
      label="歩数"
      barHeights={stepH}
      yAxisLabels={["12000", "8000", "4000", "0"]}
      yAxisTicks={[...STEP_AXIS_TICKS]}
      accent={STEP_GREEN}
      boxBg={STEP_GREEN_LIGHT}
      statItems={[
        { value: "6,240 歩", label: "平均" },
        { value: "8,122 歩", label: "最高", highlight: true },
      ]}
    />
  );
}
