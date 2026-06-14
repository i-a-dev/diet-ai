import {
  Calendar,
  ChevronLeft,
  ChevronRight,
  Cookie,
  Footprints,
  Moon,
  StickyNote,
  Sun,
  Sunset,
  UtensilsCrossed,
  Weight,
} from "lucide-react";
import { MealSection } from "../MealSection.tsx";
import { SecIcon } from "../SecIcon.tsx";
import { TopNav } from "../TopNav.tsx";
import { ORANGE } from "../../constants.ts";

export function RecordScreen() {
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
  const plusBtn = (
    <span
      style={{ color: ORANGE, fontSize: 26, lineHeight: 1, cursor: "pointer" }}
    >
      +
    </span>
  );

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
        title="記録する"
        rightIcon={<Calendar size={22} color="#C0C0C0" />}
      />
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
        <span style={{ fontSize: 15, fontWeight: 600, color: "#111" }}>
          今日 4/24（水）
        </span>
        <ChevronRight size={22} color="#C0C0C0" />
      </div>
      <div
        style={{
          flex: 1,
          overflowY: "auto",
          background: "#F7F7F7",
        }}
      >
        <div style={{ ...secStyle, marginTop: 12 }}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#FDE8C8" color={ORANGE}>
                <Weight size={16} />
              </SecIcon>
              体重
            </div>
            {plusBtn}
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
                  62.4
                </span>
                <span style={{ fontSize: 18, color: "#888" }}> kg</span>
              </div>
              <div style={{ fontSize: 13, color: ORANGE, marginTop: 5 }}>
                前日比 -0.2kg
              </div>
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
              1,582 / 1,800kcal
            </span>
          </div>
          <MealSection
            icon={<Sun size={14} />}
            title="朝ごはん"
            items={[
              { label: "白米 150g", kcal: "234kcal" },
              { label: "味噌汁", kcal: "45kcal" },
              { label: "焼き鮭", kcal: "180kcal" },
            ]}
          />
          <MealSection
            icon={<Sunset size={14} />}
            title="昼ごはん"
            items={[
              { label: "鶏のから揚げ定食", kcal: "680kcal" },
              { label: "ご飯 少なめ", kcal: "180kcal" },
              { label: "サラダ", kcal: "35kcal" },
            ]}
          />
          <MealSection
            icon={<Moon size={14} />}
            title="夜ごはん"
            items={[
              { label: "豆腐ハンバーグ", kcal: "320kcal" },
              { label: "野菜スープ", kcal: "85kcal" },
              { label: "ごはん 150g", kcal: "147kcal" },
            ]}
          />
          <MealSection
            icon={<Cookie size={14} />}
            title="間食・おやつ"
            items={[]}
            isLast
          />
        </div>

        <div style={secStyle}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#D6F5E8" color="#2EAA72">
                <Footprints size={16} />
              </SecIcon>
              運動・歩数
            </div>
            {plusBtn}
          </div>
          <div style={{ display: "flex", justifyContent: "space-between" }}>
            <div>
              <div style={{ fontSize: 12, color: "#AAA", marginBottom: 3 }}>
                歩数
              </div>
              <div>
                <span style={{ fontSize: 28, fontWeight: 700, color: "#111" }}>
                  5,842
                </span>
                <span style={{ fontSize: 14, color: "#888" }}> 歩</span>
              </div>
            </div>
            <div style={{ textAlign: "right" }}>
              <div style={{ fontSize: 12, color: "#AAA", marginBottom: 3 }}>
                消費カロリー
              </div>
              <div>
                <span style={{ fontSize: 28, fontWeight: 700, color: "#111" }}>
                  231
                </span>
                <span style={{ fontSize: 14, color: "#888" }}> kcal</span>
              </div>
            </div>
          </div>
        </div>

        <div style={{ ...secStyle, marginBottom: 10 }}>
          <div style={secHead}>
            <div style={secTitle}>
              <SecIcon bg="#E0EFFE" color="#3B82F6">
                <StickyNote size={16} />
              </SecIcon>
              メモ
            </div>
            {plusBtn}
          </div>
          <div style={{ fontSize: 14, color: "#AAA" }}>
            今日はケーキを食べちゃった
          </div>
        </div>
      </div>
    </div>
  );
}
