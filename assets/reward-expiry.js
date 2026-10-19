(() => {
  const expiryAt = Number(window.rewardExpiryAt);

  if (!Number.isFinite(expiryAt) || expiryAt <= 0) return;

  function hideExpiredRewards() {
    if (Date.now() < expiryAt) return;

    document.querySelectorAll('[data-approved-reward]')
      .forEach(card => card.remove());

    document.querySelectorAll('.pending-cart')
      .forEach(section => {
        if (!section.querySelector('.cart-card')) {
          section.remove();
        }
      });

    clearInterval(timer);
  }

  const timer = setInterval(hideExpiredRewards, 30000);

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      hideExpiredRewards();
    }
  });

  hideExpiredRewards();
})();