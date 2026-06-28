import {
  useEffect,
  useRef,
  useState,
  type CSSProperties,
  type ReactNode,
} from "react";
import {
  Calendar,
  ChevronLeft,
  Flame,
  Heart,
  Scale,
  Target,
  User,
  X,
} from "lucide-react";
import {
  fetchUserProfile,
  updateUserProfile,
  type DietGoal,
  type Gender,
  type UserProfile,
} from "../api/client.ts";
import { ORANGE } from "../constants.ts";
import {
  calculateCalorieGoal,
  isCalorieGoalInputReady,
} from "../utils/calorieGoal.ts";
import { StepperButton } from "./StepperButton.tsx";

const GREEN = "#48B868";
const GREEN_BG = "#E8F7ED";
const ORANGE_BG = "#FFF3E6";
const BLUE = "#3B82F6";
const BLUE_BG = "#EFF6FF";
const PURPLE = "#8B5CF6";
const PURPLE_BG = "#F3E8FF";

interface ProfileSettingsSheetProps {
  open: boolean;
  onClose: () => void;
  onSaved?: () => void;
  mode?: "settings" | "onboarding";
}

const GENDER_OPTIONS: { value: Gender; label: string }[] = [
  { value: "male", label: "男性" },
  { value: "female", label: "女性" },
  { value: "other", label: "その他" },
];

const DIET_GOAL_OPTIONS: { value: DietGoal; label: string }[] = [
  { value: "weight_loss", label: "減量" },
  { value: "maintenance", label: "体型維持" },
  { value: "muscle_gain", label: "筋肉増量" },
  { value: "health", label: "健康維持" },
];

const PROFILE_TEXT_MAX_LENGTH = 100;

const DEFAULT_PROFILE: UserProfile = {
  gender: null,
  birthDate: null,
  heightCm: null,
  currentWeightKg: null,
  targetWeightKg: null,
  activityLevel: null,
  targetPaceKgPerMonth: null,
  dietGoal: null,
  desiredDietMethod: null,
  allergiesDislikes: null,
  pastDietExperience: null,
  coachNotes: null,
  isComplete: false,
  updatedAt: null,
};

function roundOneDecimal(value: number) {
  return Math.round(value * 10) / 10;
}

const DEFAULT_NUMERIC = {
  heightCm: 160,
  currentWeightKg: 60,
  targetWeightKg: 57,
  targetPaceKgPerMonth: 2,
} as const;

function applyNumericDefaults(profile: UserProfile): UserProfile {
  return {
    ...profile,
    heightCm: profile.heightCm ?? DEFAULT_NUMERIC.heightCm,
    currentWeightKg: profile.currentWeightKg ?? DEFAULT_NUMERIC.currentWeightKg,
    targetWeightKg: profile.targetWeightKg ?? DEFAULT_NUMERIC.targetWeightKg,
    targetPaceKgPerMonth:
      profile.targetPaceKgPerMonth ?? DEFAULT_NUMERIC.targetPaceKgPerMonth,
  };
}

function trimProfileText(value: string): string | null {
  const trimmed = value.slice(0, PROFILE_TEXT_MAX_LENGTH);
  return trimmed || null;
}

function CharCounter({ value }: { value: string }) {
  return (
    <div
      style={{
        marginTop: 6,
        fontSize: 11,
        color: "#999",
        textAlign: "right",
      }}
    >
      {value.length}/{PROFILE_TEXT_MAX_LENGTH}
    </div>
  );
}

function isRequiredComplete(profile: UserProfile) {
  const effective = applyNumericDefaults(profile);
  return (
    effective.gender !== null &&
    effective.birthDate !== null &&
    effective.heightCm !== null &&
    effective.currentWeightKg !== null &&
    effective.targetWeightKg !== null
  );
}

