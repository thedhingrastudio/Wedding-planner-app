;(() => {
  const DISMISS_MS = 3000;

  function removeNode(el) {
    if (!el) return;
    if (typeof el.remove === "function") el.remove();
    else if (el.parentNode) el.parentNode.removeChild(el);
  }

  function closeToast(toast) {
    if (!toast || toast.dataset.toastClosing === "1") return;
    toast.dataset.toastClosing = "1";

    toast.style.transition = "opacity 160ms ease, transform 160ms ease";
    toast.style.opacity = "0";
    toast.style.transform = "translateY(8px)";

    const wrap = toast.closest("[data-toast-wrap]");
    setTimeout(() => {
      removeNode(toast);
      if (wrap && wrap.querySelectorAll("[data-toast]").length === 0) removeNode(wrap);
    }, 180);
  }

  function initToast(toast) {
    if (!toast || toast.dataset.toastInit === "1") return;
    toast.dataset.toastInit = "1";

    const timerId = setTimeout(() => closeToast(toast), DISMISS_MS);
    toast.dataset.toastTimerId = String(timerId);

    const closeBtn = toast.querySelector("[data-toast-close]");
    if (closeBtn) {
      closeBtn.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        clearTimeout(Number(toast.dataset.toastTimerId));
        closeToast(toast);
      });
    }
  }

  function initAllToasts() {
    document.querySelectorAll("[data-toast]").forEach(initToast);
  }

  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-toast-close]");
    if (!btn) return;
    const toast = btn.closest("[data-toast]") || document.querySelector("[data-toast]");
    if (!toast) return;
    e.preventDefault();
    e.stopPropagation();
    clearTimeout(Number(toast.dataset.toastTimerId));
    closeToast(toast);
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAllToasts);
  } else {
    initAllToasts();
  }
})();