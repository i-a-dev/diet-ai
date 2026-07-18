import { useEffect, useMemo, useState } from "react";
import {
  ChevronLeft,
  ChevronRight,
  Dumbbell,
  Footprints,
  HeartPlus,
  Lollipop,
  Moon,
  PersonStanding,
  StickyNote,
  Sun,
  Sunrise,
  Utensils,
} from "lucide-react";
import {
  type ExerciseEntrySummary,
  type MealSectionSummary,
  deleteMeal,
  fetchDailyRecord,
  fetchUserProfile,
  saveExercise,
  saveMeal,
  saveSteps,
  saveWeight,
} from "../../api/client.ts";
import { ActivitySubSection } from "../ActivitySubSection.tsx";
import { ExerciseChip } from "../ExerciseChip.tsx";
import type { MealItemInput } from "../AddFoodModal.tsx";
import { AddFoodModal } from "../AddFoodModal.tsx";
import { BottomSheet } from "../BottomSheet.tsx";
import {
  type ExerciseInput,
  ExerciseRegisterSheet,
} from "../ExerciseRegisterSheet.tsx";
import { MealEntryDetailSheet } from "../MealEntryDetailSheet.tsx";
import { MealSection } from "../MealSection.tsx";
import { SecIcon } from "../SecIcon.tsx";
import { StepsRegisterSheet } from "../StepsRegisterSheet.tsx";
import { TopNav } from "../TopNav.tsx";
import { WeightRegisterSheet } from "../WeightRegisterSheet.tsx";
import { WeightSparkline } from "../WeightSparkline.tsx";
import {
  buildRegistrationMetricsFromSteps,
} from "../../utils/registrationMetrics.ts";
import { ORANGE } from "../../constants.ts";

type MealKey = "breakfast" | "lunch" | "dinner" | "snack";

interface MealSectionData {
  title: string;
  icon: React.ReactNode;
  items: MealItemInput[];
  isLast?: boolean;
}

const BASE_MEALS: Record<MealKey, Omit<MealSectionData, "items">> = {
  breakfast: {
    title: "朝ごはん",
    icon: <Sunrise size={14} />,
  },
  lunch: {
    title: "昼ごはん",
    icon: <Sun size={14} />,
  },
  dinner: {
    title: "夜ごはん",
    icon: <Moon size={14} />,
  },
  snack: {
    title: "間食・おやつ",
    icon: <Lollipop size={14} />,
    isLast: true,
  },
};

function parseKcal(kcal: string) {
  return Number(kcal.replace(/[^\d]/g, "")) || 0;
}

const NET_INTAKE_BUFFER_KCAL = 300;
const NET_INTAKE_OVER_COLOR = "#C0392B";

function formatKcal(value: number) {
  return value.toLocaleString();
}

function formatWeightDiff(diff: number | null) {
  if (diff === null) return "前日比 --";
  if (diff === 0) return "前日比 ±0.0kg";
  return diff > 0
    ? `前日比 +${diff.toFixed(1)}kg`
    : `前日比 ${diff.toFixed(1)}kg`;
}

function shiftDate(baseDate: string, dayOffset: number) {
  const [year, month, day] = baseDate.split("-").map(Number);
  const shifted = new Date(year, month - 1, day + dayOffset);
  const shiftedYear = shifted.getFullYear();
  const shiftedMonth = `${shifted.getMonth() + 1}`.padStart(2, "0");
  const shiftedDay = `${shifted.getDate()}`.padStart(2, "0");
  return `${shiftedYear}-${shiftedMonth}-${shiftedDay}`;
}

