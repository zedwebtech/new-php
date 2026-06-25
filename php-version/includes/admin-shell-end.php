  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Flatpickr — calendar + time-picker UI for every <input type="date">
     and <input type="datetime-local"> in the admin.  Native browser date
     widgets are inconsistent across OSes (and ugly in dark mode), so we
     enhance them globally with one tiny library (~25 KB minified). -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/themes/airbnb.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script>
(function () {
  if (typeof flatpickr === 'undefined') return;
  // Auto-enhance every date input across admin tabs.  We use the
  // original HTML5 input attributes (min/max/value) so existing forms
  // submit the same ISO strings — flatpickr is purely a visual upgrade.
  function enhance(scope) {
    (scope || document).querySelectorAll('input[type="date"]:not(.fp-enhanced), input[type="datetime-local"]:not(.fp-enhanced)').forEach(function (el) {
      var withTime = el.type === 'datetime-local';
      el.classList.add('fp-enhanced');
      try {
        flatpickr(el, {
          enableTime: withTime,
          time_24hr: true,
          dateFormat: withTime ? 'Y-m-d\\TH:i' : 'Y-m-d',
          altInput: true,
          altFormat: withTime ? 'D, M j Y · H:i' : 'D, M j Y',
          allowInput: true,
          // Range pairing: any pair of inputs where one is name="from"/"starts_at"
          // and the next is name="to"/"ends_at" gets its min set from the start.
          onChange: function (sel, dStr, fp) {
            if (!sel.length) return;
            var name = el.getAttribute('name') || '';
            if (name === 'starts_at' || name.endsWith('_from') || name === 'vh_from') {
              var partner = document.querySelector('input[name="ends_at"], input[name="' + name.replace('_from', '_to') + '"], input[name="vh_to"]');
              if (partner && partner._flatpickr) partner._flatpickr.set('minDate', sel[0]);
            }
          },
          disableMobile: true,   // use flatpickr even on touch devices
        });
      } catch (e) { console.warn('flatpickr init failed', e); }
    });
  }
  enhance(document);
  // Catch any inputs that get inserted dynamically (modals, drawers).
  var mo = new MutationObserver(function (muts) { muts.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) enhance(n); }); }); });
  mo.observe(document.body, { childList: true, subtree: true });
})();
</script>

<script>
// Instant theme toggle — no page redirect, persists via cookie for 1 year
// AND fires a fire-and-forget POST to /ajax/user-theme.php so the choice
// is also saved to the logged-in user's row.  Multi-device admins toggle
// once and the preference follows them everywhere.
function toggleAdmTheme(ev){
  if(ev) ev.preventDefault();
  var html = document.documentElement;
  var cur  = html.getAttribute('data-bs-theme') || 'light';
  var next = cur === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-bs-theme', next);
  document.cookie = 'adm_mode=' + next + '; path=/; max-age=' + (365*86400) + '; SameSite=Lax';
  var icon = document.getElementById('admThemeIcon');
  if (icon) icon.className = 'bi ' + (next === 'dark' ? 'bi-sun' : 'bi-moon-stars');
  // Persist server-side for the logged-in user (silently — UI doesn't
  // wait for the response and falls back to cookie if the call fails).
  try {
    fetch('ajax/user-theme.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ theme: next })
    }).catch(function(){ /* offline / 5xx — cookie still works */ });
  } catch (_) {}
}
</script>
</body>
</html>
