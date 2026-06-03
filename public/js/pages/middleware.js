// Middleware page — client-side filter for the inline visualizer's "All routes"
// table. Progressive enhancement only: the page is fully server-rendered; this
// just hides non-matching rows as you type. Re-binds after every htmx swap.
(function () {
  function wire() {
    var input = document.getElementById('mwv-filter');
    var table = document.getElementById('mwv-table');
    if (!input || !table || input.dataset.wired === '1') return;
    input.dataset.wired = '1';

    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      var rows = table.querySelectorAll('tbody tr');
      rows.forEach(function (row) {
        var text = row.textContent.toLowerCase();
        row.style.display = (q === '' || text.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  document.addEventListener('DOMContentLoaded', wire);
  document.body.addEventListener('htmx:afterSettle', wire);
})();