function CalorieGoalCard({ profile }: { profile: UserProfile }) {
  const effective = applyNumericDefaults(profile);
  const calorieInput: UserProfile = {
    ...profile,
    heightCm: effective.heightCm,
    currentWeightKg: effective.currentWeightKg,
    targetPaceKgPerMonth: effective.targetPaceKgPerMonth,
  };
  const ready = isCalorieGoalInputReady(calorieInput);
  const calorieGoal = calculateCalorieGoal(calorieInput);

  return (
    <div
      style={{
        marginBottom: 16,
        padding: "18px 16px",
        borderRadius: 16,
        background: ready ? ORANGE_BG : "#F8F8F8",
        border: ready ? `1.5px solid ${ORANGE}` : "1px solid #ECECEC",
      }}
    >
      <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
        <div
          style={{
            width: 36,
            height: 36,
            borderRadius: 10,
            background: ready ? "#fff" : "#EFEFEF",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            flexShrink: 0,
          }}
        >
          <Flame size={18} color={ready ? ORANGE : "#AAA"} strokeWidth={2.2} />
        </div>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 14, fontWeight: 700, color: "#111" }}>
            目標推定カロリー
          </div>
          {ready && calorieGoal.dailyIntakeGoalKcal !== null ? (
            <>
              <div
                style={{
                  marginTop: 6,
                  fontSize: 28,
                  fontWeight: 800,
                  color: ORANGE,
                  lineHeight: 1.1,
                }}
              >
                {calorieGoal.dailyIntakeGoalKcal.toLocaleString()}
                <span
                  style={{ fontSize: 14, fontWeight: 600, marginLeft: 4 }}
                >
                  kcal/日
                </span>
              </div>
              <p
                style={{
                  margin: "8px 0 0",
                  fontSize: 12,
                  color: "#888",
                  lineHeight: 1.6,
                }}
              >
                推定基礎代謝 {calorieGoal.bmrKcal?.toLocaleString()} kcal
                {" · "}
                推定消費 {calorieGoal.tdeeKcal?.toLocaleString()} kcal
              </p>
            </>
          ) : (
            <p
              style={{
                margin: "6px 0 0",
                fontSize: 12,
                color: "#999",
                lineHeight: 1.6,
              }}
            >
              性別・生年月日・身長・現在の体重・目標ペースを入力すると表示されます
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

function SettingCard({
  icon,
  iconBg,
  label,
  hint,
  value,
  unit,
  min,
  max,
  step,
  onChange,
}: {
  icon: ReactNode;
  iconBg: string;
  label: string;
  hint?: string;
  value: number;
  unit: string;
  min: number;
  max: number;
  step: number;
  onChange: (value: number) => void;
}) {
  const valueRef = useRef(value);
  valueRef.current = value;

  const adjust = (delta: number) => {
    onChange(
      roundOneDecimal(Math.max(min, Math.min(max, valueRef.current + delta))),
    );
  };

  return (
    <div style={cardStyle}>
      <div
        style={{
          display: "flex",
          alignItems: "flex-start",
          gap: 10,
          marginBottom: hint ? 8 : 20,
        }}
      >
        <div style={iconWrapStyle(iconBg)}>{icon}</div>
        <div>
          <span style={{ fontSize: 16, fontWeight: 700, color: "#111" }}>
            {label}
          </span>
          {hint && (
            <p
              style={{
                margin: "4px 0 0",
                fontSize: 12,
                color: "#999",
                lineHeight: 1.5,
              }}
            >
              {hint}
            </p>
          )}
        </div>
      </div>

      <div
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          gap: 24,
        }}
      >
        <StepperButton
          ariaLabel={`${label}を${step}減らす`}
          onStep={() => adjust(-step)}
        >
          −
        </StepperButton>
        <div style={{ display: "flex", alignItems: "baseline", gap: 6 }}>
          <span
            style={{
              fontSize: 44,
              fontWeight: 700,
              color: "#111",
              lineHeight: 1,
            }}
          >
            {value.toFixed(1)}
          </span>
          <span style={{ fontSize: 18, color: "#999", fontWeight: 500 }}>
            {unit}
          </span>
        </div>
        <StepperButton
          ariaLabel={`${label}を${step}増やす`}
          onStep={() => adjust(step)}
        >
          ＋
        </StepperButton>
      </div>
    </div>
  );
}

