/* ============================================================
   CRYPTEX — JavaScript v2 (multi-destinataires + tags)
   ============================================================ */

/* ── Gestion multi-destinataires (tags) ─────────────────────── */
(function () {
  const wrapper   = document.getElementById('tags-wrapper');
  const container = document.getElementById('tags-container');
  const input     = document.getElementById('recipient_search');
  const listEl    = document.getElementById('autocomplete_list');
  const jsonField = document.getElementById('recipients_json');
  const counter   = document.getElementById('recipients-counter');

  if (!input) return;

  let debounce, focusIdx = -1;
  let recipients = []; // [{email, name}]

  function updateJson() {
    jsonField.value = JSON.stringify(recipients);
    const n = recipients.length;
    if (counter) {
      counter.textContent = n === 0 ? '0 destinataire sélectionné'
        : n === 1 ? '1 destinataire sélectionné'
        : n + ' destinataires sélectionnés';
      counter.className = 'recipients-counter' + (n > 0 ? ' has-items' : '');
    }
  }

  function addRecipient(u) {
    if (recipients.some(r => r.email === u.email)) {
      closeList();
      input.value = '';
      return;
    }
    recipients.push({ email: u.email, name: u.name });
    renderTags();
    updateJson();
    input.value = '';
    input.focus();
    closeList();
  }

  function removeRecipient(email) {
    recipients = recipients.filter(r => r.email !== email);
    renderTags();
    updateJson();
  }

  function renderTags() {
    container.innerHTML = '';
    recipients.forEach(r => {
      const initials = (r.name || r.email).split(' ').map(p => p[0]).join('').slice(0, 2).toUpperCase();
      const chip = document.createElement('div');
      chip.className = 'tag-chip';
      chip.innerHTML = `
        <span class="avatar-xs">${esc(initials)}</span>
        <span title="${esc(r.email)}">${esc(r.name || r.email)}</span>
        <button type="button" title="Retirer" onclick=""></button>`;
      chip.querySelector('button').addEventListener('click', e => {
        e.stopPropagation();
        removeRecipient(r.email);
      });
      container.appendChild(chip);
    });
  }

  input.addEventListener('input', function () {
    clearTimeout(debounce);
    const q = this.value.trim();
    if (q.length < 2) { closeList(); return; }
    debounce = setTimeout(() => fetchUsers(q), 280);
  });

  async function fetchUsers(q) {
    try {
      const resp = await fetch('api/search_users.php?q=' + encodeURIComponent(q), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      renderList(await resp.json());
    } catch { closeList(); }
  }

  function renderList(users) {
    listEl.innerHTML = '';
    focusIdx = -1;

    if (!users.length) {
      listEl.innerHTML = '<div class="autocomplete-item" style="color:#999;cursor:default;">Aucun résultat</div>';
      listEl.classList.add('open');
      return;
    }

    users.forEach(u => {
      const already = recipients.some(r => r.email === u.email);
      const initials = (u.name || '?').split(' ').map(p => p[0]).join('').slice(0, 2).toUpperCase();
      const div = document.createElement('div');
      div.className = 'autocomplete-item' + (already ? ' focused' : '');
      div.innerHTML = `
        <div class="avatar">${esc(initials)}</div>
        <div class="info">
          <div class="name">${esc(u.name)} ${already ? '✓' : ''}</div>
          <div class="meta">${esc(u.email)}${u.department ? ' · ' + esc(u.department) : ''}</div>
        </div>`;
      div.addEventListener('mousedown', e => {
        e.preventDefault();
        if (!already) addRecipient(u);
      });
      listEl.appendChild(div);
    });
    listEl.classList.add('open');
  }

  function closeList() {
    listEl.classList.remove('open');
    listEl.innerHTML = '';
    focusIdx = -1;
  }

  input.addEventListener('keydown', e => {
    const items = listEl.querySelectorAll('.autocomplete-item');
    if (e.key === 'ArrowDown') {
      focusIdx = Math.min(focusIdx + 1, items.length - 1);
      items.forEach((el, i) => el.classList.toggle('focused', i === focusIdx));
      e.preventDefault();
    } else if (e.key === 'ArrowUp') {
      focusIdx = Math.max(focusIdx - 1, 0);
      items.forEach((el, i) => el.classList.toggle('focused', i === focusIdx));
      e.preventDefault();
    } else if (e.key === 'Enter' && focusIdx >= 0) {
      items[focusIdx].dispatchEvent(new Event('mousedown'));
      e.preventDefault();
    } else if (e.key === 'Escape') {
      closeList();
    } else if (e.key === 'Backspace' && input.value === '' && recipients.length) {
      removeRecipient(recipients[recipients.length - 1].email);
    }
  });

  document.addEventListener('click', e => {
    if (!wrapper.contains(e.target) && !listEl.contains(e.target)) closeList();
  });

  updateJson();

  function esc(s) {
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
})();

/* ── Validation formulaire ───────────────────────────────────── */
(function () {
  const form = document.getElementById('deposit-form');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    const rj      = document.getElementById('recipients_json');
    const content = document.getElementById('secret_content');
    const btn     = document.getElementById('submit-btn');

    let recips = [];
    try { recips = JSON.parse(rj.value || '[]'); } catch {}

    if (!recips.length) {
      e.preventDefault();
      showError('Sélectionnez au moins un destinataire.');
      return;
    }
    if (!content || !content.value.trim()) {
      e.preventDefault();
      showError('Le contenu du message est obligatoire.');
      content.focus();
      return;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Envoi en cours…';
  });

  function showError(msg) {
    let el = document.getElementById('form-error');
    if (!el) {
      el = document.createElement('div');
      el.id = 'form-error';
      el.className = 'alert alert-danger';
      form.prepend(el);
    }
    el.textContent = '⚠ ' + msg;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
})();

/* ── Copier dans le presse-papier ────────────────────────────── */
function copyToClipboard(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    const orig = btn.innerHTML;
    btn.innerHTML = '✅ Copié !';
    setTimeout(() => btn.innerHTML = orig, 2000);
  });
}

/* ── Compte à rebours expiration ─────────────────────────────── */
(function () {
  const el = document.getElementById('countdown');
  if (!el) return;
  const expires = new Date(el.dataset.expires.replace(' ', 'T') + 'Z');
  function update() {
    const diff = Math.max(0, Math.floor((expires - Date.now()) / 1000));
    if (!diff) { el.textContent = '⌛ Expiré'; return; }
    const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
    el.textContent = '⏱ Expire dans ' + (h > 0 ? `${h}h ${String(m).padStart(2,'0')}m` : `${m}m ${String(s).padStart(2,'0')}s`);
    el.className = 'countdown' + (diff < 3600 ? ' warning' : '');
  }
  update();
  setInterval(update, 1000);
})();

/* ── Compteur caractères textarea ────────────────────────────── */
(function () {
  const ta = document.getElementById('secret_content');
  const ct = document.getElementById('char-count');
  const MAX = parseInt(document.body.dataset.maxLen || '10000');
  if (!ta || !ct) return;
  ta.addEventListener('input', () => {
    const l = ta.value.length;
    ct.textContent = l + ' / ' + MAX;
    ct.style.color = l > MAX * .9 ? '#ef476f' : '#6b7a90';
  });
})();
