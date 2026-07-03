import { useEffect, useMemo, useState } from "react";
import {
  fetchWeightTimeline,
  type WeightTimelinePoint,
} from "../api/client.ts";
import { ORANGE } from "../constants.ts";

const WIDTH = 110;
const HEIGHT = 52;
const PADDING_X = 6;
const PADDING_Y = 6;
const VISIBLE_DAYS = 7;

function shiftDate(baseDate: string, dayOffset: number) {
  const [year, month, day] = baseDate.split("-").map(Number);
  const shifted = new Date(year, month - 1, day + dayOffset);
  const shiftedYear = shifted.getFullYear();
  const shiftedMonth = `${shifted.getMonth() + 1}`.padStart(2, "0");
  const shiftedDay = `${shifted.getDate()}`.padStart(2, "0");
  return `${shiftedYear}-${shiftedMonth}-${shiftedDay}`;
}

function getVisibleWeekPoints(
  points: WeightTimelinePoint[],
  endDate: string,
): WeightTimelinePoint[] {
  const startDate = shiftDate(endDate, -(VISIBLE_DAYS - 1));
  const byDate = new Map(points.map((point) => [point.date, point]));
  const visiblePoints: WeightTimelinePoint[] = [];

  for (let offset = 0; offset < VISIBLE_DAYS; offset += 1) {
    const date = shiftDate(startDate, offset);
    const existing = byDate.get(date);
    visiblePoints.push(
      existing ?? {
        date,
        label: "",
        value: null,
      },
    );
  }

  return visiblePoints;
}

function buildSparklineGeometry(
  points: WeightTimelinePoint[],
  selectedDate: string,
): {
  linePoints: string;
  hollowMarkers: { x: number; y: number; index: number }[];
  selectedDateMarker: { x: number; y: number } | null;
} | null {
  const visiblePoints = getVisibleWeekPoints(points, selectedDate);
  const values = visiblePoints
    .map((point) => point.value)
    .filter((value): value is number => value !== null);

  if (values.length < 2) {
    return null;
  }

  const dataMin = Math.min(...values);
  const dataMax = Math.max(...values);
  const range = dataMax - dataMin;
  const padding = range > 0 ? range * 0.15 : 0.5;
  const chartMin = dataMin - padding;
  const chartMax = dataMax + padding;
  const plotWidth = WIDTH - PADDING_X * 2;
  const plotHeight = HEIGHT - PADDING_Y * 2;

  const toY = (value: number) =>
    PADDING_Y + ((chartMax - value) / (chartMax - chartMin)) * plotHeight;

  const toX = (index: number) =>
    visiblePoints.length <= 1
      ? WIDTH / 2
      : PADDING_X + (index / (visiblePoints.length - 1)) * plotWidth;

  const linePoints = visiblePoints.flatMap((point, index) => {
    if (point.value === null) {
      return [];
    }

    return [`${toX(index)},${toY(point.value)}`];
  });

  const hollowMarkers: { x: number; y: number; index: number }[] = [];
  let selectedDateMarker: { x: number; y: number } | null = null;

  visiblePoints.forEach((point, index) => {
    if (point.value === null) {
      return;
    }

    const marker = { x: toX(index), y: toY(point.value) };

    if (point.date === selectedDate) {
      selectedDateMarker = marker;
      return;
    }

    hollowMarkers.push({ ...marker, index });
  });

  return {
    linePoints: linePoints.join(" "),
    hollowMarkers,
    selectedDateMarker,
  };
}

interface WeightSparklineProps {
  selectedDate: string;
  refreshKey?: number;
}

export function WeightSparkline({
  selectedDate,
  refreshKey = 0,
}: WeightSparklineProps) {
  const [points, setPoints] = useState<WeightTimelinePoint[]>([]);

  useEffect(() => {
    let cancelled = false;

    void fetchWeightTimeline(selectedDate, VISIBLE_DAYS)
      .then((response) => {
        if (!cancelled) {
          setPoints(response.weight.points);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setPoints([]);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [selectedDate, refreshKey]);

  const geometry = useMemo(
    () => buildSparklineGeometry(points, selectedDate),
    [points, selectedDate],
  );

  if (!geometry) {
    return null;
  }

  return (
    <svg
      width={WIDTH}
      height={HEIGHT}
      viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
      style={{ overflow: "visible" }}
    >
      <polyline
        points={geometry.linePoints}
        fill="none"
        stroke={ORANGE}
        strokeWidth="2.2"
        strokeLinejoin="round"
        strokeLinecap="round"
      />
      {geometry.hollowMarkers.map((marker) => (
        <circle
          key={marker.index}
          cx={marker.x}
          cy={marker.y}
          r="3"
          fill="#fff"
          stroke={ORANGE}
          strokeWidth="1.8"
        />
      ))}
      {geometry.selectedDateMarker && (
        <circle
          cx={geometry.selectedDateMarker.x}
          cy={geometry.selectedDateMarker.y}
          r="4"
          fill={ORANGE}
        />
      )}
    </svg>
  );
}