function SectionHeader({
  title,
  optional,
}: {
  title: string;
  optional?: boolean;
}) {
  return (
    <div
      style={{
        margin: "0 0 12px",
        display: "flex",
        alignItems: "center",
        gap: 8,
      }}
    >
      <span
        style={{
          fontSize: 13,
          fontWeight: 700,
          color: "#333",
          letterSpacing: "0.02em",
        }}
      >
        {title}
      </span>
      {optional && (
        <span
          style={{
            fontSize: 11,
            fontWeight: 600,
            color: "#999",
            background: "#F0F0F0",
            borderRadius: 6,
            padding: "2px 8px",
          }}
        >
          任意
        </span>
      )}
    </div>
  );
}

function FieldCard({
  icon,
  iconBg,
  label,
  hint,
  children,
}: {
  icon: ReactNode;
  iconBg: string;
  label: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div style={cardStyle}>
      <div
        style={{
          display: "flex",
          alignItems: "flex-start",
          gap: 10,
          marginBottom: 14,
        }}
      >
        <div style={iconWrapStyle(iconBg)}>{icon}</div>
        <div>
          <span style={{ fontSize: 16, fontWeight: 700, color: "#111" }}>
            {label}
          </span>
          {hint && (
            <p
              style={{
                margin: "4px 0 0",
                fontSize: 12,
                color: "#999",
                lineHeight: 1.5,
              }}
            >
              {hint}
            </p>
          )}
        </div>
      </div>
      {children}
    </div>
  );
}

