// Modul Telefon: Rückfrage vor Löschen und anderen folgenreichen Knöpfen (Formular mit data-confirm).
document.addEventListener('submit', (e) => {
  const f = e.target.closest('form[data-confirm]');
  if (f && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
});
