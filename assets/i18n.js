(() => {
  const messages = {
    zh: {
      homeTitle: "家庭积分世界",
      boy: "弟弟",
      girl: "姐姐",
      availablePoints: "可用积分",
      earnedToday: "今日已得",
      quarterlyPool: "季度奖池",
      parentEntrance: "🔒 家长入口",
      openBoyShop: "📦 打开奖励箱子",
      openGirlShop: "🎵 进入奖励商店",
      backHome: "← 积分世界",
      boyShopTitle: "📦 弟弟的奖励箱子",
      girlShopTitle: "🎵 姐姐的奖励商店",
      myRewards: "📦 我的奖励",
      pending: "⏳ 待家长确认",
      approved: "✓ 已收入囊中",
      pendingHelp: "申请已发送，家长同意后才能领取或使用。",
      approvedHelp: "家长已同意，可以领取或使用啦！",
      redeem: "兑换",
      parentApproval: "需要家长确认",
      duplicateTitle: "⏳ 已有兑换申请",
      duplicateMessage: "请先等待家长确认当前的兑换请求。",
      gotIt: "知道了",
      requestSent: "🎉 已发送兑换请求！等待家长确认",
      points: "{count} 分",
      pointsShort: "{count}",
      needMore: "还差 {count} 分",
      overflow: "⭐ 超额 {count} 分已进入季度奖池"
    },

    en: {
      homeTitle: "Our Reward World",
      boy: "Little Brother",
      girl: "Big Sister",
      availablePoints: "Available Points",
      earnedToday: "Earned Today",
      quarterlyPool: "Quarterly Bonus Bank",
      parentEntrance: "🔒 Parents",
      openBoyShop: "📦 Open Reward Chest",
      openGirlShop: "🎵 Visit Reward Shop",
      backHome: "← Reward World",
      boyShopTitle: "📦 Little Brother’s Reward Chest",
      girlShopTitle: "🎵 Big Sister’s Reward Shop",
      myRewards: "📦 My Rewards",
      pending: "⏳ Waiting for Parent Approval",
      approved: "✓ It's Yours!",
      pendingHelp: "Request sent! Wait for a parent to approve it.",
      approvedHelp: "Approved! Ask a parent to enjoy your reward.",
      redeem: "Redeem",
      parentApproval: "Parent approval required",
      duplicateTitle: "⏳ A Request Is Already Waiting",
      duplicateMessage: "Please wait for a parent to review your current request.",
      gotIt: "Got It",
      requestSent: "🎉 Request sent! Waiting for parent approval.",
      points: "{count} pts",
      pointsShort: "{count}",
      needMore: "{count} more pts needed",
      overflow: "⭐ {count} extra pts added to your Bonus Bank"
    }
  };

  const rewardNames = {
    "电视 20 分钟": "TV · 20 minutes",
    "电视 30 分钟": "TV · 30 minutes",
    "电视 60 分钟": "TV · 60 minutes",
    "游戏机 20 分钟": "Video Games · 20 minutes",
    "游戏机 30 分钟": "Video Games · 30 minutes",
    "游戏机 60 分钟": "Video Games · 60 minutes",
    "iPad 10 分钟": "iPad · 10 minutes",
    "iPad 15 分钟": "iPad · 15 minutes",
    "iPad 20 分钟": "iPad · 20 minutes",
    "iPad 30 分钟": "iPad · 30 minutes",
    "稀有零食小份": "Special Snack · Small",
    "稀有零食中份": "Special Snack · Medium"
  };

  let language = "zh";

  try {
    const saved = localStorage.getItem("family-points-language");

    if (saved === "zh" || saved === "en") {
      language = saved;
    }
  } catch {
    // Storage may be unavailable; use Chinese for this visit.
  }

  function translate(key, variables = {}) {
    let text = messages[language][key] ?? messages.zh[key] ?? key;

    Object.entries(variables).forEach(([name, value]) => {
      text = text.replaceAll(`{${name}}`, String(value));
    });

    return text;
  }

  function applyLanguage() {
    document.documentElement.lang =
      language === "en" ? "en" : "zh-CN";

    document.querySelectorAll("[data-i18n]").forEach(element => {
      const key = element.dataset.i18n;
      const variables = {};

      if (element.dataset.count !== undefined) {
        variables.count = element.dataset.count;
      }

      element.textContent = translate(key, variables);
    });

    document.querySelectorAll("[data-reward-name]").forEach(element => {
      const original = element.dataset.rewardName;

      element.textContent = language === "en"
        ? (rewardNames[original] ?? original)
        : original;
    });

    document.querySelectorAll("[data-language-toggle]").forEach(button => {
      button.textContent = language === "zh" ? "EN" : "中文";

      button.setAttribute(
        "aria-label",
        language === "zh" ? "Switch to English" : "切换为中文"
      );
    });

    const titleKey = document.body.dataset.pageTitle;

    if (titleKey) {
      document.title = translate(titleKey);
    }
  }

  window.familyI18n = {
    t: translate,
    apply: applyLanguage,
    getLanguage: () => language
  };

  document.querySelectorAll("[data-language-toggle]").forEach(button => {
    button.addEventListener("click", () => {
      language = language === "zh" ? "en" : "zh";

      try {
        localStorage.setItem("family-points-language", language);
      } catch {
        // Switching still works without persistence.
      }

      applyLanguage();
    });
  });

  applyLanguage();
})();