import {
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
  type RefObject,
} from "react";
import {
  Footprints,
  PersonStanding,
  UtensilsCrossed,
  Weight,
} from "lucide-react";
import { SecIcon } from "../SecIcon.tsx";
import { TopNav } from "../TopNav.tsx";
import { ORANGE } from "../../constants.ts";
import {
  fetchMetricTimeline,
  fetchWeightTimeline,
  type MetricTimelineResponse,
  type WeightTimelinePoint,
  type WeightTimelineResponse,
} from "../../api/client.ts";

const METRIC_TABS = ["体重", "食事", "運動", "歩数"] as const;
const PERIOD_TABS = ["週", "月", "3ヶ月", "半年", "1年", "3年"] as const;

const STEP_GREEN = "#2EAA72";
const STEP_GREEN_BG = "#D6F5E8";
const STEP_GREEN_LIGHT = "#EDF9F3";

const BAR_WIDTH = 13;
const CHART_HEIGHT = 130;
const CHART_PLOT_WIDTH = 310;
const Y_AXIS_WIDTH = 30;
const CHART_AXIS_GAP = 2;
const CHART_BOTTOM_Y = CHART_HEIGHT;
const DEFAULT_CALORIE_CHART_MAX = 3000;
const DEFAULT_STEP_CHART_MAX = 12000;

const CHART_GRID_COLOR = "#ECECEC";
const WEIGHT_AXIS_STEP_THRESHOLD_KG = 15;
const DATE_LABEL_HALF_WIDTH = 14;
const PERIOD_DAY_WINDOWS = [7, 30, 90, 180, 365, 1095] as const;
const DEFAULT_PERIOD_TAB = PERIOD_TABS.indexOf("月");
/** 3ヶ月（90日）以上の期間では体重の丸マーカーを非表示にする */
const WEIGHT_MARKER_MAX_VISIBLE_DAYS = 90;
const WEIGHT_SCROLL_FLOOR_YMD = "2026-01-01";

type WeightTimelineBundle = {
  visibleWindowDays: number;
  points: WeightTimelinePoint[];
  bounds: {
    chartMin: number;
    chartMax: number;
    targetWeightKg: number | null;
  };
  scrollFloor: string;
};

function toWeightTimelineBundle(
  visibleWindowDays: number,
  payload: WeightTimelineResponse["weight"],
): WeightTimelineBundle {
  return {
    visibleWindowDays,
    points: payload.points,
    scrollFloor: payload.scrollFloor,
    bounds: {
      chartMin: payload.chartMin,
      chartMax: payload.chartMax,
      targetWeightKg: payload.targetWeightKg,
    },
  };
}

function getTimelinePlotMetrics(clientWidth: number, visibleDays: number) {
  const safeClientWidth = Math.max(1, clientWidth);
  const safeVisibleDays = Math.max(1, visibleDays);
  const slotWidth = safeClientWidth / safeVisibleDays;
  const leftInset =
    slotWidth / 2 >= DATE_LABEL_HALF_WIDTH
      ? 0
      : Math.ceil(DATE_LABEL_HALF_WIDTH - slotWidth / 2);

  return {
    leftInset,
    contentWidth: safeClientWidth,
    slotWidth,
  };
}

function getTimelineLatestAlignment(
  clientWidth: number,
  visibleDays: number,
  timelineLength: number,
  scrollFloorIndex: number,
) {
  const metrics = getTimelinePlotMetrics(clientWidth, visibleDays);
  const { leftInset, slotWidth } = metrics;

  const leadingPadSlots = Math.max(0, visibleDays - timelineLength);
  const dataSlotCount = leadingPadSlots + timelineLength;
  const periodStartSlot = Math.max(0, dataSlotCount - visibleDays);

  const minScrollLeft =
    timelineLength > visibleDays
      ? Math.max(0, scrollFloorIndex * slotWidth)
      : 0;
  const targetScrollLeft = Math.max(
    minScrollLeft,
    periodStartSlot * slotWidth,
  );

  const minChartSlotsForScroll = Math.ceil(
    (targetScrollLeft + clientWidth) / slotWidth,
  );
  const chartSlotCount = Math.max(dataSlotCount, minChartSlotsForScroll);

  return {
    leftInset,
    slotWidth,
    leadingPadSlots,
    chartSlotCount,
    periodStartSlot,
    targetScrollLeft,
    minScrollLeft,
  };
}

function isTimelineAtInitialScroll(
  scrollLeft: number,
  targetScrollLeft: number,
) {
  return Math.abs(scrollLeft - targetScrollLeft) < 1;
}

function getInitialPeriodGridOffsets(
  visibleDays: number,
  dateLabelStep: number,
) {
  const intervalCount = Math.max(1, Math.round(visibleDays / dateLabelStep));
  return Array.from({ length: intervalCount + 1 }, (_, index) =>
    (index * visibleDays) / intervalCount,
  );
}

function buildInitialPeriodVerticalLines(
  periodStartSlot: number,
  visibleDays: number,
  slotWidth: number,
  dateLabelStep: number,
) {
  return getInitialPeriodGridOffsets(visibleDays, dateLabelStep).map(
    (offset) => (periodStartSlot + offset) * slotWidth,
  );
}

type TimelineDateLabel = {
  key: string;
  x: number;
  text: string;
};