function formatCurrentDate() {
  const now = new Date();
  const year = now.getFullYear();
  const month = `${now.getMonth() + 1}`.padStart(2, "0");
  const day = `${now.getDate()}`.padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function createInitialMeals(): Record<MealKey, MealSectionData> {
  return {
    breakfast: { ...BASE_MEALS.breakfast, items: [] },
    lunch: { ...BASE_MEALS.lunch, items: [] },
    dinner: { ...BASE_MEALS.dinner, items: [] },
    snack: { ...BASE_MEALS.snack, items: [] },
  };
}

interface ActiveMealEntry {
  mealKey: MealKey;
  item: MealItemInput;
}

function mapMealSectionsToState(
  sections: MealSectionSummary[] = [],
): Record<MealKey, MealSectionData> {
  const nextMeals = createInitialMeals();

  sections.forEach((section) => {
    const key = section.id;
    if (!nextMeals[key]) return;

    nextMeals[key] = {
      ...nextMeals[key],
      items: section.items.map((item) => ({
        id: item.id,
        label: item.label,
        kcal: `${item.calories}kcal`,
        caloriesEdited: item.caloriesEdited,
        calorieSource: item.calorieSource ?? null,
        sourceUrl: item.sourceUrl ?? null,
        confidence: item.confidence ?? null,
        foodId: item.foodId ?? null,
        rawInput: item.rawInput ?? null,
        amount: item.amount ?? null,
        unit: item.unit ?? null,
        servingLabel: item.servingLabel ?? null,
        servingWeightG: item.servingWeightG ?? null,
        proteinG: item.proteinG ?? null,
        fatG: item.fatG ?? null,
        carbsG: item.carbsG ?? null,
        fiberG: item.fiberG ?? null,
        sodiumMg: item.sodiumMg ?? null,
      })),
    };
  });

  return nextMeals;
}

export function RecordScreen() {
  const [weight, setWeight] = useState<number | null>(null);
  const [referenceWeight, setReferenceWeight] = useState<number | null>(null);
  const [weightDiff, setWeightDiff] = useState<number | null>(null);
  const [dateLabel, setDateLabel] = useState("読み込み中...");
  const [selectedDate, setSelectedDate] = useState<string>(formatCurrentDate);
  const [recordedOn, setRecordedOn] = useState<string>();
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [meals, setMeals] =
    useState<Record<MealKey, MealSectionData>>(createInitialMeals);
  const [weightSheetOpen, setWeightSheetOpen] = useState(false);
  const [mealSheetOpen, setMealSheetOpen] = useState(false);
  const [stepsSheetOpen, setStepsSheetOpen] = useState(false);
  const [exerciseSheetOpen, setExerciseSheetOpen] = useState(false);
  const [activeMealKey, setActiveMealKey] = useState<MealKey | null>(null);
  const [steps, setSteps] = useState({ count: 0, burnedCalories: 0 });
  const [exercises, setExercises] = useState<ExerciseEntrySummary[]>([]);
  const [exerciseNotice, setExerciseNotice] = useState<string | null>(null);
  const [activeExerciseNote, setActiveExerciseNote] =
    useState<ExerciseEntrySummary | null>(null);
  const [activeMealEntry, setActiveMealEntry] =
    useState<ActiveMealEntry | null>(null);
  const [isDeletingMeal, setIsDeletingMeal] = useState(false);
  const [dailyIntakeGoalKcal, setDailyIntakeGoalKcal] = useState<number | null>(
    null,
  );
  const [weightSparklineKey, setWeightSparklineKey] = useState(0);

  useEffect(() => {
    let cancelled = false;

    void fetchUserProfile()
      .then((response) => {
        if (!cancelled) {
          setDailyIntakeGoalKcal(response.calorieGoal.dailyIntakeGoalKcal);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setDailyIntakeGoalKcal(null);
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    let cancelled = false;

    async function loadDailyRecord() {
      try {
        setIsLoading(true);
        setError(null);
        const data = await fetchDailyRecord(selectedDate);
        if (cancelled) return;

        setDateLabel(data.date);
        setRecordedOn(data.recordedOn);
        setWeight(data.weight.current);
        setReferenceWeight(data.weight.referenceWeight ?? null);
        setWeightDiff(data.weight.diffFromPreviousDay);
        setMeals(mapMealSectionsToState(data.meals));
        setSteps({
          count: data.steps?.count ?? 0,
          burnedCalories: data.steps?.burnedCalories ?? 0,
        });
        setExercises(data.exercises?.entries ?? []);
        setExerciseNotice(null);
      } catch (loadError) {
        if (!cancelled) {
          setError(
            loadError instanceof Error
              ? loadError.message
              : "読み込みに失敗しました",
          );
        }
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    }

    void loadDailyRecord();

    return () => {
      cancelled = true;
    };
  }, [selectedDate]);

  const totalMealKcal = useMemo(
    () =>
      Object.values(meals).reduce(
        (sum, meal) =>
          sum +
          meal.items.reduce(
            (mealSum, item) => mealSum + parseKcal(item.kcal),
            0,
          ),
        0,
      ),
    [meals],
  );

  const openMealSheet = (key: MealKey) => {
    setActiveMealKey(key);
    setMealSheetOpen(true);
  };

  const handleMealSave = async (item: MealItemInput) => {
    if (!activeMealKey) return;

    const calories = parseKcal(item.kcal);
    if (calories <= 0) {
      setError("カロリーは1以上で入力してください");
      return;
    }

    try {
      setIsSaving(true);
      setError(null);

      const date = recordedOn ?? selectedDate;
      const data = await saveMeal({
        mealType: activeMealKey,
        foodName: item.label,
        calories,
        date,
        caloriesEdited: item.caloriesEdited ?? false,
        calorieSource: item.calorieSource ?? null,
        sourceUrl: item.sourceUrl ?? null,
        confidence: item.confidence ?? null,
        foodId: item.foodId ?? null,
        rawInput: item.rawInput ?? null,
        amount: item.amount ?? null,
        unit: item.unit ?? null,
        servingLabel: item.servingLabel ?? null,
        servingWeightG: item.servingWeightG ?? null,
        proteinG: item.proteinG ?? null,
        fatG: item.fatG ?? null,
        carbsG: item.carbsG ?? null,
        fiberG: item.fiberG ?? null,
        sodiumMg: item.sodiumMg ?? null,
        registrationMetrics:
          item.registrationMetrics ??
          buildRegistrationMetricsFromSteps({
            rawInput: item.rawInput ?? item.label,
            selectedSource: String(item.calorieSource ?? "user_registered"),
            steps: [],
          }),
      });
      setMeals(mapMealSectionsToState(data.meals));
      setMealSheetOpen(false);
      setActiveMealKey(null);
    } catch (saveError) {
      setError(
        saveError instanceof Error
          ? saveError.message
          : "食事の保存に失敗しました",
      );
    } finally {
      setIsSaving(false);
    }
  };

  const handleMealDelete = async () => {
    if (!activeMealEntry?.item.id) return;

    try {
      setIsDeletingMeal(true);
      setError(null);
      const data = await deleteMeal(activeMealEntry.item.id);
      setMeals(mapMealSectionsToState(data.meals));
      setActiveMealEntry(null);
    } catch (deleteError) {
      setError(
        deleteError instanceof Error
          ? deleteError.message
          : "食事の削除に失敗しました",
      );
    } finally {
      setIsDeletingMeal(false);
    }
  };

  const mealTotals = useMemo(
    () =>
      (Object.entries(meals) as [MealKey, MealSectionData][]).reduce(
        (acc, [key, meal]) => {
          acc[key] = meal.items.reduce(
            (sum, item) => sum + parseKcal(item.kcal),
            0,
          );
          return acc;
        },
        {} as Record<MealKey, number>,
      ),
    [meals],
  );

  const handleWeightSave = async (value: number) => {
    try {
      setIsSaving(true);
      setError(null);
      const data = await saveWeight(value, recordedOn);
      setWeight(data.weight.current);
      setReferenceWeight(data.weight.referenceWeight ?? data.weight.current);
      setWeightDiff(data.weight.diffFromPreviousDay);
      setWeightSparklineKey((prev) => prev + 1);
      setWeightSheetOpen(false);
    } catch (saveError) {
      setError(
        saveError instanceof Error ? saveError.message : "保存に失敗しました",
      );
    } finally {
      setIsSaving(false);
    }
  };

  const handleMoveDate = (offset: number) => {
    const baseDate = selectedDate ?? recordedOn;
    if (!baseDate) return;
    const nextDate = shiftDate(baseDate, offset);
    if (nextDate > formatCurrentDate()) return;
    setSelectedDate(nextDate);
  };
  const canMoveToNextDate =
    selectedDate != null && selectedDate < formatCurrentDate();
  const exerciseTotalKcal = useMemo(
    () => exercises.reduce((sum, item) => sum + item.burnedCalories, 0),
    [exercises],
  );
  const totalActivityKcal = steps.burnedCalories + exerciseTotalKcal;
  const netIntakeKcal = totalMealKcal - totalActivityKcal;
  const hasLowConfidenceExercise = exercises.some(
    (item) => item.confidence === "low",
  );

  const handleStepsSave = async (value: number) => {
    try {
      setIsSaving(true);
      setError(null);
      const data = await saveSteps(value, recordedOn ?? selectedDate);
      setSteps(data.steps);
      setStepsSheetOpen(false);
    } catch (saveError) {
      setError(
        saveError instanceof Error
          ? saveError.message
          : "歩数の保存に失敗しました",
      );
    } finally {
      setIsSaving(false);
    }
  };

  const handleExerciseSave = async (input: ExerciseInput) => {
    try {
      setIsSaving(true);
      setError(null);
      const data = await saveExercise(
        input.name,
        input.amount,
        input.unit,
        recordedOn ?? selectedDate,
        input.burnedCalories,
      );
      setExercises(data.exercises.entries);
      if (data.meta?.usedDefaultWeight && data.meta.weightHint) {
        setExerciseNotice(data.meta.weightHint);
      } else {
        setExerciseNotice(null);
      }
      setExerciseSheetOpen(false);
    } catch (saveError) {
      setError(
        saveError instanceof Error
          ? saveError.message
          : "運動の保存に失敗しました",
      );
    } finally {
      setIsSaving(false);
    }
  };

  const netIntakeBarMax =
    dailyIntakeGoalKcal !== null
      ? dailyIntakeGoalKcal + NET_INTAKE_BUFFER_KCAL
      : Math.max(netIntakeKcal, 1);
  const netIntakeFillPercent = Math.min(
    (Math.max(netIntakeKcal, 0) / netIntakeBarMax) * 100,
    100,
  );
  const netIntakeGoalMarkerPercent =
    dailyIntakeGoalKcal !== null
      ? (dailyIntakeGoalKcal / netIntakeBarMax) * 100
      : null;
  const isNetIntakeOverBuffer =
    dailyIntakeGoalKcal !== null &&
    netIntakeKcal > dailyIntakeGoalKcal + NET_INTAKE_BUFFER_KCAL;
  const netIntakeBarColor = isNetIntakeOverBuffer
    ? NET_INTAKE_OVER_COLOR
    : ORANGE;
  const netIntakeValueColor = isNetIntakeOverBuffer
    ? NET_INTAKE_OVER_COLOR
    : ORANGE;

  const secStyle = {
    background: "#fff",
    margin: "10px 16px 0",
    borderRadius: 16,
    padding: "14px 16px",
  };
  const secHead = {
    display: "flex",
    alignItems: "center",
    justifyContent: "space-between",
    marginBottom: 10,
  };
  const secTitle = {
    display: "flex",
    alignItems: "center",
    gap: 8,
    fontSize: 15,
    fontWeight: 600,
    color: "#222",
  };
  const plusBtnStyle = {
    border: "none",
    background: "transparent",
    color: ORANGE,
    fontSize: 26,
    lineHeight: 1,
    cursor: "pointer",
    padding: 0,
  } as const;

  return (
    <div
      style={{
        position: "relative",
        flex: 1,
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
        minHeight: 0,
      }}
    >
      <TopNav title="記録する" />
      <div
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          padding: "10px 20px 12px",
          borderBottom: "1px solid #F0F0F0",
          background: "#fff",
        }}
      >
        <button
          type="button"
          aria-label="前日を表示"
          onClick={() => handleMoveDate(-1)}
          disabled={isLoading || isSaving || !selectedDate}
          style={{
            border: "none",
            background: "transparent",
            padding: 0,
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            cursor:
              isLoading || isSaving || !selectedDate
                ? "not-allowed"
                : "pointer",
            opacity: isLoading || isSaving || !selectedDate ? 0.4 : 1,
          }}
        >
          <ChevronLeft size={22} color="#C0C0C0" />
        </button>
        <span style={{ fontSize: 15, fontWeight: 600, color: "#111" }}>
          {dateLabel}
        </span>
        {canMoveToNextDate ? (
          <button
            type="button"
            aria-label="翌日を表示"
            onClick={() => handleMoveDate(1)}
            disabled={isLoading || isSaving || !selectedDate}
            style={{
              border: "none",
              background: "transparent",
              padding: 0,
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              cursor:
                isLoading || isSaving || !selectedDate
                  ? "not-allowed"
                  : "pointer",
              opacity: isLoading || isSaving || !selectedDate ? 0.4 : 1,
            }}
          >
            <ChevronRight size={22} color="#C0C0C0" />
          </button>
        ) : (
          <div style={{ width: 22 }} aria-hidden="true" />
        )}
      </div>
      <div
        style={{
          flex: 1,
          overflowY: "auto",
          background: "#F7F7F7",
        }}
      >
        {error && (
          <div
            style={{
              margin: "12px 16px 0",
              padding: "10px 12px",
              borderRadius: 10,
              background: "#FFF1F0",
              color: "#C0392B",
              fontSize: 13,
            }}
          >
            {error}
          </div>
        )}
        <div style={{ ...secStyle, marginTop: 12 }}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#FDE8C8" color={ORANGE}>
                <PersonStanding size={16} />
              </SecIcon>
              体重
            </div>
            <button
              type="button"
              aria-label="体重を記録"
              onClick={() => setWeightSheetOpen(true)}
              disabled={isLoading || isSaving}
              style={{
                ...plusBtnStyle,
                opacity: isLoading || isSaving ? 0.4 : 1,
                cursor: isLoading || isSaving ? "not-allowed" : "pointer",
              }}
            >
              +
            </button>
          </div>
          <div
            style={{
              display: "flex",
              alignItems: "center",
              flexWrap: "nowrap",
              width: "100%",
            }}
          >
            <div style={{ flex: "0 1 auto", minWidth: 0 }}>
              <div>
                <span style={{ fontSize: 36, fontWeight: 700, color: "#111" }}>
                  {weight === null ? "--.-" : weight.toFixed(1)}
                </span>
                <span style={{ fontSize: 18, color: "#888" }}> kg</span>
              </div>
              <div style={{ fontSize: 13, color: ORANGE, marginTop: 5 }}>
                {formatWeightDiff(weightDiff)}
              </div>
            </div>
            <div
              style={{
                flex: "0 0 auto",
                alignSelf: "center",
                marginLeft: "auto",
                paddingRight: 24,
              }}
            >
              <WeightSparkline
                selectedDate={selectedDate}
                refreshKey={weightSparklineKey}
              />
            </div>
          </div>
        </div>

        <div style={secStyle}>
          <div
            style={{
              display: "flex",
              alignItems: "baseline",
              justifyContent: "space-between",
              gap: 12,
              marginBottom: 14,
            }}
          >
            <div style={{ fontSize: 15, fontWeight: 600, color: "#222" }}>
              純摂取カロリー
            </div>
            <div style={{ fontSize: 15, color: "#666", whiteSpace: "nowrap" }}>
              <span
                style={{
                  fontSize: 20,
                  fontWeight: 800,
                  color: netIntakeValueColor,
                }}
              >
                {formatKcal(netIntakeKcal)}
              </span>
              {dailyIntakeGoalKcal !== null && (
                <span style={{ fontWeight: 600 }}>
                  {" "}
                  / {formatKcal(dailyIntakeGoalKcal)} kcal
                </span>
              )}
              {dailyIntakeGoalKcal === null && (
                <span style={{ fontWeight: 600 }}> kcal</span>
              )}
            </div>
          </div>

          <div
            style={{
              position: "relative",
              height: 10,
              borderRadius: 999,
              background: "#F0F0F0",
              overflow: "visible",
              marginBottom: 18,
            }}
          >
            <div
              style={{
                height: "100%",
                width: `${Math.min(netIntakeFillPercent, 100)}%`,
                borderRadius: 999,
                background: netIntakeBarColor,
                transition: "width 200ms ease, background 200ms ease",
              }}
            />
            {netIntakeGoalMarkerPercent !== null && (
              <div
                style={{
                  position: "absolute",
                  top: -3,
                  left: `${Math.min(netIntakeGoalMarkerPercent, 100)}%`,
                  transform: "translateX(-50%)",
                  width: 2,
                  height: 16,
                  borderRadius: 1,
                  background: "#9CA3AF",
                }}
              />
            )}
          </div>

          <div
            style={{
              display: "flex",
              alignItems: "center",
              justifyContent: "space-between",
              gap: 8,
              paddingTop: 8,
              borderTop: "1px solid #F0F0F0",
            }}
          >
            <div style={{ flex: 1, textAlign: "center" }}>
              <div style={{ fontSize: 12, color: "#888", marginBottom: 0 }}>
                食事
              </div>
              <div style={{ fontSize: 16, fontWeight: 700, color: "#A67C52" }}>
                {formatKcal(totalMealKcal)}
                <span style={{ fontSize: 12, fontWeight: 500, color: "#888" }}>
                  {" "}
                  kcal
                </span>
              </div>
            </div>
            <span style={{ fontSize: 18, color: "#CCC", paddingTop: 14 }}>
              −
            </span>
            <div style={{ flex: 1, textAlign: "center" }}>
              <div style={{ fontSize: 12, color: "#888", marginBottom: 0 }}>
                運動
              </div>
              <div style={{ fontSize: 16, fontWeight: 700, color: "#2EAA72" }}>
                {formatKcal(totalActivityKcal)}
                <span style={{ fontSize: 12, fontWeight: 500, color: "#888" }}>
                  {" "}
                  kcal
                </span>
              </div>
            </div>
            <span style={{ fontSize: 18, color: "#CCC", paddingTop: 14 }}>
              =
            </span>
            <div style={{ flex: 1, textAlign: "center" }}>
              <div style={{ fontSize: 12, color: "#888", marginBottom: 0 }}>
                純摂取
              </div>
              <div
                style={{
                  fontSize: 16,
                  fontWeight: 700,
                  color: netIntakeValueColor,
                }}
              >
                {formatKcal(netIntakeKcal)}
                <span style={{ fontSize: 12, fontWeight: 500, color: "#888" }}>
                  {" "}
                  kcal
                </span>
              </div>
            </div>
          </div>
        </div>

        <div style={secStyle}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#FDE8C8" color={ORANGE}>
                <Utensils size={16} />
              </SecIcon>
              食事
            </div>
            <span style={{ fontSize: 13, fontWeight: 700, color: ORANGE }}>
              {formatKcal(totalMealKcal)} kcal
            </span>
          </div>
          {(Object.entries(meals) as [MealKey, MealSectionData][]).map(
            ([key, meal]) => (
              <MealSection
                key={key}
                icon={meal.icon}
                title={meal.title}
                totalKcal={formatKcal(
                  meal.items.reduce(
                    (sum, item) => sum + parseKcal(item.kcal),
                    0,
                  ),
                )}
                items={meal.items}
                isLast={meal.isLast}
                onAdd={() => openMealSheet(key)}
                onItemClick={(item) => {
                  if (item.id == null) return;
                  setActiveMealEntry({
                    mealKey: key,
                    item: {
                      id: item.id,
                      label: item.label,
                      kcal: item.kcal,
                      caloriesEdited: item.caloriesEdited,
                      calorieSource: item.calorieSource ?? null,
                      sourceUrl: item.sourceUrl ?? null,
                      confidence: item.confidence ?? null,
                    },
                  });
                }}
              />
            ),
          )}
        </div>

        <div style={secStyle}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#D6F5E8" color="#2EAA72">
                <HeartPlus size={16} />
              </SecIcon>
              活動
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: 14 }}>
              <span style={{ fontSize: 14, color: "#2EAA72" }}>
                <span style={{ fontWeight: 700 }}>{totalActivityKcal}</span>{" "}
                kcal
              </span>
              <button
                type="button"
                disabled={isLoading || isSaving}
                onClick={() => setExerciseSheetOpen(true)}
                style={{
                  ...plusBtnStyle,
                  opacity: isLoading || isSaving ? 0.4 : 1,
                  cursor: isLoading || isSaving ? "not-allowed" : "pointer",
                }}
              >
                +
              </button>
            </div>
          </div>
          <ActivitySubSection
            icon={<Footprints size={14} />}
            title="歩数"
            totalKcal={String(steps.burnedCalories)}
            onAdd={() => setStepsSheetOpen(true)}
            disabled={isLoading || isSaving}
          >
            <div>
              <span style={{ fontSize: 28, fontWeight: 700, color: "#111" }}>
                {steps.count.toLocaleString()}
              </span>
              <span style={{ fontSize: 14, color: "#888" }}> 歩</span>
            </div>
          </ActivitySubSection>
          <ActivitySubSection
            icon={<Dumbbell size={14} />}
            title="運動"
            totalKcal={String(exerciseTotalKcal)}
            isLast
            onAdd={() => setExerciseSheetOpen(true)}
            disabled={isLoading || isSaving}
          >
            <div
              style={{
                display: "flex",
                flexWrap: "wrap",
                gap: 6,
                alignItems: "center",
              }}
            >
              {exercises.map((item) => (
                <ExerciseChip
                  key={item.id}
                  text={`${item.name}　${item.amount}${item.unit === "min" ? "分" : "回"}　${item.burnedCalories}kcal`}
                  isAiEstimate={item.source === "llm_estimate"}
                  onClick={
                    item.source === "llm_estimate" && item.note
                      ? () => setActiveExerciseNote(item)
                      : undefined
                  }
                />
              ))}
            </div>
            {hasLowConfidenceExercise && (
              <div style={{ marginTop: 8, fontSize: 12, color: "#9CA3AF" }}>
                実際と異なる場合があります
              </div>
            )}
            {exerciseNotice && (
              <div style={{ marginTop: 6, fontSize: 12, color: "#9CA3AF" }}>
                {exerciseNotice}
              </div>
            )}
          </ActivitySubSection>
        </div>

        <div style={{ ...secStyle, marginBottom: 10 }}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#E0EFFE" color="#3B82F6">
                <StickyNote size={16} />
              </SecIcon>
              メモ
            </div>
            <button type="button" style={plusBtnStyle}>
              +
            </button>
          </div>
        </div>
      </div>

      <WeightRegisterSheet
        open={weightSheetOpen}
        initialValue={weight ?? referenceWeight ?? 60}
        dateLabel={dateLabel}
        isSaving={isSaving}
        onClose={() => setWeightSheetOpen(false)}
        onSave={handleWeightSave}
      />

      <StepsRegisterSheet
        open={stepsSheetOpen}
        initialSteps={steps.count}
        isSaving={isSaving}
        onClose={() => setStepsSheetOpen(false)}
        onSave={handleStepsSave}
      />

      <ExerciseRegisterSheet
        open={exerciseSheetOpen}
        isSaving={isSaving}
        recordDate={recordedOn ?? selectedDate}
        onClose={() => setExerciseSheetOpen(false)}
        onSave={handleExerciseSave}
      />

      <BottomSheet
        open={activeExerciseNote !== null}
        title={
          activeExerciseNote
            ? `${activeExerciseNote.name}について`
            : "運動について"
        }
        onClose={() => setActiveExerciseNote(null)}
      >
        {activeExerciseNote && (
          <div
            style={{
              border: "1px solid #EEE",
              borderRadius: 12,
              padding: "14px 12px",
              background: "#fff",
              display: "flex",
              flexDirection: "column",
              gap: 10,
            }}
          >
            <div style={{ fontSize: 14, color: "#374151", lineHeight: 1.6 }}>
              {activeExerciseNote.note ?? "AI推定で計算しました"}
            </div>
            <div style={{ borderTop: "1px solid #F0F0F0" }} />
            <div style={{ fontSize: 14, fontWeight: 700, color: "#111" }}>
              消費カロリー {activeExerciseNote.burnedCalories}kcal
            </div>
          </div>
        )}
      </BottomSheet>

      <MealEntryDetailSheet
        open={activeMealEntry !== null}
        mealTitle={
          activeMealEntry ? meals[activeMealEntry.mealKey].title : ""
        }
        item={activeMealEntry?.item ?? null}
        isDeleting={isDeletingMeal}
        onClose={() => setActiveMealEntry(null)}
        onDelete={() => void handleMealDelete()}
      />

      {/* 変更: 食事追加UIを新しい検索フロー対応モーダルへ差し替え。 */}
      <AddFoodModal
        open={mealSheetOpen}
        mealType={activeMealKey ?? "breakfast"}
        mealTitle={activeMealKey ? meals[activeMealKey].title : ""}
        currentMealKcal={activeMealKey ? mealTotals[activeMealKey] : 0}
        currentTotalKcal={totalMealKcal}
        dailyGoalKcal={dailyIntakeGoalKcal}
        onClose={() => {
          setMealSheetOpen(false);
          setActiveMealKey(null);
        }}
        onSave={handleMealSave}
      />
    </div>
  );
}
