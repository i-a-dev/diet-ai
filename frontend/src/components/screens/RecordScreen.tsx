import { useEffect, useMemo, useState } from "react";
import {
  ChevronLeft,
  ChevronRight,
  Cookie,
  Footprints,
  Moon,
  PersonStanding,
  StickyNote,
  Sun,
  Sunset,
  UtensilsCrossed,
  Weight,
} from "lucide-react";
import {
  type ExerciseEntrySummary,
  type MealSectionSummary,
  fetchDailyRecord,
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
import { type ExerciseInput, ExerciseRegisterSheet } from "../ExerciseRegisterSheet.tsx";
import { MealSection } from "../MealSection.tsx";
import { SecIcon } from "../SecIcon.tsx";
import { StepsRegisterSheet } from "../StepsRegisterSheet.tsx";
import { TopNav } from "../TopNav.tsx";
import { WeightRegisterSheet } from "../WeightRegisterSheet.tsx";
import { ORANGE } from "../../constants.ts";

type MealKey = "breakfast" | "lunch" | "dinner" | "snack";

interface MealSectionData {
  title: string;
  icon: React.ReactNode;
  items: MealItemInput[];
  isLast?: boolean;
}

const MEAL_SUGGESTIONS: MealItemInput[] = [
  { label: "白米 150g", kcal: "234kcal" },
  { label: "味噌汁", kcal: "45kcal" },
  { label: "焼き鮭", kcal: "180kcal" },
  { label: "サラダ", kcal: "35kcal" },
  { label: "豆腐ハンバーグ", kcal: "320kcal" },
  { label: "野菜スープ", kcal: "85kcal" },
];

const BASE_MEALS: Record<MealKey, Omit<MealSectionData, "items">> = {
  breakfast: {
    title: "朝ごはん",
    icon: <Sun size={14} />,
  },
  lunch: {
    title: "昼ごはん",
    icon: <Sunset size={14} />,
  },
  dinner: {
    title: "夜ごはん",
    icon: <Moon size={14} />,
  },
  snack: {
    title: "間食・おやつ",
    icon: <Cookie size={14} />,
    isLast: true,
  },
};

function parseKcal(kcal: string) {
  return Number(kcal.replace(/[^\d]/g, "")) || 0;
}

function formatKcal(value: number) {
  return value.toLocaleString();
}

function formatWeightDiff(diff: number | null) {
  if (diff === null) return "前日比 --";
  if (diff === 0) return "前日比 ±0.0kg";
  return diff > 0 ? `前日比 +${diff.toFixed(1)}kg` : `前日比 ${diff.toFixed(1)}kg`;
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

function mapMealSectionsToState(sections: MealSectionSummary[] = []): Record<MealKey, MealSectionData> {
  const nextMeals = createInitialMeals();

  sections.forEach((section) => {
    const key = section.id;
    if (!nextMeals[key]) return;

    nextMeals[key] = {
      ...nextMeals[key],
      items: section.items.map((item) => ({
        label: item.label,
        kcal: `${item.calories}kcal`,
      })),
    };
  });

  return nextMeals;
}

export function RecordScreen() {
  const [weight, setWeight] = useState<number | null>(null);
  const [referenceWeight, setReferenceWeight] = useState<number | null>(null);
  const [referenceRecordedOn, setReferenceRecordedOn] = useState<string | null>(null);
  const [weightDiff, setWeightDiff] = useState<number | null>(null);
  const [dateLabel, setDateLabel] = useState("読み込み中...");
  const [selectedDate, setSelectedDate] = useState<string>(formatCurrentDate);
  const [recordedOn, setRecordedOn] = useState<string>();
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [meals, setMeals] = useState<Record<MealKey, MealSectionData>>(createInitialMeals);
  const [weightSheetOpen, setWeightSheetOpen] = useState(false);
  const [mealSheetOpen, setMealSheetOpen] = useState(false);
  const [stepsSheetOpen, setStepsSheetOpen] = useState(false);
  const [exerciseSheetOpen, setExerciseSheetOpen] = useState(false);
  const [activeMealKey, setActiveMealKey] = useState<MealKey | null>(null);
  const [steps, setSteps] = useState({ count: 0, burnedCalories: 0 });
  const [exercises, setExercises] = useState<ExerciseEntrySummary[]>([]);
  const [exerciseNotice, setExerciseNotice] = useState<string | null>(null);
  const [activeExerciseNote, setActiveExerciseNote] = useState<ExerciseEntrySummary | null>(null);

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
        setReferenceRecordedOn(data.weight.referenceRecordedOn ?? null);
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
          setError(loadError instanceof Error ? loadError.message : "読み込みに失敗しました");
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
        (sum, meal) => sum + meal.items.reduce((mealSum, item) => mealSum + parseKcal(item.kcal), 0),
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
      const data = await saveMeal(activeMealKey, item.label, calories, recordedOn ?? selectedDate);
      setMeals(mapMealSectionsToState(data.meals));
      setMealSheetOpen(false);
      setActiveMealKey(null);
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "食事の保存に失敗しました");
    } finally {
      setIsSaving(false);
    }
  };

  const mealTotals = useMemo(
    () =>
      (Object.entries(meals) as [MealKey, MealSectionData][]).reduce(
        (acc, [key, meal]) => {
          acc[key] = meal.items.reduce((sum, item) => sum + parseKcal(item.kcal), 0);
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
      setReferenceRecordedOn(data.weight.referenceRecordedOn ?? data.weight.recordedOn);
      setWeightDiff(data.weight.diffFromPreviousDay);
      setWeightSheetOpen(false);
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "保存に失敗しました");
    } finally {
      setIsSaving(false);
    }
  };

  const handleMoveDate = (offset: number) => {
    const baseDate = selectedDate ?? recordedOn;
    if (!baseDate) return;
    setSelectedDate(shiftDate(baseDate, offset));
  };
  const exerciseTotalKcal = useMemo(
    () => exercises.reduce((sum, item) => sum + item.burnedCalories, 0),
    [exercises],
  );
  const totalActivityKcal = steps.burnedCalories + exerciseTotalKcal;
  const hasLowConfidenceExercise = exercises.some((item) => item.confidence === "low");

  const handleStepsSave = async (value: number) => {
    try {
      setIsSaving(true);
      setError(null);
      const data = await saveSteps(value, recordedOn ?? selectedDate);
      setSteps(data.steps);
      setStepsSheetOpen(false);
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "歩数の保存に失敗しました");
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
      );
      setExercises(data.exercises.entries);
      if (data.meta?.usedDefaultWeight && data.meta.weightHint) {
        setExerciseNotice(data.meta.weightHint);
      } else {
        setExerciseNotice(null);
      }
      setExerciseSheetOpen(false);
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "運動の保存に失敗しました");
    } finally {
      setIsSaving(false);
    }
  };

  const hasTodayWeight = weight !== null;
  const referenceDateLabel = referenceRecordedOn
    ? referenceRecordedOn === recordedOn
      ? undefined
      : referenceRecordedOn.replace(/^\d{4}-/, "").replace("-", "/")
    : undefined;

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
            cursor: isLoading || isSaving || !selectedDate ? "not-allowed" : "pointer",
            opacity: isLoading || isSaving || !selectedDate ? 0.4 : 1,
          }}
        >
          <ChevronLeft size={22} color="#C0C0C0" />
        </button>
        <span style={{ fontSize: 15, fontWeight: 600, color: "#111" }}>{dateLabel}</span>
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
            cursor: isLoading || isSaving || !selectedDate ? "not-allowed" : "pointer",
            opacity: isLoading || isSaving || !selectedDate ? 0.4 : 1,
          }}
        >
          <ChevronRight size={22} color="#C0C0C0" />
        </button>
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
                <Weight size={16} />
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
              alignItems: "flex-end",
              justifyContent: "space-between",
            }}
          >
            <div>
              <div>
                <span style={{ fontSize: 36, fontWeight: 700, color: "#111" }}>
                  {weight === null ? "--.-" : weight.toFixed(1)}
                </span>
                <span style={{ fontSize: 18, color: "#888" }}> kg</span>
              </div>
              <div style={{ fontSize: 13, color: ORANGE, marginTop: 5 }}>{formatWeightDiff(weightDiff)}</div>
            </div>
            <svg width="110" height="44" viewBox="0 0 110 44">
              <polyline
                points="0,34 18,30 36,38 54,26 72,20 90,14 110,8"
                fill="none"
                stroke={ORANGE}
                strokeWidth="2.2"
                strokeLinejoin="round"
                strokeLinecap="round"
              />
              {[
                { cx: 0, cy: 34 },
                { cx: 18, cy: 30 },
                { cx: 36, cy: 38 },
                { cx: 54, cy: 26 },
                { cx: 72, cy: 20 },
                { cx: 90, cy: 14 },
              ].map((point) => (
                <circle
                  key={`${point.cx}-${point.cy}`}
                  cx={point.cx}
                  cy={point.cy}
                  r="3"
                  fill="#fff"
                  stroke={ORANGE}
                  strokeWidth="1.8"
                />
              ))}
              <circle cx="110" cy="8" r="4" fill={ORANGE} />
            </svg>
          </div>
        </div>

        <div style={secStyle}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#FDE8C8" color={ORANGE}>
                <UtensilsCrossed size={16} />
              </SecIcon>
              食事
            </div>
            <span style={{ fontSize: 13, fontWeight: 700, color: ORANGE }}>
              {formatKcal(totalMealKcal)} / 1,800kcal
            </span>
          </div>
          {(Object.entries(meals) as [MealKey, MealSectionData][]).map(([key, meal]) => (
            <MealSection
              key={key}
              icon={meal.icon}
              title={meal.title}
              totalKcal={formatKcal(meal.items.reduce((sum, item) => sum + parseKcal(item.kcal), 0))}
              items={meal.items}
              isLast={meal.isLast}
              onAdd={() => openMealSheet(key)}
            />
          ))}
        </div>

        <div style={secStyle}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#D6F5E8" color="#2EAA72">
                <Footprints size={16} />
              </SecIcon>
              運動・歩数
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: 14 }}>
              <span style={{ fontSize: 14, color: "#2EAA72" }}>
                <span style={{ fontWeight: 700 }}>{totalActivityKcal}</span> kcal
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
            icon={<Sun size={14} />}
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
            icon={<PersonStanding size={14} />}
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
                  text={`${item.name}　${item.amount}${item.unit === "min" ? "分" : "回"}　${item.burnedCalories}kcal${
                    item.source === "llm_estimate" ? "　AI推定 ⓘ" : ""
                  }`}
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
              <div style={{ marginTop: 6, fontSize: 12, color: "#9CA3AF" }}>{exerciseNotice}</div>
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
          <div style={{ fontSize: 14, color: "#AAA" }}>今日はケーキを食べちゃった</div>
        </div>
      </div>

      <WeightRegisterSheet
        open={weightSheetOpen}
        initialValue={weight ?? referenceWeight ?? 62.4}
        dateLabel={dateLabel}
        referenceDateLabel={!hasTodayWeight ? referenceDateLabel : undefined}
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
        title={activeExerciseNote ? `${activeExerciseNote.name}について` : "運動について"}
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

      {/* 変更: 食事追加UIを新しい検索フロー対応モーダルへ差し替え。 */}
      <AddFoodModal
        open={mealSheetOpen}
        mealType={activeMealKey ?? "breakfast"}
        mealTitle={activeMealKey ? meals[activeMealKey].title : ""}
        suggestions={MEAL_SUGGESTIONS}
        currentMealKcal={activeMealKey ? mealTotals[activeMealKey] : 0}
        currentTotalKcal={totalMealKcal}
        dailyGoalKcal={1800}
        onClose={() => {
          setMealSheetOpen(false);
          setActiveMealKey(null);
        }}
        onSave={handleMealSave}
      />
    </div>
  );
}
