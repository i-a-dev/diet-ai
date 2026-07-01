export type ExerciseIntensityLevel = "easy" | "normal" | "firm" | "hard";

export interface MetsComparisonItem {
  name: string;
  mets: number;
  isCurrent: boolean;
}

const INTENSITY_SEGMENTS: Array<{
  level: ExerciseIntensityLevel;
  label: string;
  minMets: number;
}> = [
  { level: "easy", label: "らく", minMets: 0 },
  { level: "normal", label: "ふつう", minMets: 3 },
  { level: "firm", label: "しっかり", minMets: 5 },
  { level: "hard", label: "ハード", minMets: 8 },
];

const WALKING_METS = 3.0;
const JOGGING_METS = 7.0;

export function getIntensityLevel(mets: number): ExerciseIntensityLevel {
  if (mets < 3) return "easy";
  if (mets < 5) return "normal";
  if (mets < 8) return "firm";
  return "hard";
}

export function getIntensityLabel(level: ExerciseIntensityLevel): string {
  switch (level) {
    case "easy":
      return "らくめ";
    case "normal":
      return "ふつう";
    case "firm":
      return "しっかりめ";
    case "hard":
      return "ハード";
  }
}

export function getIntensityDescription(mets: number): string {
  const ratio = mets / WALKING_METS;
  if (ratio < 1.2) return "ウォーキング程度";
  if (ratio < 1.6) return "早歩き程度";
  const rounded = Math.round(ratio * 10) / 10;
  const display = Number.isInteger(rounded)
    ? String(rounded)
    : rounded.toFixed(1);
  return `早歩きの約${display}倍`;
}

export function getIntensitySegments(level: ExerciseIntensityLevel) {
  return INTENSITY_SEGMENTS.map((segment) => ({
    ...segment,
    active: segment.level === level,
  }));
}

export function buildEquivalenceText(
  exercise: string,
  estimatedExercise: string,
  source: "local_db" | "llm_estimate",
): string {
  if (estimatedExercise !== "" && estimatedExercise !== exercise) {
    return `${exercise}は${estimatedExercise}と同じくらいの運動強度として計算しています。`;
  }
  if (source === "local_db") {
    return `${exercise}は登録済みの運動強度（METs）として計算しています。`;
  }
  return `${exercise}の運動強度として計算しています。`;
}

export function buildMetsComparisons(
  exerciseName: string,
  mets: number,
): MetsComparisonItem[] {
  const items: MetsComparisonItem[] = [
    { name: "ウォーキング", mets: WALKING_METS, isCurrent: false },
    { name: exerciseName, mets, isCurrent: true },
    { name: "ジョギング", mets: JOGGING_METS, isCurrent: false },
  ];

  return items.sort((left, right) => left.mets - right.mets);
}

export function formatMetsValue(mets: number): string {
  return Number.isInteger(mets) ? String(mets) : mets.toFixed(1);
}

export function buildCalorieFormulaText(
  mets: number,
  weightKg: number,
  minutes: number,
  caloriesBurned: number,
): string {
  const weightText =
    Number.isInteger(weightKg) || weightKg % 1 === 0
      ? String(weightKg)
      : weightKg.toFixed(1);
  return `${formatMetsValue(mets)} METs × ${weightText}kg × ${minutes}分÷60 = ${caloriesBurned}kcal`;
}
