import { useEffect, useMemo, useState } from "react";
import {
  Calendar,
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
import { fetchDailyRecord, saveWeight } from "../../api/client.ts";
import { ActivitySubSection } from "../ActivitySubSection.tsx";
import { ExerciseChip } from "../ExerciseChip.tsx";
import type { MealItemInput } from "../AddFoodModal.tsx";
import { AddFoodModal } from "../AddFoodModal.tsx";
import { MealSection } from "../MealSection.tsx";
import { SecIcon } from "../SecIcon.tsx";
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

const INITIAL_MEALS: Record<MealKey, MealSectionData> = {
  breakfast: {
    title: "朝ごはん",
    icon: <Sun size={14} />,
    items: [
      { label: "白米 150g", kcal: "234kcal" },
      { label: "味噌汁", kcal: "45kcal" },
      { label: "焼き鮭", kcal: "180kcal" },
    ],
  },
  lunch: {
    title: "昼ごはん",
    icon: <Sunset size={14} />,
    items: [
      { label: "鶏のから揚げ定食", kcal: "680kcal" },
      { label: "ご飯 少なめ", kcal: "180kcal" },
      { label: "サラダ", kcal: "35kcal" },
    ],
  },
  dinner: {
    title: "夜ごはん",
    icon: <Moon size={14} />,
    items: [
      { label: "豆腐ハンバーグ", kcal: "320kcal" },
      { label: "野菜スープ", kcal: "85kcal" },
    ],
  },
  snack: {
    title: "間食・おやつ",
    icon: <Cookie size={14} />,
    items: [],
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

export function RecordScreen() {
  const [weight, setWeight] = useState<number | null>(null);
  const [weightDiff, setWeightDiff] = useState<number | null>(null);
  const [dateLabel, setDateLabel] = useState("読み込み中...");
  const [recordedOn, setRecordedOn] = useState<string>();
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [meals, setMeals] = useState(INITIAL_MEALS);
  const [weightSheetOpen, setWeightSheetOpen] = useState(false);
  const [mealSheetOpen, setMealSheetOpen] = useState(false);
  const [activeMealKey, setActiveMealKey] = useState<MealKey | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function loadDailyRecord() {
      try {
        setIsLoading(true);
        setError(null);
        const data = await fetchDailyRecord();
        if (cancelled) return;

        setDateLabel(data.date);
        setRecordedOn(data.recordedOn);
        setWeight(data.weight.current);
        setWeightDiff(data.weight.diffFromPreviousDay);
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
  }, []);

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

  const handleMealSave = (item: MealItemInput) => {
    if (!activeMealKey) return;
    setMeals((prev) => ({
      ...prev,
      [activeMealKey]: {
        ...prev[activeMealKey],
        items: [...prev[activeMealKey].items, item],
      },
    }));
    setMealSheetOpen(false);
    setActiveMealKey(null);
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
      setWeightDiff(data.weight.diffFromPreviousDay);
      setWeightSheetOpen(false);
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "保存に失敗しました");
    } finally {
      setIsSaving(false);
    }
  };

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
      <TopNav title="記録する" rightIcon={<Calendar size={22} color="#C0C0C0" />} />
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
        <ChevronLeft size={22} color="#C0C0C0" />
        <span style={{ fontSize: 15, fontWeight: 600, color: "#111" }}>{dateLabel}</span>
        <ChevronRight size={22} color="#C0C0C0" />
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
                  {weight === null ? "--" : weight.toFixed(1)}
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
                <span style={{ fontWeight: 700 }}>411</span> kcal
              </span>
              <button type="button" style={plusBtnStyle}>
                +
              </button>
            </div>
          </div>
          <ActivitySubSection icon={<Sun size={14} />} title="歩数" totalKcal="231">
            <div>
              <span style={{ fontSize: 28, fontWeight: 700, color: "#111" }}>5,842</span>
              <span style={{ fontSize: 14, color: "#888" }}> 歩</span>
            </div>
          </ActivitySubSection>
          <ActivitySubSection icon={<PersonStanding size={14} />} title="運動" totalKcal="180" isLast>
            <div
              style={{
                display: "flex",
                flexWrap: "wrap",
                gap: 6,
                alignItems: "center",
              }}
            >
              <ExerciseChip text="スクワット　30回　60kcal" />
              <ExerciseChip text="腹筋　20回 × 2セット　30kcal" />
              <ExerciseChip text="ウォーキング　30分　90kcal" />
            </div>
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
        initialValue={weight ?? 62.4}
        dateLabel={dateLabel}
        isSaving={isSaving}
        onClose={() => setWeightSheetOpen(false)}
        onSave={handleWeightSave}
      />

      {/* 変更: 食事追加UIを新しい検索フロー対応モーダルへ差し替え。 */}
      <AddFoodModal
        open={mealSheetOpen}
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
