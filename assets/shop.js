document.addEventListener("DOMContentLoaded", () => {
  const params = new URLSearchParams(window.location.search);

  if (params.get("requested") !== "1") return;

  if (typeof confetti !== "function") return;

  confetti({
    particleCount: 120,
    spread: 75,
    startVelocity: 45,
    origin: { x: 0.5, y: 0.7 },
    colors: ["#ffd166", "#7ee787", "#7c5cff", "#ffffff"]
  });

  const banner = document.createElement("div");
  banner.className = "celebrate-banner";
  banner.textContent = "🎉 已发送兑换请求！等待家长确认";
  document.body.appendChild(banner);

  setTimeout(() => banner.remove(), 3500);
});