function buildInitialPeriodDateLabels(
  points: { label: string; date: string }[],
  leadingPadSlots: number,
  periodStartSlot: number,
  visibleDays: number,
  slotWidth: number,
  dateLabelStep: number,
  useFullDateLabel: boolean,
): TimelineDateLabel[] {
  const offsets = getInitialPeriodGridOffsets(visibleDays, dateLabelStep);
  const labels: TimelineDateLabel[] = [];

  for (let index = 1; index < offsets.length - 1; index += 1) {
    const offset = offsets[index];
    const dataIndex = Math.min(
      points.length - 1,
      Math.max(0, Math.round(periodStartSlot - leadingPadSlots + offset)),
    );
    const point = points[dataIndex];
    labels.push({
      key: `${point.date}-${index}`,
      x: (periodStartSlot + offset) * slotWidth,
      text: useFullDateLabel
        ? formatFullDateLabel(point.date)
        : point.label,
    });
  }

  return labels;
}

function clampTimelineScrollLeft(
  scrollLeft: number,
  alignment: ReturnType<typeof getTimelineLatestAlignment>,
) {
  return Math.min(
    Math.max(scrollLeft, alignment.minScrollLeft),
    alignment.targetScrollLeft,
  );
}

function valueToY(value: number, max: number) {
  if (max <= 0) {
    return CHART_HEIGHT;
  }
  return CHART_HEIGHT - (value / max) * CHART_HEIGHT;
}

function buildValueAxis(
  chartMax: number,
  formatLabel: (value: number) => string,
) {
  const steps = 3;
  const yAxisTicks = Array.from({ length: steps + 1 }, (_, index) =>
    valueToY((chartMax * (steps - index)) / steps, chartMax),
  );
  const yAxisLabels = Array.from({ length: steps + 1 }, (_, index) =>
    formatLabel(Math.round((chartMax * (steps - index)) / steps)),
  );

  return { yAxisLabels, yAxisTicks };
}

function getTimelineDateLabelStep(
  visibleWindowDays: number,
  effectiveVisibleDays: number,
) {
  if (visibleWindowDays <= 7) {
    return 1;
  }
  if (visibleWindowDays <= 30) {
    return 4;
  }
  if (visibleWindowDays <= 90) {
    return 13;
  }
  if (visibleWindowDays <= 180) {
    return 26;
  }
  if (visibleWindowDays <= 365) {
    return Math.max(1, Math.floor((effectiveVisibleDays - 1) / 7));
  }
  return Math.max(1, Math.floor((effectiveVisibleDays - 1) / 4));
}

function getTimelineRightLabelOffset(
  visibleWindowDays: number,
  dateLabelStep: number,
) {
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
  return Math.min(60, dateLabelStep - 1);
}

function alignsWithDateLabelPhase(
  index: number,
  dateLabelPhase: number,
  dateLabelStep: number,
) {
  const step = Math.max(1, dateLabelStep);
  return ((index - dateLabelPhase) % step + step) % step === 0;
}

function shouldShowTimelineDateLabel(
  index: number,
  dateLabelPhase: number,
  dateLabelStep: number,
) {
  return alignsWithDateLabelPhase(index, dateLabelPhase, dateLabelStep);
}

function getTimelineDateLabelCenterX(
  index: number,
  leadingPadSlots: number,
  slotWidth: number,
) {
  return leadingPadSlots * slotWidth + index * slotWidth + slotWidth / 2;
}

function getLeftBorderOverlappingDateLabelIndex(
  pointCount: number,
  scrollLeft: number,
  leftInset: number,
  leadingPadSlots: number,
  slotWidth: number,
  dateLabelPhase: number,
  dateLabelStep: number,
) {
  let leftmostOverlappingIndex: number | null = null;
  let leftmostCenterX = Infinity;

  for (let index = 0; index < pointCount; index += 1) {
    if (!shouldShowTimelineDateLabel(index, dateLabelPhase, dateLabelStep)) {
      continue;
    }

    const centerX = getTimelineDateLabelCenterX(
      index,
      leadingPadSlots,
      slotWidth,
    );
    const offsetFromViewportLeft = leftInset + centerX - scrollLeft;

    // 表示領域の左端付近だけを対象にする（画面外の古い日付は除外）
    if (offsetFromViewportLeft > DATE_LABEL_HALF_WIDTH) {
      continue;
    }
    if (offsetFromViewportLeft < -DATE_LABEL_HALF_WIDTH) {
      continue;
    }

    if (centerX < leftmostCenterX) {
      leftmostCenterX = centerX;
      leftmostOverlappingIndex = index;
    }
  }

  return leftmostOverlappingIndex;
}

/**
 * 縦グリッドはスクロール位置（viewport）に依存させない。
 * 記録がない leading pad や chart 末尾の余白も含め、全スロットに一定間隔で引く。
 */
function buildTimelineVerticalLines(
  chartSlotCount: number,
  leadingPadSlots: number,
  dateLabelPhase: number,
  dateLabelStep: number,
  slotWidth: number,
) {
  return Array.from({ length: chartSlotCount }, (_, slot) => slot).flatMap(
    (slot) => {
      const dataIndex = slot - leadingPadSlots;
      return alignsWithDateLabelPhase(dataIndex, dateLabelPhase, dateLabelStep)
        ? [slot * slotWidth + slotWidth / 2]
        : [];
    },
  );
}

function getTimelineBarLayout(
  index: number,
  leadingPadSlots: number,
  slotWidth: number,
) {
  const barWidth = Math.min(BAR_WIDTH, Math.max(4, slotWidth * 0.55));
  const x =
    leadingPadSlots * slotWidth +
    index * slotWidth +
    (slotWidth - barWidth) / 2;

  return { barWidth, x };
}

function formatCalorieAxisLabel(value: number) {
  return value.toLocaleString();
}

function formatStepAxisLabel(value: number) {
  return value.toLocaleString();
}

