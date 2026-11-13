/* CICI app script: password toggle, admin tables (search / filter / paging), popups, delete confirmation, bell. No libraries. */
(function () {
  /* Show / Hide password buttons (login, profile, set-password popup) */
  document.querySelectorAll('.show').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = b.parentNode.querySelector('input'), s = i.type === 'password';
      i.type = s ? 'text' : 'password';
      b.textContent = s ? 'Hide' : 'Show';
    });
  });

  /* Admin notification bell (forgot-password requests) */
  var wrap = document.getElementById('bellWrap');
  if (wrap) {
    var bell = document.getElementById('bell'), pop = document.getElementById('bellPop'),
        list = document.getElementById('bellList'), dot = document.getElementById('bellN'),
        empty = document.getElementById('bellEmpty'), csrf = wrap.dataset.csrf;

    var refresh = function () {
      var n = list.querySelectorAll('.bell-item[data-pending="1"]').length;
      dot.textContent = n; dot.hidden = n === 0;
      empty.hidden = list.querySelectorAll('.bell-item').length > 0;
    };
    var toggle = function (show) { pop.hidden = !show; bell.setAttribute('aria-expanded', show ? 'true' : 'false'); };
    var send = function (action, id) {
      var f = new FormData();
      f.append('csrf', csrf); f.append('action', action); f.append('request_id', id);
      return fetch('notify.php', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    };

    bell.addEventListener('click', function () { toggle(pop.hidden); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) toggle(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggle(false); });

    list.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-act]');
      if (!b) return;
      var item = b.closest('.bell-item'), act = b.dataset.act;
      if (act === 'ok') { item.remove(); refresh(); return; }
      if (act === 'reset' && !confirm('Reset this password?')) return;
      b.disabled = true;
      send(act, item.dataset.id).then(function (d) {
        if (!d.ok) throw new Error('failed');
        if (act === 'dismiss') { item.remove(); }
        else {
          item.dataset.pending = '0';
          var box = item.querySelector('.bell-body'); box.textContent = '';
          var t = document.createElement('div'); t.textContent = 'Temporary password for ' + d.username + ':';
          var c = document.createElement('code'); c.textContent = d.temp;
          var s = document.createElement('small'); s.textContent = 'Shown once. Give it to the student privately.';
          box.appendChild(t); box.appendChild(c); box.appendChild(s);
          item.querySelector('.bell-actions').innerHTML = '<button type="button" data-act="ok">Done</button>';
        }
        refresh();
      }).catch(function () { b.disabled = false; alert('Something went wrong. Reload the page and try again.'); });
    });
  }

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
  document.querySelectorAll('[data-reset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = document.getElementById('resetModal');
      d.querySelector('[name=user_id]').value = b.dataset.reset;
      d.querySelector('#resetName').textContent = b.dataset.name;
      d.showModal();
    });
  });

  /* Delete confirmation window (forms marked data-confirm). Built the first time it is needed. */
  var pending = null, dm = null;
  var confirmWindow = function () {
    if (dm) return dm;
    dm = document.createElement('dialog');
    dm.className = 'a-modal fig del';
    dm.innerHTML = '<div class="dl"><h3 class="dT"></h3><p class="dH"></p><p class="dX"></p><p class="dR">This action cannot be undone.</p><div class="a-acts"><button type="button" class="s-btn soft dC">Cancel</button><button type="button" class="s-btn danger dOk"></button></div></div>';
    document.body.appendChild(dm);
    dm.querySelector('.dC').addEventListener('click', function () { dm.close(); pending = null; });
    dm.addEventListener('mousedown', function (e) { if (e.target === dm) dm.close(); });
    dm.querySelector('.dOk').addEventListener('click', function () { var f = pending; pending = null; dm.close(); if (f) f.submit(); });
    return dm;
  };
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.hasAttribute || !f.hasAttribute('data-confirm')) return;
    e.preventDefault(); pending = f;
    var d = confirmWindow();
    d.querySelector('.dT').textContent = f.dataset.title || 'Delete';
    d.querySelector('.dH').textContent = f.dataset.heading || 'Delete this record?';
    d.querySelector('.dX').textContent = f.dataset.text || '';
    d.querySelector('.dOk').textContent = f.dataset.title || 'Delete';
    d.showModal();
  });

  /* Star rating form (rate.php) */
  var starNames = ['', 'Very dirty', 'Dirty', 'Okay', 'Good', 'Spotless'];
  document.querySelectorAll('.s-rform').forEach(function (f) {
    var labels = f.querySelectorAll('.s-starpick label'), out = f.querySelector('.s-starlabel');
    f.querySelectorAll('.s-starpick input').forEach(function (i) {
      i.addEventListener('change', function () {
        var v = +i.value;
        labels.forEach(function (l) { l.classList.toggle('on', +l.dataset.v <= v); });
        out.textContent = v + ' out of 5 \u00b7 ' + starNames[v];
      });
    });
    var t = f.querySelector('textarea'), c = f.querySelector('.cnt');
    t.addEventListener('input', function () { c.textContent = t.value.length + ' / 500'; });
  });
})();
