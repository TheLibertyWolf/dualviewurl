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
  let currentUrl = '';
  let dragging = false;
  let suppressSync = false;

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
    return `/proxy.php?${new URLSearchParams({ url, ua: selectedDevice(panel).ua, theme: data.theme.value })}`;
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
  panels.forEach(panel => {
    const data = panelData(panel);
    data.frame.addEventListener('load', () => { data.loading.hidden = true; status.textContent = `Affiché · ${new URL(currentUrl).hostname}`; });
    $('.reload', panel).addEventListener('click', () => currentUrl && loadPanel(panel, currentUrl));
    data.device.addEventListener('change', () => { applyDevice(panel); if (currentUrl) loadPanel(panel, currentUrl); savePreferences(); });
    data.theme.addEventListener('change', () => { if (currentUrl) loadPanel(panel, currentUrl); savePreferences(); });
  });
  divider.addEventListener('pointerdown', event => { dragging = true; divider.classList.add('dragging'); divider.setPointerCapture(event.pointerId); splitFromPointer(event); });
  divider.addEventListener('pointermove', event => { if (dragging) splitFromPointer(event); });
  divider.addEventListener('pointerup', event => { dragging = false; divider.classList.remove('dragging'); divider.releasePointerCapture(event.pointerId); });
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
  sync.addEventListener('change', savePreferences);
  remember.addEventListener('change', () => {
    if (remember.checked) savePreferences(); else localStorage.removeItem('duoviewurl.preferences');
  });
  window.addEventListener('message', event => {
    if (event.origin !== 'null' || event.data?.source !== 'duoviewurl') return;
    const sourcePanel = panels.find(panel => panelData(panel).frame.contentWindow === event.source);
    if (!sourcePanel || event.data.type !== 'navigate' || suppressSync) return;
    try {
      const target = normalizeUrl(event.data.url);
      currentUrl = target; input.value = target; updateShareUrl();
      if (sync.checked) {
        suppressSync = true;
        panels.filter(panel => panel !== sourcePanel).forEach(panel => loadPanel(panel, target));
        setTimeout(() => { suppressSync = false; }, 500);
      }
      savePreferences();
    } catch { /* Le serveur validera également toutes les destinations. */ }
  });
  $('#help').addEventListener('click', () => $('#help-dialog').showModal());
  new ResizeObserver(updateDimensions).observe(workspace);
  window.addEventListener('resize', updateDimensions);

  panels.forEach(applyDevice); restorePreferences(); updateDimensions();
  const sharedUrl = new URLSearchParams(location.search).get('url');
  const initial = sharedUrl || (remember.checked ? input.value : '');
  if (initial) { try { load(normalizeUrl(initial)); } catch { $('#url-error').textContent = 'L’URL partagée est invalide.'; } }
})();
