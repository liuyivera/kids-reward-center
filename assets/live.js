const ACTIVE_WINDOWS = [
  { start: "00:00", end: "23:59", interval: 5000 }
];

let pollTimer = null;
let lastVersion = null;
let retryDelay = 10000;

function getActiveInterval() {
  const now = new Date();
  const minutes = now.getHours() * 60 + now.getMinutes();

  for (const window of ACTIVE_WINDOWS) {
    const [startHour, startMinute] = window.start.split(":").map(Number);
    const [endHour, endMinute] = window.end.split(":").map(Number);

    const start = startHour * 60 + startMinute;
    const end = endHour * 60 + endMinute;

    if (minutes >= start && minutes < end) {
      return window.interval;
    }
  }

  return null;
}

async function fetchJson(url) {
  const response = await fetch(url, { cache: "no-store" });

  if (!response.ok) {
    throw new Error("Network response was not ok");
  }

  return response.json();
}

function renderChildren(children) {
  children.forEach(child => {
    const card = document.querySelector(`[data-child-id="${child.id}"]`);

    if (!card) return;

    const balance = card.querySelector("[data-balance]");
    const daily = card.querySelector("[data-daily]");
    const dailyBar = card.querySelector("[data-daily-bar]");
    const pool = card.querySelector("[data-pool]");
    const poolBar = card.querySelector("[data-pool-bar]");

    const oldBalance = Number(balance?.textContent || 0);
    const newBalance = Number(child.normal_balance);

    if (balance) balance.textContent = newBalance;

    if (daily) {
      daily.textContent = `${child.daily_points_today} / ${child.daily_cap}`;
    }

    if (dailyBar) {
      dailyBar.style.width =
        Math.min(100, child.daily_points_today / child.daily_cap * 100) + "%";
    }

    if (pool) {
      pool.textContent = `${child.quarterly_pool} / ${child.quarterly_goal}`;
    }

    if (poolBar) {
      poolBar.style.width =
        Math.min(100, child.quarterly_pool / child.quarterly_goal * 100) + "%";
    }

    if (newBalance > oldBalance && balance) {
      animateIncrease(balance);
    }
  });
}

function animateIncrease(element) {
  element.animate(
    [
      { transform: "scale(1)", opacity: 1 },
      { transform: "scale(1.25)", opacity: 1 },
      { transform: "scale(1)", opacity: 1 }
    ],
    {
      duration: 700,
      easing: "ease-out"
    }
  );
}

async function checkForUpdates() {
  try {
    const versionData = await fetchJson("api/version.php");

    if (lastVersion === null) {
      lastVersion = versionData.v;
      const statusData = await fetchJson("api/status.php");
      renderChildren(statusData.children);
      return;
    }

    if (versionData.v !== lastVersion) {
      lastVersion = versionData.v;
      const statusData = await fetchJson("api/status.php");
      renderChildren(statusData.children);
    }

    retryDelay = 10000;
  } catch (error) {
    retryDelay = Math.min(retryDelay * 2, 60000);
  }
}

function schedulePolling() {
  clearTimeout(pollTimer);

  if (document.hidden) return;

  const interval = getActiveInterval();

  if (!interval) return;

  pollTimer = setTimeout(async () => {
    await checkForUpdates();
    schedulePolling();
  }, interval);
}

document.addEventListener("visibilitychange", () => {
  if (!document.hidden) {
    checkForUpdates();
  }

  schedulePolling();
});

checkForUpdates();
schedulePolling();