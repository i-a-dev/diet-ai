import { useState, type ReactNode } from "react";
import {
  Calendar,
  ChevronLeft,
  ChevronRight,
  Footprints,
  PersonStanding,
  UtensilsCrossed,
  Weight,
} from "lucide-react";
import { SecIcon } from "../SecIcon.tsx";
import { TopNav } from "../TopNav.tsx";
import { ORANGE } from "../../constants.ts";

const METRIC_TABS = ["体重", "食事", "運動", "歩数"] as const;
const PERIOD_TABS = ["週", "月", "3ヶ月", "半年", "1年", "3年"] as const;

const STEP_GREEN = "#2EAA72";
const STEP_GREEN_BG = "#D6F5E8";
const STEP_GREEN_LIGHT = "#EDF9F3";

const days = ["4/18", "4/19", "4/20", "4/21", "4/22", "4/23", "4/24"];
const kcalH = [44, 60, 38, 52, 36, 66, 54];
const exerciseH = [28, 42, 20, 36, 24, 48, 32];
const stepH = [48, 64, 34, 56, 42, 68, 52];
const BAR_WIDTH = 13;
const CHART_HEIGHT = 130;
const CHART_PLOT_WIDTH = 310;
const Y_AXIS_WIDTH = 40;
const CHART_BOTTOM_Y = CHART_HEIGHT;
const KCAL_AXIS_TICKS = [0, 43, 86, CHART_HEIGHT] as const;
const STEP_AXIS_TICKS = [0, 43, 86, CHART_HEIGHT] as const;
const WEIGHT_AXIS_LABELS = ["64", "63", "62"] as const;
const WEIGHT_AXIS_TICKS = [0, 65, CHART_HEIGHT] as const;
const WEIGHT_MIN = 62;
const WEIGHT_MAX = 64;
const weightData = [62.85, 62.75, 62.35, 62.55, 62.65, 62.8, 62.9];

function valueToY(value: number, max: number) {
  return CHART_HEIGHT - (value / max) * CHART_HEIGHT;
}

function weightToY(weight: number) {
  return ((WEIGHT_MAX - weight) / (WEIGHT_MAX - WEIGHT_MIN)) * CHART_HEIGHT;
}

const xsBar = [22.5, 65.5, 108.5, 151.5, 194.5, 237.5, 280.5];
const xsLine = [44, 87, 130, 173, 216, 259, 302];

const plusBtn = (
  <span
    style={{ color: ORANGE, fontSize: 26, lineHeight: 1, cursor: "pointer" }}
  >
    +
  </span>
);

export function GraphScreen() {
  const [metricTab, setMetricTab] = useState(0);
  const [periodTab, setPeriodTab] = useState(0);
  const accent = metricTab >= 2 ? STEP_GREEN : ORANGE;

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
            display: "flex",
            alignItems: "center",
            justifyContent: "space-between",
            padding: "2px 20px 8px",
            flexShrink: 0,
          }}
        >
          <ChevronLeft size={20} color="#C0C0C0" />
          <span style={{ fontSize: 13, color: "#666" }}>
            4/18（木）〜 4/24（水）
          </span>
          <ChevronRight size={20} color="#C0C0C0" />
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
          {metricTab === 0 && <WeightGraphCard />}
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
        padding: "10px 12px",
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
        // , marginBottom: 15
      }}
    >
      <div
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
        }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          {icon}
          <span style={{ fontSize: 15, fontWeight: 600, color: "#222" }}>
            {label}
          </span>
        </div>
        {plusBtn}
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

function GraphChart({
  children,
  yAxisLabels,
  yAxisTicks,
  goalOverlay,
  plotMarkers,
}: {
  children: ReactNode;
  yAxisLabels?: string[];
  yAxisTicks?: number[];
  goalOverlay?: { label: string; color: string };
  plotMarkers?: { x: number; y: number; color: string }[];
}) {
  const showYAxis = Boolean(
    yAxisLabels && yAxisTicks && yAxisLabels.length === yAxisTicks.length,
  );

  const dateRow = (
    <div
      style={{
        display: "flex",
        justifyContent: "space-between",
        padding: `2px 4px 0 ${showYAxis ? `${Y_AXIS_WIDTH}px` : "4px"}`,
        flexShrink: 0,
      }}
    >
      {days.map((day) => (
        <span
          key={day}
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
          gap: 6,
        }}
      >
        <div
          style={{
            width: Y_AXIS_WIDTH,
            height: "100%",
            position: "relative",
            flexShrink: 0,
          }}
        >
          {yAxisLabels!.map((label, index) => (
            <span
              key={`${label}-${index}`}
              style={{
                position: "absolute",
                left: 0,
                top: `${(yAxisTicks![index] / CHART_HEIGHT) * 100}%`,
                transform: "translateY(-50%)",
                fontSize: 11,
                color: "#7A7A7A",
                fontWeight: 500,
                lineHeight: 1,
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
                top: 4,
                right: 4,
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
      {dateRow}
    </div>
  );
}

function WeightGraphCard() {
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

      <GraphChart
        yAxisLabels={[...WEIGHT_AXIS_LABELS]}
        yAxisTicks={[...WEIGHT_AXIS_TICKS]}
        plotMarkers={xsLine.map((x, index) => ({
          x,
          y: weightToY(weightData[index]),
          color: ORANGE,
        }))}
      >
        {WEIGHT_AXIS_TICKS.map((y) => (
          <line
            key={y}
            x1="0"
            y1={y}
            x2={CHART_PLOT_WIDTH}
            y2={y}
            stroke="#F0F0F0"
            strokeWidth="1"
          />
        ))}
        <polyline
          points={xsLine
            .map((x, i) => `${x},${weightToY(weightData[i])}`)
            .join(" ")}
          fill="none"
          stroke={ORANGE}
          strokeWidth="2.5"
          strokeLinejoin="round"
          strokeLinecap="round"
          vectorEffect="non-scaling-stroke"
        />
      </GraphChart>

      <StatBoxes
        accent={ORANGE}
        boxBg="#FFF5EB"
        items={[
          { value: "62.7 kg", label: "平均" },
          { value: "-0.8 kg", label: "変化量", highlight: true },
          { value: "-5.4 kg", label: "目標まで", highlight: true },
        ]}
      />
    </CardShell>
  );
}

function GoalLineSvg({ y, color }: { y: number; color: string }) {
  const connectorX = CHART_PLOT_WIDTH - 14;

  return (
    <>
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
      <line
        x1={connectorX}
        y1={y}
        x2={connectorX}
        y2={24}
        stroke={color}
        strokeWidth="1"
        opacity="0.7"
      />
    </>
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

  return (
    <CardShell>
      <CardHeader icon={icon} label={label} />

      <GraphChart
        yAxisLabels={yAxisLabels}
        yAxisTicks={yAxisTicks}
        goalOverlay={
          goalLine ? { label: goalLine.label, color: accent } : undefined
        }
      >
        {yAxisTicks.map((y) => (
          <line
            key={y}
            x1="0"
            y1={y}
            x2={CHART_PLOT_WIDTH}
            y2={y}
            stroke="#F5F5F5"
            strokeWidth="1"
          />
        ))}
        {goalLine && goalY !== undefined && (
          <GoalLineSvg y={goalY} color={accent} />
        )}
        {barHeights.map((height, index) => (
          <rect
            key={days[index]}
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
