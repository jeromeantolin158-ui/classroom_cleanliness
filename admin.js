/* Admin pages: search + filter + pagination + modals. No libraries. */
(function () {
  var tb = document.querySelector('table[data-paged]');
  if (tb) {
    var per = parseInt(tb.dataset.paged, 10) || 8, noun = tb.dataset.noun || 'items',
        rows = Array.prototype.slice.call(tb.querySelectorAll('tbody tr.r')),
        q = document.querySelector('[data-search]'), fs = Array.prototype.slice.call(document.querySelectorAll('[data-filter]')),
        cnt = document.getElementById('a-count'), show = document.getElementById('a-showing'),
        prev = document.getElementById('a-prev'), next = document.getElementById('a-next'),
        none = tb.querySelector('tr.none'), page = 0, hit = [];
    function apply(reset) {
      var t = q ? q.value.trim().toLowerCase() : '';
      hit = rows.filter(function (r) {
        return (!t || r.dataset.q.indexOf(t) > -1) && fs.every(function (x) { return !x.value || r.dataset[x.dataset.attr || 'f'] === x.value; });
      });
      if (reset) page = 0;
      var pages = Math.max(1, Math.ceil(hit.length / per)); if (page >= pages) page = pages - 1;
      rows.forEach(function (r) { r.hidden = true; });
      hit.slice(page * per, page * per + per).forEach(function (r) { r.hidden = false; });
      if (none) none.hidden = hit.length > 0;
      if (cnt) cnt.textContent = hit.length + ' ' + noun;
      if (show) show.textContent = hit.length ? 'Showing ' + (page * per + 1) + '-' + Math.min(hit.length, page * per + per) + ' of ' + hit.length : 'Nothing to show';
      if (prev) prev.disabled = page === 0;
      if (next) next.disabled = page >= pages - 1;
    }
    if (q) q.addEventListener('input', function () { apply(true); });
    fs.forEach(function (x) { x.addEventListener('change', function () { apply(true); }); });
    if (prev) prev.addEventListener('click', function () { page--; apply(false); });
    if (next) next.addEventListener('click', function () { page++; apply(false); });
    apply(true);
  }

  document.querySelectorAll('[data-open]').forEach(function (b) {
    b.addEventListener('click', function () { document.getElementById(b.dataset.open).showModal(); });
  });
  document.querySelectorAll('dialog [data-close]').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('dialog').close(); });
  });
  document.querySelectorAll('dialog.a-modal').forEach(function (d) {
    d.addEventListener('mousedown', function (e) { if (e.target === d) d.close(); });
    if (d.hasAttribute('data-autoopen')) {
      d.showModal();
      d.addEventListener('close', function () { location.href = location.pathname; });
    }
  });
  document.querySelectorAll('select[data-role]').forEach(function (r) {
    var s = r.form.querySelector('[data-sect]');
    function sync() { if (r.disabled) return; s.disabled = r.value === 'admin'; if (s.disabled) s.value = ''; s.required = r.value === 'user'; }
    r.addEventListener('change', sync); sync();
  });
  document.querySelectorAll('[data-times]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = document.getElementById('timesModal');
      d.querySelector('[name=schedule_id]').value = b.dataset.sid;
      d.querySelector('[name=time_in]').value = b.dataset.in;
      d.querySelector('[name=time_out]').value = b.dataset.out;
      d.querySelector('#timesWho').textContent = b.dataset.room;
      d.showModal();
    });
  });
  document.querySelectorAll('[data-reset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = document.getElementById('resetModal');
      d.querySelector('[name=user_id]').value = b.dataset.reset;
      d.querySelector('#resetName').textContent = b.dataset.name;
      d.showModal();
    });
  });

  /* Delete confirmation window (forms marked data-confirm) */
  var pending = null, dm = document.createElement('dialog');
  dm.className = 'a-modal fig del';
  dm.innerHTML = '<div class="dl"><h3 class="dT"></h3><p class="dH"></p><p class="dX"></p><p class="dR">This action cannot be undone.</p><div class="a-acts"><button type="button" class="s-btn soft dC">Cancel</button><button type="button" class="s-btn danger dOk"></button></div></div>';
  document.body.appendChild(dm);
  dm.querySelector('.dC').addEventListener('click', function () { dm.close(); pending = null; });
  dm.addEventListener('mousedown', function (e) { if (e.target === dm) dm.close(); });
  dm.querySelector('.dOk').addEventListener('click', function () { var f = pending; pending = null; dm.close(); if (f) f.submit(); });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.hasAttribute || !f.hasAttribute('data-confirm')) return;
    e.preventDefault(); pending = f;
    dm.querySelector('.dT').textContent = f.dataset.title || 'Delete';
    dm.querySelector('.dH').textContent = f.dataset.heading || 'Delete this record?';
    dm.querySelector('.dX').textContent = f.dataset.text || '';
    dm.querySelector('.dOk').textContent = f.dataset.title || 'Delete';
    dm.showModal();
  });
})();