function OptionPills<T extends string>({
  options,
  value,
  onChange,
}: {
  options: { value: T; label: string }[];
  value: T | null;
  onChange: (value: T) => void;
}) {
  return (
    <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
      {options.map((option) => {
        const selected = value === option.value;
        return (
          <button
            key={option.value}
            type="button"
            onClick={() => onChange(option.value)}
            style={{
              padding: "10px 16px",
              borderRadius: 999,
              border: selected ? `1.5px solid ${ORANGE}` : "1px solid #E8E8E8",
              background: selected ? ORANGE_BG : "#fff",
              color: selected ? ORANGE : "#555",
              fontSize: 14,
              fontWeight: selected ? 700 : 500,
              cursor: "pointer",
            }}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

export function ProfileSettingsSheet({
  open,
  onClose,
  onSaved,
  mode = "settings",
}: ProfileSettingsSheetProps) {
  const isOnboarding = mode === "onboarding";
  const [profile, setProfile] = useState<UserProfile>(DEFAULT_PROFILE);
  const [isLoading, setIsLoading] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    let cancelled = false;
    setIsLoading(true);
    setError(null);

    fetchUserProfile()
      .then((response) => {
        if (cancelled) {
          return;
        }
        setProfile(applyNumericDefaults(response.profile));
      })
      .catch((fetchError: Error) => {
        if (!cancelled) {
          setError(fetchError.message);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [open]);

  const updateField = <K extends keyof UserProfile>(
    key: K,
    value: UserProfile[K],
  ) => {
    setProfile((prev) => ({ ...prev, [key]: value }));
  };

  const handleSave = async () => {
    if (!isRequiredComplete(profile)) {
      setError("必須項目をすべて入力してください");
      return;
    }

    setIsSaving(true);
    setError(null);

    try {
      const effective = applyNumericDefaults(profile);
      const response = await updateUserProfile({
        gender: effective.gender,
        birthDate: effective.birthDate,
        heightCm: effective.heightCm,
        currentWeightKg: effective.currentWeightKg,
        targetWeightKg: effective.targetWeightKg,
        activityLevel: null,
        targetPaceKgPerMonth: effective.targetPaceKgPerMonth,
        dietGoal: profile.dietGoal,
        desiredDietMethod: profile.desiredDietMethod,
        allergiesDislikes: profile.allergiesDislikes,
        pastDietExperience: profile.pastDietExperience,
        coachNotes: profile.coachNotes,
      });
      setProfile(response.profile);
      onSaved?.();
      onClose();
    } catch (saveError) {
      setError(
        saveError instanceof Error ? saveError.message : "保存に失敗しました",
      );
    } finally {
      setIsSaving(false);
    }
  };

  if (!open) {
    return null;
  }

  const heightCm = profile.heightCm ?? DEFAULT_NUMERIC.heightCm;
  const currentWeightKg =
    profile.currentWeightKg ?? DEFAULT_NUMERIC.currentWeightKg;
  const targetWeightKg =
    profile.targetWeightKg ?? DEFAULT_NUMERIC.targetWeightKg;
  const targetPaceKgPerMonth =
    profile.targetPaceKgPerMonth ?? DEFAULT_NUMERIC.targetPaceKgPerMonth;

  return (
    <div
      style={{
        position: "fixed",
        inset: 0,
        zIndex: 50,
        background: "#F5F5F7",
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
      }}
    >
      <div
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          minHeight: 44,
          padding: "0 20px",
          background: "#fff",
          borderBottom: "1px solid #F0F0F0",
          flexShrink: 0,
        }}
      >
        {!isOnboarding ? (
          <button
            type="button"
            onClick={onClose}
            aria-label="戻る"
            style={headerBtnStyle}
          >
            <ChevronLeft size={22} color="#111" strokeWidth={2} />
          </button>
        ) : (
          <span style={{ width: 22 }} />
        )}
        <span
          style={{
            fontSize: 17,
            fontWeight: 600,
            color: "#111",
            lineHeight: "22px",
          }}
        >
          {isOnboarding ? "プロフィール登録" : "プロフィール"}
        </span>
        {!isOnboarding ? (
          <button
            type="button"
            onClick={onClose}
            aria-label="閉じる"
            style={headerBtnStyle}
          >
            <X size={22} color="#AAA" strokeWidth={2} />
          </button>
        ) : (
          <span style={{ width: 22 }} />
        )}
      </div>

      <div
        style={{
          flex: 1,
          overflowY: "auto",
          padding: "20px 16px 24px",
        }}
      >
        <p
          style={{
            margin: "0 0 20px",
            fontSize: 14,
            color: "#666",
            lineHeight: 1.7,
            textAlign: "center",
          }}
        >
          {isOnboarding ? (
            <>
              はじめに、あなたの基本情報を登録しましょう。
              <br />
              カロリー目標やAIコーチのアドバイス精度向上に使います。
            </>
          ) : (
            <>
              あなたに最適なアドバイスや目標設定のために、
              <br />
              プロフィールを登録・更新できます。
            </>
          )}
        </p>

        {isLoading ? (
          <div
            style={{
              textAlign: "center",
              padding: "48px 0",
              color: "#888",
              fontSize: 14,
            }}
          >
            読み込み中...
          </div>
        ) : (
          <>
            <SectionHeader title="基本情報" />
            <p
              style={{
                margin: "0 0 14px",
                fontSize: 12,
                color: "#999",
                lineHeight: 1.6,
              }}
            >
              基礎代謝・カロリー目標の計算に使用します（必須）
            </p>

            <FieldCard
              icon={<User size={18} color={GREEN} strokeWidth={2.2} />}
              iconBg={GREEN_BG}
              label="性別"
              hint="目標摂取カロリーの計算に使用"
            >
              <OptionPills
                options={GENDER_OPTIONS}
                value={profile.gender}
                onChange={(value) => updateField("gender", value)}
              />
            </FieldCard>

            <FieldCard
              icon={<Calendar size={18} color={BLUE} strokeWidth={2.2} />}
              iconBg={BLUE_BG}
              label="生年月日"
              hint="目標摂取カロリーの計算に使用"
            >
              <input
                type="date"
                value={profile.birthDate ?? ""}
                max={new Date().toISOString().slice(0, 10)}
                onChange={(event) =>
                  updateField("birthDate", event.target.value || null)
                }
                style={dateInputStyle}
              />
            </FieldCard>

            <SettingCard
              icon={<User size={18} color={GREEN} strokeWidth={2.2} />}
              iconBg={GREEN_BG}
              label="身長"
              hint="目標摂取カロリーの計算に使用"
              value={heightCm}
              unit="cm"
              min={100}
              max={220}
              step={0.1}
              onChange={(value) => updateField("heightCm", value)}
            />

            <SettingCard
              icon={<Scale size={18} color={BLUE} strokeWidth={2.2} />}
              iconBg={BLUE_BG}
              label="現在の体重"
              hint="目標摂取カロリーの計算に使用"
              value={currentWeightKg}
              unit="kg"
              min={20}
              max={200}
              step={0.1}
              onChange={(value) => updateField("currentWeightKg", value)}
            />

            <SettingCard
              icon={<Target size={18} color={ORANGE} strokeWidth={2.2} />}
              iconBg={ORANGE_BG}
              label="目標体重"
              hint="目標摂取カロリーの計算に使用"
              value={targetWeightKg}
              unit="kg"
              min={20}
              max={200}
              step={0.1}
              onChange={(value) => updateField("targetWeightKg", value)}
            />

            <CalorieGoalCard profile={profile} />

            <div style={{ height: 8 }} />

            <SectionHeader title="詳細設定" optional />
            <p
              style={{
                margin: "0 0 14px",
                fontSize: 12,
                color: "#999",
                lineHeight: 1.6,
              }}
            >
              あとから設定・変更できます
            </p>

            <SettingCard
              icon={<Target size={18} color={ORANGE} strokeWidth={2.2} />}
              iconBg={ORANGE_BG}
              label="目標ペース"
              hint="目標摂取カロリーの計算に使用"
              value={targetPaceKgPerMonth}
              unit="kg/月"
              min={0}
              max={10}
              step={0.1}
              onChange={(value) => updateField("targetPaceKgPerMonth", value)}
            />

            <FieldCard
              icon={<Heart size={18} color={GREEN} strokeWidth={2.2} />}
              iconBg={GREEN_BG}
              label="ダイエット目的"
            >
              <OptionPills
                options={DIET_GOAL_OPTIONS}
                value={profile.dietGoal}
                onChange={(value) => updateField("dietGoal", value)}
              />
            </FieldCard>

            <FieldCard
              icon={<Heart size={18} color={ORANGE} strokeWidth={2.2} />}
              iconBg={ORANGE_BG}
              label="やりたいダイエット方法"
            >
              <textarea
                value={profile.desiredDietMethod ?? ""}
                maxLength={PROFILE_TEXT_MAX_LENGTH}
                onChange={(event) =>
                  updateField(
                    "desiredDietMethod",
                    trimProfileText(event.target.value),
                  )
                }
                placeholder="例：リバウンドしにくく、ある程度筋力をつけて、健康に痩せたい"
                rows={3}
                style={textareaStyle}
              />
              <CharCounter value={profile.desiredDietMethod ?? ""} />
            </FieldCard>

            <FieldCard
              icon={<User size={18} color={BLUE} strokeWidth={2.2} />}
              iconBg={BLUE_BG}
              label="アレルギー・苦手食材"
            >
              <textarea
                value={profile.allergiesDislikes ?? ""}
                maxLength={PROFILE_TEXT_MAX_LENGTH}
                onChange={(event) =>
                  updateField(
                    "allergiesDislikes",
                    trimProfileText(event.target.value),
                  )
                }
                placeholder="例：えびアレルギー、きのこが苦手"
                rows={3}
                style={textareaStyle}
              />
              <CharCounter value={profile.allergiesDislikes ?? ""} />
            </FieldCard>

            <FieldCard
              icon={<User size={18} color={PURPLE} strokeWidth={2.2} />}
              iconBg={PURPLE_BG}
              label="過去のダイエット経験"
            >
              <textarea
                value={profile.pastDietExperience ?? ""}
                maxLength={PROFILE_TEXT_MAX_LENGTH}
                onChange={(event) =>
                  updateField(
                    "pastDietExperience",
                    trimProfileText(event.target.value),
                  )
                }
                placeholder="例：糖質制限を3ヶ月続けたがリバウンドした"
                rows={3}
                style={textareaStyle}
              />
              <CharCounter value={profile.pastDietExperience ?? ""} />
            </FieldCard>

            <FieldCard
              icon={<User size={18} color={GREEN} strokeWidth={2.2} />}
              iconBg={GREEN_BG}
              label="AIコーチに伝えておきたいこと"
              hint="AIコーチの提案精度向上に使用"
            >
              <textarea
                value={profile.coachNotes ?? ""}
                maxLength={PROFILE_TEXT_MAX_LENGTH}
                onChange={(event) =>
                  updateField("coachNotes", trimProfileText(event.target.value))
                }
                placeholder="例：週末だけ外食が多い"
                rows={2}
                style={textareaStyle}
              />
              <CharCounter value={profile.coachNotes ?? ""} />
            </FieldCard>

            {error && (
              <div
                style={{
                  fontSize: 13,
                  color: "#DC2626",
                  textAlign: "center",
                  marginBottom: 12,
                }}
              >
                {error}
              </div>
            )}

            <div
              style={{
                display: "flex",
                flexDirection: "column",
                gap: 10,
                marginTop: 4,
              }}
            >
              <button
                type="button"
                onClick={() => void handleSave()}
                disabled={isSaving || !isRequiredComplete(profile)}
                style={{
                  ...primaryBtnStyle,
                  opacity: isSaving || !isRequiredComplete(profile) ? 0.7 : 1,
                  cursor:
                    isSaving || !isRequiredComplete(profile)
                      ? "not-allowed"
                      : "pointer",
                }}
              >
                {isSaving
                  ? "保存中..."
                  : isOnboarding
                    ? "登録してはじめる"
                    : "保存する"}
              </button>
              {!isOnboarding && (
                <button
                  type="button"
                  onClick={onClose}
                  disabled={isSaving}
                  style={secondaryBtnStyle}
                >
                  キャンセル
                </button>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}

const iconWrapStyle = (background: string): CSSProperties => ({
  width: 36,
  height: 36,
  borderRadius: 10,
  background,
  display: "flex",
  alignItems: "center",
  justifyContent: "center",
  flexShrink: 0,
});

const headerBtnStyle: CSSProperties = {
  width: 22,
  height: 22,
  border: "none",
  background: "transparent",
  padding: 0,
  cursor: "pointer",
  display: "flex",
  alignItems: "center",
  justifyContent: "center",
};

const cardStyle: CSSProperties = {
  background: "#fff",
  borderRadius: 16,
  border: "1px solid #EBEBEB",
  padding: "18px 16px 16px",
  marginBottom: 14,
};

const dateInputStyle: CSSProperties = {
  width: "100%",
  padding: "12px 14px",
  borderRadius: 12,
  border: "1px solid #E8E8E8",
  background: "#fff",
  fontSize: 16,
  color: "#111",
  boxSizing: "border-box",
};

const textareaStyle: CSSProperties = {
  width: "100%",
  padding: "12px 14px",
  borderRadius: 12,
  border: "1px solid #E8E8E8",
  background: "#fff",
  fontSize: 14,
  color: "#111",
  lineHeight: 1.6,
  resize: "vertical",
  minHeight: 88,
  boxSizing: "border-box",
  fontFamily: "inherit",
};

const primaryBtnStyle: CSSProperties = {
  width: "100%",
  padding: "15px 0",
  borderRadius: 12,
  border: "none",
  background: ORANGE,
  fontSize: 16,
  fontWeight: 700,
  color: "#fff",
  cursor: "pointer",
};

const secondaryBtnStyle: CSSProperties = {
  width: "100%",
  padding: "15px 0",
  borderRadius: 12,
  border: "1px solid #E8E8E8",
  background: "#fff",
  fontSize: 16,
  fontWeight: 600,
  color: "#666",
  cursor: "pointer",
};