function formatCalorieAverage(value: number | null) {
  if (value === null) {
    return "--";
  }
  return `${value.toLocaleString()} kcal`;
}

function formatStepAverage(value: number | null) {
  if (value === null) {
    return "--";
  }
  return `${value.toLocaleString()} 歩`;
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
  const segment: string[] = [];

  points.forEach((point, index) => {
    if (point.value === null) {
      return;
    }

    const y = weightToY(point.value, chartMin, chartMax);
    segment.push(`${xs[index]},${y}`);
  });

  return segment.length > 0 ? [segment.join(" ")] : [];
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

function formatFullDateLabel(date: string) {
  const [year, month, day] = date.split("-");
  return `${year}/${Number(month)}/${Number(day)}`;
}

interface GraphScreenProps {
  profileRefreshKey?: number;
}

export function GraphScreen({ profileRefreshKey = 0 }: GraphScreenProps) {
  const [metricTab, setMetricTab] = useState(0);
  const [periodTab, setPeriodTab] = useState(DEFAULT_PERIOD_TAB);
  const [displayedTimeline, setDisplayedTimeline] =
    useState<WeightTimelineBundle | null>(null);
  const [pendingTimeline, setPendingTimeline] =
    useState<WeightTimelineBundle | null>(null);
  const accent = metricTab >= 2 ? STEP_GREEN : ORANGE;
  const todayYmdRef = useRef(formatDateToYmd(new Date()));
  const displayedTimelineRef = useRef<WeightTimelineBundle | null>(null);
  const visibleWindowDays = PERIOD_DAY_WINDOWS[periodTab] ?? 7;
  displayedTimelineRef.current = displayedTimeline;

  useEffect(() => {
    let cancelled = false;
    const endDate = todayYmdRef.current;
    const requestedVisibleDays = visibleWindowDays;

    setPendingTimeline(null);

    fetchWeightTimeline(endDate, requestedVisibleDays)
      .then((response) => {
        if (cancelled) {
          return;
        }
        const bundle = toWeightTimelineBundle(
          requestedVisibleDays,
          response.weight,
        );
        if (displayedTimelineRef.current === null) {
          setDisplayedTimeline(bundle);
          return;
        }
        setPendingTimeline(bundle);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [visibleWindowDays, profileRefreshKey]);

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
      <TopNav title="記録を見る" />

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
              bundle={displayedTimeline}
              pendingBundle={pendingTimeline}
              onPendingCommitted={() => {
                setPendingTimeline((pending) => {
                  if (pending) {
                    setDisplayedTimeline(pending);
                  }
                  return null;
                });
              }}
            />
          )}
          {metricTab === 1 && (
            <MealGraphCard visibleWindowDays={visibleWindowDays} />
          )}
          {metricTab === 2 && (
            <ExerciseGraphCard visibleWindowDays={visibleWindowDays} />
          )}
          {metricTab === 3 && (
            <StepsGraphCard visibleWindowDays={visibleWindowDays} />
          )}
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
  const innerHorizontalTicks = horizontalTicks.filter(
    (y) => y > 0 && y < CHART_HEIGHT,
  );

  return (
    <>
      {innerHorizontalTicks.map((y) => (
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

function TimelineDateAxis({
  chartWidth,
  dateScrollRef,
  leftInset,
  isAtInitialPeriodView,
  points,
  leadingPadSlots,
  periodStartSlot,
  visibleWindowDays,
  effectiveVisibleDays,
  slotWidth,
  dateLabelPhase,
  dateLabelStep,
  hiddenLeftDateLabelIndex,
}: {
  chartWidth: number;
  dateScrollRef: RefObject<HTMLDivElement | null>;
  leftInset: number;
  isAtInitialPeriodView: boolean;
  points: { label: string; date: string }[];
  leadingPadSlots: number;
  periodStartSlot: number;
  visibleWindowDays: number;
  effectiveVisibleDays: number;
  slotWidth: number;
  dateLabelPhase: number;
  dateLabelStep: number;
  hiddenLeftDateLabelIndex: number | null;
}) {
  const useFullDateLabel = visibleWindowDays > 365;
  const initialLabels = useMemo(
    () =>
      buildInitialPeriodDateLabels(
        points,
        leadingPadSlots,
        periodStartSlot,
        effectiveVisibleDays,
        slotWidth,
        dateLabelStep,
        useFullDateLabel,
      ),
    [
      dateLabelStep,
      effectiveVisibleDays,
      leadingPadSlots,
      periodStartSlot,
      points,
      slotWidth,
      useFullDateLabel,
    ],
  );
  const shouldShowDateLabel = (index: number) =>
    shouldShowTimelineDateLabel(index, dateLabelPhase, dateLabelStep) &&
    index !== hiddenLeftDateLabelIndex;

  return (
    <div
      ref={dateScrollRef as RefObject<HTMLDivElement>}
      style={{
        flex: 1,
        minWidth: 0,
        overflowX: "auto",
        overflowY: "hidden",
        scrollbarWidth: "none",
        pointerEvents: "none",
        paddingLeft: isAtInitialPeriodView ? 0 : leftInset,
        boxSizing: "border-box",
      }}
    >
      <div
        style={{
          width: chartWidth,
          position: "relative",
          height: 20,
        }}
      >
        {isAtInitialPeriodView
          ? initialLabels.map((label) => (
              <span
                key={label.key}
                style={{
                  position: "absolute",
                  left: label.x,
                  transform: "translateX(-50%)",
                  fontSize: 11,
                  color: "#888",
                  fontWeight: 500,
                  lineHeight: "20px",
                  whiteSpace: "nowrap",
                }}
              >
                {label.text}
              </span>
            ))
          : points.map((point, index) => (
              <span
                key={`${point.date}-${index}`}
                style={{
                  position: "absolute",
                  left:
                    leadingPadSlots * slotWidth +
                    index * slotWidth +
                    slotWidth / 2,
                  transform: "translateX(-50%)",
                  fontSize: 11,
                  color: "#888",
                  fontWeight: 500,
                  lineHeight: "20px",
                  whiteSpace: "nowrap",
                }}
              >
                {shouldShowDateLabel(index)
                  ? useFullDateLabel
                    ? formatFullDateLabel(point.date)
                    : point.label
                  : ""}
              </span>
            ))}
      </div>
    </div>
  );
}

function BarGraphCard({
  icon,
  label,
  points,
  chartMax,
  visibleWindowDays,
  scrollFloor = WEIGHT_SCROLL_FLOOR_YMD,
  formatAxisLabel,
  formatAverage,
  accent = ORANGE,
  boxBg = "#FFF5EB",
}: {
  icon: ReactNode;
  label: string;
  points: { label: string; value: number; date: string }[];
  chartMax: number;
  visibleWindowDays: number;
  scrollFloor?: string;
  formatAxisLabel: (value: number) => string;
  formatAverage: (value: number | null) => string;
  accent?: string;
  boxBg?: string;
}) {
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const dateScrollRef = useRef<HTMLDivElement | null>(null);
  const [viewport, setViewport] = useState({ startIndex: 0, endIndex: 0 });
  const [scrollLeft, setScrollLeft] = useState(0);
  const [isAtInitialPeriodView, setIsAtInitialPeriodView] = useState(true);
  const [dateLabelPhase, setDateLabelPhase] = useState(0);
  const [viewportWidth, setViewportWidth] = useState(CHART_PLOT_WIDTH);
  const scrollFloorIndex = useMemo(() => {
    const index = points.findIndex((point) => point.date >= scrollFloor);
    return index >= 0 ? index : 0;
  }, [points, scrollFloor]);
  const effectiveVisibleDays = Math.max(1, visibleWindowDays);
  const latestAlignment = useMemo(
    () =>
      getTimelineLatestAlignment(
        viewportWidth,
        effectiveVisibleDays,
        points.length,
        scrollFloorIndex,
      ),
    [effectiveVisibleDays, points.length, scrollFloorIndex, viewportWidth],
  );
  const { leftInset, slotWidth, leadingPadSlots, chartSlotCount, periodStartSlot } =
    latestAlignment;
  const chartWidth = chartSlotCount * slotWidth;
  const dateLabelStep = useMemo(
    () => getTimelineDateLabelStep(visibleWindowDays, effectiveVisibleDays),
    [effectiveVisibleDays, visibleWindowDays],
  );
  const rightLabelOffset = useMemo(
    () => getTimelineRightLabelOffset(visibleWindowDays, dateLabelStep),
    [dateLabelStep, visibleWindowDays],
  );
  const { yAxisLabels, yAxisTicks } = buildValueAxis(chartMax, formatAxisLabel);

  useLayoutEffect(() => {
    const element = scrollRef.current;
    if (!element || points.length === 0) {
      return;
    }

    const nextViewportWidth = Math.max(1, element.clientWidth);
    const visibleCount = Math.max(1, effectiveVisibleDays);
    const alignment = getTimelineLatestAlignment(
      nextViewportWidth,
      visibleCount,
      points.length,
      scrollFloorIndex,
    );
    const maxStart = Math.max(0, points.length - visibleCount);
    const endIndex = Math.min(
      points.length - 1,
      maxStart + effectiveVisibleDays - 1,
    );
    const preferredRightLabelIndex = Math.max(0, endIndex - rightLabelOffset);
    const nextDateLabelPhase = preferredRightLabelIndex % dateLabelStep;
    const nextScrollLeft = alignment.targetScrollLeft;
    const startIndex = Math.min(
      maxStart,
      Math.max(
        scrollFloorIndex,
        Math.round(nextScrollLeft / alignment.slotWidth) - alignment.leadingPadSlots,
      ),
    );

    setViewportWidth(nextViewportWidth);
    setDateLabelPhase(nextDateLabelPhase);
    element.scrollLeft = nextScrollLeft;
    if (dateScrollRef.current) {
      dateScrollRef.current.scrollLeft = nextScrollLeft;
    }
    setScrollLeft(nextScrollLeft);
    setIsAtInitialPeriodView(true);
    setViewport({
      startIndex,
      endIndex: Math.min(points.length - 1, startIndex + visibleCount - 1),
    });
  }, [
    dateLabelStep,
    effectiveVisibleDays,
    points,
    rightLabelOffset,
    scrollFloorIndex,
    leadingPadSlots,
    visibleWindowDays,
  ]);

  useEffect(() => {
    const element = scrollRef.current;
    if (!element || points.length === 0) {
      return;
    }

    const updateViewport = () => {
      const nextViewportWidth = Math.max(1, element.clientWidth);
      setViewportWidth(nextViewportWidth);
      const visibleCount = Math.max(1, effectiveVisibleDays);
      const alignment = getTimelineLatestAlignment(
        nextViewportWidth,
        visibleCount,
        points.length,
        scrollFloorIndex,
      );
      const maxStart = Math.max(0, points.length - visibleCount);
      const clampedScrollLeft = clampTimelineScrollLeft(
        element.scrollLeft,
        alignment,
      );
      if (clampedScrollLeft !== element.scrollLeft) {
        element.scrollLeft = clampedScrollLeft;
      }
      const rawStart =
        Math.round(clampedScrollLeft / alignment.slotWidth) -
        alignment.leadingPadSlots;
      const startIndex = Math.min(
        maxStart,
        Math.max(scrollFloorIndex, rawStart),
      );
      const endIndex = Math.min(
        points.length - 1,
        startIndex + visibleCount - 1,
      );
      setScrollLeft(clampedScrollLeft);
      setIsAtInitialPeriodView(
        isTimelineAtInitialScroll(
          clampedScrollLeft,
          alignment.targetScrollLeft,
        ),
      );
      setViewport({ startIndex, endIndex });
      if (dateScrollRef.current) {
        dateScrollRef.current.scrollLeft = element.scrollLeft;
      }
    };

    const onWheel = (event: WheelEvent) => {
      const dominantDelta =
        Math.abs(event.deltaX) >= Math.abs(event.deltaY)
          ? event.deltaX
          : event.deltaY;

      if (Math.abs(dominantDelta) < 0.5) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      const wheelAlignment = getTimelineLatestAlignment(
        element.clientWidth,
        effectiveVisibleDays,
        points.length,
        scrollFloorIndex,
      );
      element.scrollLeft = clampTimelineScrollLeft(
        element.scrollLeft + dominantDelta,
        wheelAlignment,
      );
    };

    element.addEventListener("scroll", updateViewport, { passive: true });
    element.addEventListener("wheel", onWheel, { passive: false });
    window.addEventListener("resize", updateViewport);

    return () => {
      element.removeEventListener("scroll", updateViewport);
      element.removeEventListener("wheel", onWheel);
      window.removeEventListener("resize", updateViewport);
    };
  }, [effectiveVisibleDays, points, scrollFloorIndex]);

  const average = useMemo(() => {
    if (points.length === 0) {
      return null;
    }

    const visiblePoints = points.slice(viewport.startIndex, viewport.endIndex + 1);
    if (visiblePoints.length === 0) {
      return null;
    }

    const total = visiblePoints.reduce((sum, point) => sum + point.value, 0);
    return Math.round(total / visiblePoints.length);
  }, [points, viewport.endIndex, viewport.startIndex]);

  const hiddenLeftDateLabelIndex = useMemo(
    () =>
      isAtInitialPeriodView
        ? null
        : getLeftBorderOverlappingDateLabelIndex(
            points.length,
            scrollLeft,
            leftInset,
            leadingPadSlots,
            slotWidth,
            dateLabelPhase,
            dateLabelStep,
          ),
    [
      dateLabelPhase,
      dateLabelStep,
      isAtInitialPeriodView,
      leadingPadSlots,
      leftInset,
      points.length,
      scrollLeft,
      slotWidth,
    ],
  );
  const verticalLines = isAtInitialPeriodView
    ? buildInitialPeriodVerticalLines(
        periodStartSlot,
        effectiveVisibleDays,
        slotWidth,
        dateLabelStep,
      )
    : buildTimelineVerticalLines(
        chartSlotCount,
        leadingPadSlots,
        dateLabelPhase,
        dateLabelStep,
        slotWidth,
      );

  return (
    <CardShell>
      <CardHeader icon={icon} label={label} />

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
            {yAxisLabels.map((axisLabel, index) => (
              <span
                key={`${axisLabel}-${index}`}
                style={{
                  position: "absolute",
                  paddingLeft: 2,
                  top: `${(yAxisTicks[index] / CHART_HEIGHT) * 100}%`,
                  transform: "translateY(-50%)",
                  fontSize: yAxisLabels.length > 5 ? 9 : 11,
                  color: "#7A7A7A",
                  fontWeight: 500,
                  lineHeight: 1,
                }}
              >
                {axisLabel}
              </span>
            ))}
          </div>
          <div
            style={{ flex: 1, minHeight: 0, minWidth: 0, position: "relative" }}
          >
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
                border: `1px solid ${CHART_GRID_COLOR}`,
                overscrollBehaviorX: "contain",
                overscrollBehaviorY: "contain",
                paddingLeft: isAtInitialPeriodView ? 0 : leftInset,
                boxSizing: "border-box",
              }}
            >
              <div
                style={{
                  position: "relative",
                  width: chartWidth,
                  height: "100%",
                }}
              >
                <svg
                  width={chartWidth}
                  height="100%"
                  viewBox={`0 0 ${chartWidth} ${CHART_HEIGHT}`}
                  preserveAspectRatio="none"
                  style={{ display: "block" }}
                >
                  <ChartGrid
                    horizontalTicks={yAxisTicks}
                    verticalLines={verticalLines}
                    plotWidth={chartWidth}
                  />
                  {points.map((point, index) => {
                    const barHeight = (point.value / chartMax) * CHART_HEIGHT;
                    const { barWidth, x } = getTimelineBarLayout(
                      index,
                      leadingPadSlots,
                      slotWidth,
                    );
                    return (
                      <rect
                        key={point.date}
                        x={x}
                        y={CHART_BOTTOM_Y - barHeight}
                        width={barWidth}
                        height={barHeight}
                        rx="2"
                        fill={accent}
                      />
                    );
                  })}
                </svg>
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
          <div
            style={{ width: Y_AXIS_WIDTH, flexShrink: 0 }}
            aria-hidden="true"
          />
          <TimelineDateAxis
            chartWidth={chartWidth}
            dateScrollRef={dateScrollRef}
            leftInset={leftInset}
            isAtInitialPeriodView={isAtInitialPeriodView}
            points={points}
            leadingPadSlots={leadingPadSlots}
            periodStartSlot={periodStartSlot}
            visibleWindowDays={visibleWindowDays}
            effectiveVisibleDays={effectiveVisibleDays}
            slotWidth={slotWidth}
            dateLabelPhase={dateLabelPhase}
            dateLabelStep={dateLabelStep}
            hiddenLeftDateLabelIndex={hiddenLeftDateLabelIndex}
          />
        </div>
      </div>

      <StatBoxes
        accent={accent}
        boxBg={boxBg}
        items={[{ value: formatAverage(average), label: "平均" }]}
      />
    </CardShell>
  );
}

function WeightGraphCard({
  bundle,
  pendingBundle = null,
  onPendingCommitted,
}: {
  bundle: WeightTimelineBundle | null;
  pendingBundle?: WeightTimelineBundle | null;
  onPendingCommitted?: () => void;
}) {
  const [renderBundle, setRenderBundle] = useState<WeightTimelineBundle | null>(
    bundle,
  );
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const dateScrollRef = useRef<HTMLDivElement | null>(null);
  const pendingCommitRef = useRef(false);
  const [viewport, setViewport] = useState({ startIndex: 0, endIndex: 0 });
  const [scrollLeft, setScrollLeft] = useState(0);
  const [isAtInitialPeriodView, setIsAtInitialPeriodView] = useState(true);
  const [dateLabelPhase, setDateLabelPhase] = useState(0);
  const [viewportWidth, setViewportWidth] = useState(CHART_PLOT_WIDTH);

  useLayoutEffect(() => {
    if (pendingBundle) {
      setRenderBundle(pendingBundle);
      return;
    }
    if (bundle) {
      setRenderBundle(bundle);
    }
  }, [bundle, pendingBundle]);

  const timelinePoints = renderBundle?.points ?? [];
  const timelineBounds = renderBundle?.bounds ?? null;
  const visibleWindowDays = renderBundle?.visibleWindowDays ?? 7;
  const scrollFloor = renderBundle?.scrollFloor ?? WEIGHT_SCROLL_FLOOR_YMD;
  const scrollFloorIndex = useMemo(() => {
    const index = timelinePoints.findIndex((point) => point.date >= scrollFloor);
    return index >= 0 ? index : 0;
  }, [scrollFloor, timelinePoints]);
  const effectiveVisibleDays = useMemo(
    () => Math.max(1, visibleWindowDays),
    [visibleWindowDays],
  );
  const latestAlignment = useMemo(
    () =>
      getTimelineLatestAlignment(
        viewportWidth,
        effectiveVisibleDays,
        timelinePoints.length,
        scrollFloorIndex,
      ),
    [
      effectiveVisibleDays,
      scrollFloorIndex,
      timelinePoints.length,
      viewportWidth,
    ],
  );
  const { leftInset, slotWidth, leadingPadSlots, chartSlotCount, periodStartSlot } =
    latestAlignment;
  const dateLabelStep = useMemo(
    () => getTimelineDateLabelStep(visibleWindowDays, effectiveVisibleDays),
    [effectiveVisibleDays, visibleWindowDays],
  );
  const rightLabelOffset = useMemo(
    () => getTimelineRightLabelOffset(visibleWindowDays, dateLabelStep),
    [dateLabelStep, visibleWindowDays],
  );

  const chart = useMemo(() => {
    if (!timelineBounds || timelinePoints.length === 0) {
      return null;
    }

    const axis = buildWeightAxis(
      timelineBounds.chartMin,
      timelineBounds.chartMax,
    );
    const plotMin = axis.chartMin;
    const plotMax = axis.chartMax;
    const xs = timelinePoints.map(
      (_, index) =>
        (leadingPadSlots + index) * slotWidth + slotWidth / 2,
    );
    const lineSegments = buildWeightLineSegments(
      xs,
      timelinePoints,
      plotMin,
      plotMax,
    );
    const plotMarkers =
      visibleWindowDays >= WEIGHT_MARKER_MAX_VISIBLE_DAYS
        ? []
        : timelinePoints.flatMap((point, index) => {
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
      chartWidth: chartSlotCount * slotWidth,
    };
  }, [
    chartSlotCount,
    leadingPadSlots,
    slotWidth,
    timelineBounds,
    timelinePoints,
    visibleWindowDays,
  ]);

  useLayoutEffect(() => {
    const element = scrollRef.current;
    if (!element || timelinePoints.length === 0) {
      return;
    }

    const nextViewportWidth = Math.max(1, element.clientWidth);
    const visibleCount = Math.max(1, effectiveVisibleDays);
    const alignment = getTimelineLatestAlignment(
      nextViewportWidth,
      visibleCount,
      timelinePoints.length,
      scrollFloorIndex,
    );
    const maxStart = Math.max(0, timelinePoints.length - visibleCount);
    const endIndex = Math.min(
      timelinePoints.length - 1,
      maxStart + effectiveVisibleDays - 1,
    );
    const preferredRightLabelIndex = Math.max(0, endIndex - rightLabelOffset);
    const nextDateLabelPhase = preferredRightLabelIndex % dateLabelStep;
    const nextScrollLeft = alignment.targetScrollLeft;
    const startIndex = Math.min(
      maxStart,
      Math.max(
        scrollFloorIndex,
        Math.round(nextScrollLeft / alignment.slotWidth) - alignment.leadingPadSlots,
      ),
    );

    setViewportWidth(nextViewportWidth);
    setDateLabelPhase(nextDateLabelPhase);
    element.scrollLeft = nextScrollLeft;
    if (dateScrollRef.current) {
      dateScrollRef.current.scrollLeft = nextScrollLeft;
    }
    setScrollLeft(nextScrollLeft);
    setIsAtInitialPeriodView(true);
    setViewport({
      startIndex,
      endIndex: Math.min(timelinePoints.length - 1, startIndex + visibleCount - 1),
    });

    if (
      pendingBundle &&
      pendingBundle.visibleWindowDays === renderBundle?.visibleWindowDays &&
      !pendingCommitRef.current
    ) {
      pendingCommitRef.current = true;
      onPendingCommitted?.();
    }

    if (!pendingBundle) {
      pendingCommitRef.current = false;
    }
  }, [
    dateLabelStep,
    effectiveVisibleDays,
    onPendingCommitted,
    pendingBundle,
    renderBundle?.visibleWindowDays,
    rightLabelOffset,
    scrollFloorIndex,
    timelinePoints,
    leadingPadSlots,
    visibleWindowDays,
  ]);

  useEffect(() => {
    const element = scrollRef.current;
    if (!element || timelinePoints.length === 0) {
      return;
    }

    const updateViewport = () => {
      const nextViewportWidth = Math.max(1, element.clientWidth);
      setViewportWidth(nextViewportWidth);
      const visibleCount = Math.max(1, effectiveVisibleDays);
      const alignment = getTimelineLatestAlignment(
        nextViewportWidth,
        visibleCount,
        timelinePoints.length,
        scrollFloorIndex,
      );
      const maxStart = Math.max(0, timelinePoints.length - visibleCount);
      const clampedScrollLeft = clampTimelineScrollLeft(
        element.scrollLeft,
        alignment,
      );
      if (clampedScrollLeft !== element.scrollLeft) {
        element.scrollLeft = clampedScrollLeft;
      }
      const rawStart =
        Math.round(clampedScrollLeft / alignment.slotWidth) -
        alignment.leadingPadSlots;
      const startIndex = Math.min(
        maxStart,
        Math.max(scrollFloorIndex, rawStart),
      );
      const endIndex = Math.min(
        timelinePoints.length - 1,
        startIndex + visibleCount - 1,
      );
      setScrollLeft(clampedScrollLeft);
      setIsAtInitialPeriodView(
        isTimelineAtInitialScroll(
          clampedScrollLeft,
          alignment.targetScrollLeft,
        ),
      );
      setViewport({ startIndex, endIndex });
      if (dateScrollRef.current) {
        dateScrollRef.current.scrollLeft = element.scrollLeft;
      }
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
      const wheelAlignment = getTimelineLatestAlignment(
        element.clientWidth,
        effectiveVisibleDays,
        timelinePoints.length,
        scrollFloorIndex,
      );
      element.scrollLeft = clampTimelineScrollLeft(
        element.scrollLeft + dominantDelta,
        wheelAlignment,
      );
    };

    element.addEventListener("scroll", updateViewport, { passive: true });
    element.addEventListener("wheel", onWheel, { passive: false });
    window.addEventListener("resize", updateViewport);

    return () => {
      element.removeEventListener("scroll", updateViewport);
      element.removeEventListener("wheel", onWheel);
      window.removeEventListener("resize", updateViewport);
    };
  }, [
    effectiveVisibleDays,
    scrollFloorIndex,
    timelinePoints,
  ]);

  const stats = useMemo(() => {
    if (timelinePoints.length === 0) {
      return { average: null, diff: null, targetDiff: null };
    }

    const points = timelinePoints.slice(
      viewport.startIndex,
      viewport.endIndex + 1,
    );
    const values = points
      .map((point) => point.value)
      .filter((value): value is number => value !== null);
    const first = values.length > 0 ? values[0] : null;
    const last = values.length > 0 ? values[values.length - 1] : null;

    return {
      average:
        values.length > 0
          ? roundToOneDecimal(
              values.reduce((sum, value) => sum + value, 0) / values.length,
            )
          : null,
      diff:
        first !== null && last !== null
          ? roundToOneDecimal(last - first)
          : null,
      targetDiff:
        timelineBounds?.targetWeightKg !== null &&
        timelineBounds?.targetWeightKg !== undefined &&
        last !== null
          ? roundToOneDecimal(timelineBounds.targetWeightKg - last)
          : null,
    };
  }, [
    timelineBounds?.targetWeightKg,
    timelinePoints,
    viewport.endIndex,
    viewport.startIndex,
  ]);

  const hiddenLeftDateLabelIndex = useMemo(
    () =>
      isAtInitialPeriodView
        ? null
        : getLeftBorderOverlappingDateLabelIndex(
            timelinePoints.length,
            scrollLeft,
            leftInset,
            leadingPadSlots,
            slotWidth,
            dateLabelPhase,
            dateLabelStep,
          ),
    [
      dateLabelPhase,
      dateLabelStep,
      isAtInitialPeriodView,
      leadingPadSlots,
      leftInset,
      scrollLeft,
      slotWidth,
      timelinePoints.length,
    ],
  );

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

  const verticalLines = isAtInitialPeriodView
    ? buildInitialPeriodVerticalLines(
        periodStartSlot,
        effectiveVisibleDays,
        slotWidth,
        dateLabelStep,
      )
    : buildTimelineVerticalLines(
        chartSlotCount,
        leadingPadSlots,
        dateLabelPhase,
        dateLabelStep,
        slotWidth,
      );

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
          <div
            style={{ flex: 1, minHeight: 0, minWidth: 0, position: "relative" }}
          >
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
                border: `1px solid ${CHART_GRID_COLOR}`,
                overscrollBehaviorX: "contain",
                overscrollBehaviorY: "contain",
                paddingLeft: isAtInitialPeriodView ? 0 : leftInset,
                boxSizing: "border-box",
              }}
            >
              <div
                style={{
                  position: "relative",
                  width: chart.chartWidth,
                  height: "100%",
                }}
              >
                <svg
                  width={chart.chartWidth}
                  height="100%"
                  viewBox={`0 0 ${chart.chartWidth} ${CHART_HEIGHT}`}
                  preserveAspectRatio="none"
                  style={{ display: "block" }}
                >
                  <ChartGrid
                    horizontalTicks={chart.axis.ticks}
                    verticalLines={verticalLines}
                    plotWidth={chart.chartWidth}
                  />
                  {chart.goalY !== null && (
                    <line
                      x1="0"
                      y1={chart.goalY}
                      x2={chart.chartWidth}
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
          <div
            style={{ width: Y_AXIS_WIDTH, flexShrink: 0 }}
            aria-hidden="true"
          />
          <TimelineDateAxis
            chartWidth={chart.chartWidth}
            dateScrollRef={dateScrollRef}
            leftInset={leftInset}
            isAtInitialPeriodView={isAtInitialPeriodView}
            points={timelinePoints}
            leadingPadSlots={leadingPadSlots}
            periodStartSlot={periodStartSlot}
            visibleWindowDays={visibleWindowDays}
            effectiveVisibleDays={effectiveVisibleDays}
            slotWidth={slotWidth}
            dateLabelPhase={dateLabelPhase}
            dateLabelStep={dateLabelStep}
            hiddenLeftDateLabelIndex={hiddenLeftDateLabelIndex}
          />
        </div>
      </div>

      <StatBoxes
        accent={ORANGE}
        boxBg="#FFF5EB"
        items={[
          {
            value:
              stats.average === null ? "--" : `${stats.average.toFixed(1)} kg`,
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

function MetricBarGraphCard({
  metric,
  visibleWindowDays,
  icon,
  label,
  accent = ORANGE,
  boxBg = "#FFF5EB",
  defaultChartMax,
  formatAxisLabel,
  formatAverage,
}: {
  metric: MetricTimelineResponse["metric"];
  visibleWindowDays: number;
  icon: ReactNode;
  label: string;
  accent?: string;
  boxBg?: string;
  defaultChartMax: number;
  formatAxisLabel: (value: number) => string;
  formatAverage: (value: number | null) => string;
}) {
  const [data, setData] = useState<MetricTimelineResponse | null>(null);
  const todayYmdRef = useRef(formatDateToYmd(new Date()));

  useEffect(() => {
    let cancelled = false;

    fetchMetricTimeline(metric, todayYmdRef.current, visibleWindowDays)
      .then((response) => {
        if (!cancelled) {
          setData(response);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setData(null);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [metric, visibleWindowDays]);

  const points = data?.points ?? [];
  const chartMax = data?.chartMax ?? defaultChartMax;
  const scrollFloor = data?.scrollFloor ?? WEIGHT_SCROLL_FLOOR_YMD;

  if (!data) {
    return (
      <CardShell>
        <CardHeader icon={icon} label={label} />
        <div
          style={{
            flex: 1,
            minHeight: 120,
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            color: "#AAA",
            fontSize: 13,
          }}
        >
          読み込み中...
        </div>
      </CardShell>
    );
  }

  return (
    <BarGraphCard
      icon={icon}
      label={label}
      points={points}
      chartMax={chartMax}
      visibleWindowDays={visibleWindowDays}
      scrollFloor={scrollFloor}
      formatAxisLabel={formatAxisLabel}
      formatAverage={formatAverage}
      accent={accent}
      boxBg={boxBg}
    />
  );
}

function MealGraphCard({
  visibleWindowDays,
}: {
  visibleWindowDays: number;
}) {
  return (
    <MetricBarGraphCard
      metric="meals"
      visibleWindowDays={visibleWindowDays}
      icon={
        <SecIcon bg="#FDE8C8" color={ORANGE} size={26}>
          <UtensilsCrossed size={14} />
        </SecIcon>
      }
      label="食事"
      defaultChartMax={DEFAULT_CALORIE_CHART_MAX}
      formatAxisLabel={formatCalorieAxisLabel}
      formatAverage={formatCalorieAverage}
    />
  );
}

function ExerciseGraphCard({
  visibleWindowDays,
}: {
  visibleWindowDays: number;
}) {
  return (
    <MetricBarGraphCard
      metric="exercise"
      visibleWindowDays={visibleWindowDays}
      icon={
        <SecIcon bg={STEP_GREEN_BG} color={STEP_GREEN} size={26}>
          <PersonStanding size={14} />
        </SecIcon>
      }
      label="運動"
      defaultChartMax={DEFAULT_CALORIE_CHART_MAX}
      formatAxisLabel={formatCalorieAxisLabel}
      formatAverage={formatCalorieAverage}
      accent={STEP_GREEN}
      boxBg={STEP_GREEN_LIGHT}
    />
  );
}

function StepsGraphCard({
  visibleWindowDays,
}: {
  visibleWindowDays: number;
}) {
  return (
    <MetricBarGraphCard
      metric="steps"
      visibleWindowDays={visibleWindowDays}
      icon={
        <SecIcon bg={STEP_GREEN_BG} color={STEP_GREEN} size={26}>
          <Footprints size={14} />
        </SecIcon>
      }
      label="歩数"
      defaultChartMax={DEFAULT_STEP_CHART_MAX}
      formatAxisLabel={formatStepAxisLabel}
      formatAverage={formatStepAverage}
      accent={STEP_GREEN}
      boxBg={STEP_GREEN_LIGHT}
    />
  );
}
