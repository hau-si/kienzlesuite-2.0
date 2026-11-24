'use strict';

// Tooltips (.hilfe mit data-tip, .tip mit .tip-box) bleiben im sichtbaren Bereich: Reicht der Platz nicht, wird der ganze Tooltip
// verschoben (im Zweifel in die Fenstermitte), nie schmaler gemacht.
(function () {
  const M = 12;
  const fit = (t) => {
    const vw = document.documentElement.clientWidth;
    if (t.classList.contains('hilfe')) {
      const w = Math.min(280, vw - 2 * M), r = t.getBoundingClientRect();
      const left = r.left + r.width / 2 - w / 2;
      const target = Math.max(M, Math.min(left, vw - w - M));
      t.style.setProperty('--tip-shift', Math.round(target - left) + 'px');
      return;
    }
    const box = t.querySelector('.tip-box');
    if (!box) return;
    box.style.setProperty('--tip-shift', '0px');
    const r = box.getBoundingClientRect();
    if (r.width === 0) return;
    const target = r.width > vw - 2 * M ? (vw - r.width) / 2 : Math.max(M, Math.min(r.left, vw - r.width - M));
    box.style.setProperty('--tip-shift', Math.round(target - r.left) + 'px');
  };
  const on = (e) => { const t = e.target.closest && e.target.closest('.hilfe, .tip'); if (t) requestAnimationFrame(() => fit(t)); };
  document.addEventListener('mouseover', on);
  document.addEventListener('focusin', on);
  document.addEventListener('click', on);
})();
