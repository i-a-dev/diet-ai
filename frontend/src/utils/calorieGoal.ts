import type { ActivityLevel, Gender, UserProfile } from "../api/client.ts";

const KCAL_PER_KG_FAT = 7700;
const DAYS_PER_MONTH = 30;

const ACTIVITY_MULTIPLIERS: Record<ActivityLevel, number> = {
  sedentary: 1.2,
  light: 1.375,
  moderate: 1.55,
  active: 1.725,
  very_active: 1.9,
};

export interface CalorieGoal {
  ageYears: number | null;
  bmrKcal: number | null;
  tdeeKcal: number | null;
  dailyDeficitKcal: number | null;
  dailyIntakeGoalKcal: number | null;
  isComplete: boolean;
}

function calculateAgeYears(birthDate: string | null): number | null {
  if (!birthDate) {
    return null;
  }

  const birth = new Date(`${birthDate}T00:00:00`);
  if (Number.isNaN(birth.getTime())) {
    return null;
  }

  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const monthDiff = today.getMonth() - birth.getMonth();
  if (
    monthDiff < 0 ||
    (monthDiff === 0 && today.getDate() < birth.getDate())
  ) {
    age -= 1;
  }

  return Math.max(0, age);
}

function calculateBmr(
  gender: Gender | null,
  ageYears: number | null,
  heightCm: number | null,
  weightKg: number | null,
): number | null {
  if (
    gender === null ||
    ageYears === null ||
    heightCm === null ||
    weightKg === null
  ) {
    return null;
  }

  const base = 10 * weightKg + 6.25 * heightCm - 5 * ageYears;
  const bmr =
    gender === "male"
      ? base + 5
      : gender === "female"
        ? base - 161
        : base - 78;

  return Math.round(bmr);
}

function calculateTdee(
  bmrKcal: number | null,
  activityLevel: ActivityLevel | null,
): number | null {
  if (bmrKcal === null) {
    return null;
  }

  const level = activityLevel ?? "sedentary";
  const multiplier =
    ACTIVITY_MULTIPLIERS[level] ?? ACTIVITY_MULTIPLIERS.sedentary;

  return Math.round(bmrKcal * multiplier);
}

function calculateDailyDeficit(
  targetPaceKgPerMonth: number | null,
): number | null {
  if (targetPaceKgPerMonth === null) {
    return null;
  }

  return Math.round(
    (targetPaceKgPerMonth * KCAL_PER_KG_FAT) / DAYS_PER_MONTH,
  );
}

export function isCalorieGoalInputReady(
  profile: UserProfile,
  weightKg: number | null,
): boolean {
  return (
    profile.gender !== null &&
    profile.birthDate !== null &&
    profile.heightCm !== null &&
    weightKg !== null &&
    profile.targetPaceKgPerMonth !== null
  );
}

export function calculateCalorieGoal(
  profile: UserProfile,
  weightKg: number | null,
): CalorieGoal {
  const ageYears = calculateAgeYears(profile.birthDate);
  const bmrKcal = calculateBmr(
    profile.gender,
    ageYears,
    profile.heightCm,
    weightKg,
  );
  const tdeeKcal = calculateTdee(bmrKcal, profile.activityLevel ?? "sedentary");
  const dailyDeficitKcal = calculateDailyDeficit(
    profile.targetPaceKgPerMonth,
  );
  const dailyIntakeGoalKcal =
    tdeeKcal !== null && dailyDeficitKcal !== null
      ? Math.max(1200, Math.round(tdeeKcal - dailyDeficitKcal))
      : null;

  return {
    ageYears,
    bmrKcal,
    tdeeKcal,
    dailyDeficitKcal,
    dailyIntakeGoalKcal,
    isComplete:
      bmrKcal !== null &&
      tdeeKcal !== null &&
      dailyIntakeGoalKcal !== null,
  };
}
