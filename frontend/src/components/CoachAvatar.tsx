import coachAvatar from "../assets/coach-avatar.png";

export function CoachAvatar() {
  return (
    <img
      src={coachAvatar}
      alt="AIコーチ"
      width={38}
      height={38}
      style={{
        width: 38,
        height: 38,
        borderRadius: "50%",
        objectFit: "cover",
        flexShrink: 0,
        display: "block",
      }}
    />
  );
}
