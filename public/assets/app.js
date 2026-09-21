(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  const panels = [...document.querySelectorAll('.panel')];
  const form = $('#url-form');
  const input = $('#url-input');
  const workspace = $('#workspace');
  const divider = $('#divider');
  const sync = $('#sync');
  const remember = $('#remember');
  const status = $('#status');
  const proxyOrigin = new URL(document.documentElement.dataset.proxyOrigin || location.origin, location.href).origin;
  const proxyTicket = document.documentElement.dataset.proxyTicket || '';
  const csrf = document.documentElement.dataset.csrf || '';
  const isAdmin = document.documentElement.dataset.isAdmin === '1';
  let currentUrl = '';
  let dragging = false;
  let suppressSync = false;
  let pendingSessionPanel = null;

  const panelData = panel => ({
    frame: $('iframe', panel), loading: $('.loading-state', panel), empty: $('.empty-state', panel),
    device: $('.device-select', panel), theme: $('.theme-select', panel), dimensions: $('.dimensions', panel),
  });

  function selectedDevice(panel) {
    const select = panelData(panel).device;
    const option = select.selectedOptions[0];
    return { value: select.value, width: Number(option?.dataset.width || 0), ua: option?.dataset.ua || 'desktop' };
  }

  function applyDevice(panel) {
    const data = panelData(panel);
    const device = selectedDevice(panel);
    data.frame.style.width = device.width ? `${device.width}px` : '100%';
    data.frame.style.minWidth = device.width ? `${device.width}px` : '0';
    updateDimensions();
  }

  function normalizeUrl(value) {
    let candidate = value.trim();
    if (!candidate) throw new Error('Saisissez une URL.');
    if (!/^[a-z][a-z\d+.-]*:\/\//i.test(candidate)) candidate = `https://${candidate}`;
    const url = new URL(candidate);
    if (!['http:', 'https:'].includes(url.protocol)) throw new Error('Utilisez une URL HTTP ou HTTPS.');
    if (url.username || url.password) throw new Error('Les identifiants dans l’URL ne sont pas autorisés.');
    return url.href;
  }

  function proxyUrl(url, panel) {
    const data = panelData(panel);
    return `${proxyOrigin}/bridge.php?${new URLSearchParams({ ticket: proxyTicket, url, ua: selectedDevice(panel).ua, theme: data.theme.value })}`;
  }

  async function api(action, options = {}) {
    const [actionName, query = ''] = action.split('?', 2);
    const settings = { credentials: 'same-origin', ...options };
    if (settings.body && typeof settings.body !== 'string') {
      settings.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...(settings.headers || {}) };
      settings.body = JSON.stringify({ ...settings.body, csrf });
    }
    const response = await fetch(`/api.php?action=${encodeURIComponent(actionName)}${query ? `&${query}` : ''}`, settings);
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || 'La requête a échoué.');
    return payload;
  }

  function recordHistory(url) {
    api('history_add', { method: 'POST', body: { url } }).catch(() => {});
  }

  function loadPanel(panel, url) {
    const data = panelData(panel);
    data.empty.hidden = true;
    data.loading.hidden = false;
    data.frame.hidden = false;
    data.frame.src = proxyUrl(url, panel);
  }

  function load(url, sourcePanel = null) {
    currentUrl = url;
    input.value = url;
    $('#url-error').textContent = '';
    panels.forEach(panel => {
      if (!sourcePanel || sync.checked || panel === sourcePanel) loadPanel(panel, url);
    });
    status.textContent = `Chargement · ${new URL(url).hostname}`;
    updateShareUrl();
    savePreferences();
    if (!sourcePanel) recordHistory(url);
  }

  function updateShareUrl() {
    const url = new URL(location.href);
    currentUrl ? url.searchParams.set('url', currentUrl) : url.searchParams.delete('url');
    history.replaceState(null, '', url);
  }

  function savePreferences() {
    if (!remember.checked) return;
    const preferences = {
      url: currentUrl, split: getSplit(), sync: sync.checked, remember: true,
      panels: panels.map(panel => ({ device: panelData(panel).device.value, theme: panelData(panel).theme.value })),
    };
    localStorage.setItem('duoviewurl.preferences', JSON.stringify(preferences));
  }

  function restorePreferences() {
    try {
      const saved = JSON.parse(localStorage.getItem('duoviewurl.preferences') || 'null');
      if (!saved?.remember) return;
      remember.checked = true;
      sync.checked = saved.sync !== false;
      setSplit(Number(saved.split) || 62);
      saved.panels?.forEach((settings, index) => {
        const data = panels[index] && panelData(panels[index]);
        if (data && [...data.device.options].some(option => option.value === settings.device)) data.device.value = settings.device;
        if (data && ['system', 'light', 'dark'].includes(settings.theme)) data.theme.value = settings.theme;
        if (data) applyDevice(panels[index]);
      });
      if (!new URLSearchParams(location.search).has('url') && saved.url) input.value = saved.url;
    } catch { localStorage.removeItem('duoviewurl.preferences'); }
  }

  function getSplit() { return parseFloat(getComputedStyle(workspace).getPropertyValue('--split')) || 62; }
  function setSplit(value) {
    const split = Math.max(25, Math.min(75, value));
    workspace.style.setProperty('--split', `${split}%`);
    divider.setAttribute('aria-valuenow', String(Math.round(split)));
    updateDimensions();
    savePreferences();
  }
  function splitFromPointer(event) {
    const box = workspace.getBoundingClientRect();
    setSplit(((event.clientX - box.left) / box.width) * 100);
  }
  function stopDragging(event) {
    if (!dragging) return;
    dragging = false;
    document.body.classList.remove('is-resizing');
    divider.classList.remove('dragging');
    if (event?.pointerId !== undefined && divider.hasPointerCapture(event.pointerId)) {
      divider.releasePointerCapture(event.pointerId);
    }
  }
  function updateDimensions() {
    panels.forEach(panel => {
      const wrap = $('.viewport-wrap', panel);
      const device = selectedDevice(panel);
      const width = device.width || wrap.clientWidth;
      panelData(panel).dimensions.value = `${Math.round(width)} × ${Math.round(wrap.clientHeight)} px`;
    });
  }
  function toast(message) {
    const node = $('#toast'); node.textContent = message; node.hidden = false;
    clearTimeout(toast.timer); toast.timer = setTimeout(() => { node.hidden = true; }, 2600);
  }

  form.addEventListener('submit', event => {
    event.preventDefault();
    try { load(normalizeUrl(input.value)); } catch (error) { $('#url-error').textContent = error.message; input.focus(); }
  });
  let suggestionTimer;
  input.addEventListener('input', () => {
    clearTimeout(suggestionTimer);
    suggestionTimer = setTimeout(async () => {
      try {
        const payload = await api(`history?q=${encodeURIComponent(input.value.trim())}`);
        const datalist = $('#history-suggestions');
        datalist.replaceChildren(...payload.history.map(item => {
          const option = document.createElement('option'); option.value = item.url; return option;
        }));
      } catch { /* Les suggestions ne doivent jamais bloquer la saisie. */ }
    }, 140);
  });
  panels.forEach(panel => {
    const data = panelData(panel);
    data.frame.addEventListener('load', () => { data.loading.hidden = true; status.textContent = `Affiché · ${new URL(currentUrl).hostname}`; });
    $('.reload', panel).addEventListener('click', () => currentUrl && loadPanel(panel, currentUrl));
    data.device.addEventListener('change', () => { applyDevice(panel); if (currentUrl) loadPanel(panel, currentUrl); savePreferences(); });
    data.theme.addEventListener('change', () => { if (currentUrl) loadPanel(panel, currentUrl); savePreferences(); });
  });
  divider.addEventListener('pointerdown', event => {
    if (event.button !== 0 || !event.isPrimary) return;
    event.preventDefault();
    dragging = true;
    document.body.classList.add('is-resizing');
    divider.classList.add('dragging');
    divider.setPointerCapture(event.pointerId);
    splitFromPointer(event);
  });
  divider.addEventListener('pointermove', event => { if (dragging) splitFromPointer(event); });
  divider.addEventListener('pointerup', stopDragging);
  divider.addEventListener('pointercancel', stopDragging);
  divider.addEventListener('lostpointercapture', stopDragging);
  document.addEventListener('pointerup', stopDragging, true);
  window.addEventListener('blur', stopDragging);
  divider.addEventListener('keydown', event => {
    const step = event.shiftKey ? 5 : 1;
    if (event.key === 'ArrowLeft') { event.preventDefault(); setSplit(getSplit() - step); }
    if (event.key === 'ArrowRight') { event.preventDefault(); setSplit(getSplit() + step); }
    if (event.key === 'Home') { event.preventDefault(); setSplit(25); }
    if (event.key === 'End') { event.preventDefault(); setSplit(75); }
  });
  $('#swap').addEventListener('click', () => {
    const first = panels[0], second = panels[1];
    const a = { device: panelData(first).device.value, theme: panelData(first).theme.value };
    panelData(first).device.value = panelData(second).device.value; panelData(first).theme.value = panelData(second).theme.value;
    panelData(second).device.value = a.device; panelData(second).theme.value = a.theme;
    panels.forEach(applyDevice);
    if (currentUrl) panels.forEach(panel => loadPanel(panel, currentUrl));
    toast('Configurations inversées'); savePreferences();
  });
  $('#share').addEventListener('click', async () => {
    if (!currentUrl) return toast('Affichez d’abord une URL.');
    try { await navigator.clipboard.writeText(location.href); toast('Lien de partage copié'); }
    catch { toast('Copiez l’adresse affichée par le navigateur.'); }
  });
  $('#clear-session').addEventListener('click', async () => {
    try {
      const response = await fetch(`${proxyOrigin}/session.php`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'X-Duoviewurl-Action': 'clear-session' },
      });
      if (!response.ok) throw new Error('session');
      toast('Session distante effacée');
      if (currentUrl) panels.forEach(panel => loadPanel(panel, currentUrl));
    } catch { toast('Impossible d’effacer la session.'); }
  });
  $('#clear-history').addEventListener('click', async () => {
    if (!confirm('Supprimer tout votre historique Duoviewurl ?')) return;
    try {
      await api('history_clear', { method: 'POST', body: {} });
      $('#history-suggestions').replaceChildren();
      toast('Historique supprimé');
    } catch (error) { toast(error.message); }
  });
  $('#logout').addEventListener('click', async () => {
    try { await api('logout', { method: 'POST', body: {} }); } catch { /* La redirection termine aussi la session locale. */ }
    location.assign('/login.php');
  });
  sync.addEventListener('change', savePreferences);
  remember.addEventListener('change', () => {
    if (remember.checked) savePreferences(); else localStorage.removeItem('duoviewurl.preferences');
  });
  window.addEventListener('message', event => {
    if (event.origin !== proxyOrigin || event.data?.source !== 'duoviewurl') return;
    const sourcePanel = panels.find(panel => panelData(panel).frame.contentWindow === event.source);
    if (!sourcePanel) return;
    if (event.data.type === 'session-submit') {
      pendingSessionPanel = sourcePanel;
      status.textContent = 'Connexion en cours…';
      return;
    }
    if (event.data.type === 'ready' && pendingSessionPanel === sourcePanel) {
      pendingSessionPanel = null;
      try {
        const authenticatedUrl = normalizeUrl(event.data.url);
        currentUrl = authenticatedUrl;
        input.value = authenticatedUrl;
        updateShareUrl();
        panels.filter(panel => panel !== sourcePanel).forEach(panel => loadPanel(panel, authenticatedUrl));
        status.textContent = 'Session connectée dans les deux vues';
        savePreferences();
      } catch { /* La réponse affichée reste disponible dans la vue source. */ }
      return;
    }
    if (event.data.type !== 'navigate' || suppressSync) return;
    try {
      const target = normalizeUrl(event.data.url);
      currentUrl = target; input.value = target; updateShareUrl();
      recordHistory(target);
      if (sync.checked) {
        suppressSync = true;
        panels.filter(panel => panel !== sourcePanel).forEach(panel => loadPanel(panel, target));
        setTimeout(() => { suppressSync = false; }, 500);
      }
      savePreferences();
    } catch { /* Le serveur validera également toutes les destinations. */ }
  });
  $('#help').addEventListener('click', () => $('#help-dialog').showModal());

  const adminButton = $('#admin-open');
  adminButton.hidden = !isAdmin;
  const adminDialog = $('#admin-dialog');
  const userForm = $('#user-form');
  let adminUsers = [];
  function showAdminError(message = '') {
    const node = $('#admin-error'); node.textContent = message; node.hidden = !message;
  }
  function editUser(user = null) {
    userForm.hidden = false;
    $('#user-id').value = user?.id || '';
    $('#user-username').value = user?.username || '';
    $('#user-password').value = '';
    $('#user-password').required = !user;
    $('#user-admin').checked = Boolean(user?.is_admin ?? false);
    $('#user-active').checked = Boolean(user?.is_active ?? true);
    $('#user-username').focus();
  }
  function renderUsers() {
    const body = $('#users-list'); body.replaceChildren();
    adminUsers.forEach(user => {
      const row = document.createElement('tr');
      const username = document.createElement('td'); username.textContent = user.username;
      const role = document.createElement('td'); role.textContent = Number(user.is_admin) ? 'Admin' : 'Utilisateur';
      const state = document.createElement('td'); state.textContent = Number(user.is_active) ? 'Actif' : 'Désactivé';
      const last = document.createElement('td'); last.textContent = user.last_login_at ? new Date(Number(user.last_login_at) * 1000).toLocaleString('fr-FR') : 'Jamais';
      const actions = document.createElement('td'); actions.className = 'row-actions';
      const edit = document.createElement('button'); edit.type = 'button'; edit.textContent = 'Modifier'; edit.addEventListener('click', () => editUser(user));
      const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Supprimer'; remove.disabled = user.username === document.documentElement.dataset.username;
      remove.addEventListener('click', async () => {
        if (!confirm(`Supprimer le compte ${user.username} et son historique ?`)) return;
        try { await api('admin_user_delete', { method: 'POST', body: { id: user.id } }); await loadAdmin(); }
        catch (error) { showAdminError(error.message); }
      });
      actions.append(edit, remove); row.append(username, role, state, last, actions); body.append(row);
    });
  }
  async function loadAdmin() {
    showAdminError();
    const [usersPayload, settingsPayload] = await Promise.all([api('admin_users'), api('admin_settings')]);
    adminUsers = usersPayload.users; renderUsers();
    const turnstile = settingsPayload.turnstile;
    $('#turnstile-enabled').checked = Boolean(turnstile.enabled);
    $('#turnstile-site-key').value = turnstile.site_key || '';
    $('#turnstile-secret-key').value = '';
    $('#turnstile-secret-status').textContent = turnstile.secret_configured ? 'Une clé secrète est enregistrée.' : 'Aucune clé secrète enregistrée.';
  }
  adminButton.addEventListener('click', async () => {
    adminDialog.showModal();
    try { await loadAdmin(); } catch (error) { showAdminError(error.message); }
  });
  $('#new-user').addEventListener('click', () => editUser());
  $('#cancel-user').addEventListener('click', () => { userForm.hidden = true; showAdminError(); });
  userForm.addEventListener('submit', async event => {
    event.preventDefault(); showAdminError();
    try {
      await api('admin_user_save', { method: 'POST', body: {
        id: $('#user-id').value || 0, username: $('#user-username').value, password: $('#user-password').value,
        is_admin: $('#user-admin').checked, is_active: $('#user-active').checked,
      } });
      userForm.hidden = true; await loadAdmin(); toast('Utilisateur enregistré');
    } catch (error) { showAdminError(error.message); }
  });
  $('#turnstile-form').addEventListener('submit', async event => {
    event.preventDefault(); showAdminError();
    try {
      await api('admin_settings_save', { method: 'POST', body: {
        enabled: $('#turnstile-enabled').checked,
        site_key: $('#turnstile-site-key').value,
        secret_key: $('#turnstile-secret-key').value,
      } });
      await loadAdmin(); toast('Réglages Turnstile enregistrés');
    } catch (error) { showAdminError(error.message); }
  });
  new ResizeObserver(updateDimensions).observe(workspace);
  window.addEventListener('resize', updateDimensions);

  panels.forEach(applyDevice); restorePreferences(); updateDimensions();
  const sharedUrl = new URLSearchParams(location.search).get('url');
  const initial = sharedUrl || (remember.checked ? input.value : '');
  if (initial) { try { load(normalizeUrl(initial)); } catch { $('#url-error').textContent = 'L’URL partagée est invalide.'; } }
})();
