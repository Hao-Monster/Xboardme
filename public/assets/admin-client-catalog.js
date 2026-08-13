(function () {
  'use strict';

  if (window.__xboardAdminClientCatalog) return;
  window.__xboardAdminClientCatalog = true;

  const TOKEN_KEY = 'XBOARD_ACCESS_TOKEN';
  const ROUTE_PATH = '/config/knowledge';
  const PLATFORM_LABELS = {
    android: 'Android', ios: 'iPhone / iPad', windows: 'Windows', macos: 'macOS', linux: 'Linux',
  };
  const ACTIONS = [
    ['direct', '直接下载', '留空时继续使用现有官方商店或 GitHub 最新安装包。'],
    ['qr', '扫码下载', '留空时复用直接下载地址；填写后二维码将指向此链接。'],
    ['cloud', '网盘下载', '留空时客户端下载页隐藏此按钮。'],
    ['tutorial', '使用教程', '支持 HTTPS 外链或以 / 开头的站内知识库地址；留空时隐藏。'],
  ];
  const state = { clients: [], selected: '', loading: false, saving: false, error: '' };
  let menuAnchor = null;

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  }

  function authToken() {
    try {
      const stored = JSON.parse(localStorage.getItem(TOKEN_KEY) || 'null');
      if (!stored?.value || (stored.expire && stored.expire <= Date.now())) return null;
      return stored.value;
    } catch (_) { return null; }
  }

  function securePath() {
    return String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, '');
  }

  async function request(path, options = {}) {
    const token = authToken();
    if (!token) throw new Error('管理员登录已失效，请重新登录。');
    const response = await fetch(`/api/v2/${securePath()}${path}`, {
      method: options.method || 'GET',
      headers: {
        Authorization: token,
        'Content-Type': 'application/json',
        'Content-Language': localStorage.getItem('i18nextLng') || 'zh-CN',
      },
      body: options.data ? JSON.stringify(options.data) : undefined,
      credentials: 'same-origin', cache: 'no-store',
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || payload?.status === 'fail') {
      const validation = payload?.errors && Object.values(payload.errors).flat()[0];
      throw new Error(validation || payload?.message || `请求失败 (${response.status})`);
    }
    return payload?.data ?? payload;
  }

  function activeRoute() {
    const [path, query = ''] = (location.hash || '').replace(/^#/, '').split('?');
    return path === ROUTE_PATH && new URLSearchParams(query).get('client-catalog') === '1';
  }

  function setRoute(active) {
    location.hash = active ? `#${ROUTE_PATH}?client-catalog=1` : `#${ROUTE_PATH}`;
  }

  function findKnowledgeMenu() {
    return [...document.querySelectorAll('a,button')].find((element) => {
      if (element.classList.contains('xboard-client-catalog-nav')) return false;
      const href = element.getAttribute('href') || '';
      const text = String(element.textContent || '').replace(/\s+/g, ' ').trim();
      return /(?:^|#)\/config\/knowledge(?:[/?]|$)/.test(href)
        || /^(知识库管理|Knowledge Management)$/i.test(text);
    }) || null;
  }

  function replaceMenuLabel(item) {
    const labels = [...item.querySelectorAll('*')].filter((node) => {
      const text = String(node.textContent || '').replace(/\s+/g, ' ').trim();
      return /^(知识库管理|Knowledge Management)$/i.test(text)
        && ![...node.children].some((child) => String(child.textContent || '').trim());
    });
    if (labels.length) labels[labels.length - 1].textContent = '客户端管理';
    else item.textContent = '▣  客户端管理';
  }

  function installMenu() {
    const knowledge = findKnowledgeMenu();
    if (!knowledge || knowledge.parentElement?.querySelector(':scope > .xboard-client-catalog-nav')) return;
    const item = knowledge.cloneNode(true);
    item.classList.add('xboard-client-catalog-nav');
    item.setAttribute('href', `#${ROUTE_PATH}?client-catalog=1`);
    item.setAttribute('title', '配置各客户端、各系统的四个按钮链接');
    item.removeAttribute('aria-current');
    replaceMenuLabel(item);
    item.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      setRoute(true);
    });
    knowledge.insertAdjacentElement('afterend', item);
    menuAnchor = item;
    syncMenuState();
  }

  function syncMenuState() {
    document.querySelectorAll('.xboard-client-catalog-nav').forEach((item) => {
      item.classList.toggle('xboard-client-catalog-nav-active', activeRoute());
      if (activeRoute()) item.setAttribute('aria-current', 'page');
      else item.removeAttribute('aria-current');
    });
  }

  function findSidebarRight() {
    const anchor = menuAnchor || findKnowledgeMenu();
    let current = anchor;
    while (current && current !== document.body) {
      const rect = current.getBoundingClientRect?.();
      if (rect && rect.left < 80 && rect.width >= 150 && rect.width <= 420 && rect.height > innerHeight * 0.65) {
        return Math.max(0, Math.round(rect.right));
      }
      current = current.parentElement;
    }
    return innerWidth < 900 ? 0 : 240;
  }

  function ensureRoot() {
    let root = document.getElementById('admin-client-catalog-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'admin-client-catalog-root';
      document.body.appendChild(root);
      root.addEventListener('click', handleClick);
      root.addEventListener('input', handleInput);
    }
    root.style.setProperty('--acc-sidebar-right', `${findSidebarRight()}px`);
    return root;
  }

  function selectedClient() {
    return state.clients.find((client) => client.id === state.selected) || state.clients[0] || null;
  }

  function clientTabs() {
    return state.clients.map((client) => `<button type="button" data-acc-client="${escapeHtml(client.id)}" class="${client.id === selectedClient()?.id ? 'active' : ''}">
      <span>${escapeHtml(client.name)}</span><small>${client.platforms.length} 个系统</small>
    </button>`).join('');
  }

  function platformSections(client) {
    return client.platforms.map((item) => `<section class="acc-platform">
      <header><h3>${escapeHtml(PLATFORM_LABELS[item.platform] || item.platform)}</h3><code>${escapeHtml(item.platform)}</code></header>
      <div class="acc-fields">${ACTIONS.map(([action, label, help]) => `<label>
        <span>${label}</span>
        <input type="${action === 'tutorial' ? 'text' : 'url'}" maxlength="2048" autocomplete="off" spellcheck="false"
          data-acc-client-id="${escapeHtml(client.id)}" data-acc-platform="${escapeHtml(item.platform)}" data-acc-action="${action}"
          value="${escapeHtml(item.links[action] || '')}" placeholder="${action === 'tutorial' ? 'https://… 或 /guide/…' : 'https://…'}">
        <small>${help}</small>
      </label>`).join('')}</div>
    </section>`).join('');
  }

  function render() {
    const root = ensureRoot();
    root.classList.toggle('open', activeRoute());
    syncMenuState();
    if (!activeRoute()) return;

    if (state.loading) {
      root.innerHTML = '<div class="acc-loading">正在加载客户端配置…</div>';
      return;
    }
    if (state.error && !state.clients.length) {
      root.innerHTML = `<div class="acc-loading error"><p>${escapeHtml(state.error)}</p><button type="button" data-acc-action="reload">重新加载</button></div>`;
      return;
    }
    const client = selectedClient();
    root.innerHTML = `<div class="acc-page">
      <header class="acc-heading"><div><p>CLIENT CATALOG</p><h1>客户端管理</h1><span>按客户端和系统分别配置直接下载、扫码下载、网盘下载与使用教程。</span></div>
        <div><button type="button" class="acc-close" data-acc-action="close">返回知识库</button><button type="button" class="acc-save" data-acc-action="save" ${state.saving ? 'disabled' : ''}>${state.saving ? '正在保存…' : '保存全部配置'}</button></div>
      </header>
      ${state.error ? `<div class="acc-alert">${escapeHtml(state.error)}</div>` : ''}
      <div class="acc-layout"><nav class="acc-clients" aria-label="客户端列表">${clientTabs()}</nav>
        <main><div class="acc-client-title"><div><h2>${escapeHtml(client?.name || '')}</h2><span>${escapeHtml(client?.core || '')}</span></div><p>空白字段将使用默认行为或隐藏对应按钮。</p></div>${client ? platformSections(client) : ''}</main>
      </div>
    </div>`;
  }

  async function load() {
    state.loading = true; state.error = ''; render();
    try {
      const data = await request('/client-catalog');
      state.clients = data?.clients || [];
      if (!state.clients.some((client) => client.id === state.selected)) state.selected = state.clients[0]?.id || '';
    } catch (error) { state.error = error.message; }
    finally { state.loading = false; render(); }
  }

  function linksPayload() {
    const links = {};
    state.clients.forEach((client) => client.platforms.forEach((item) => {
      links[client.id] ||= {};
      links[client.id][item.platform] = { ...item.links };
    }));
    return links;
  }

  async function save() {
    state.saving = true; state.error = ''; render();
    try {
      const data = await request('/client-catalog/save', { method: 'POST', data: { links: linksPayload() } });
      state.clients = data?.clients || state.clients;
      showToast('客户端按钮配置已保存。');
    } catch (error) { state.error = error.message; }
    finally { state.saving = false; render(); }
  }

  function showToast(message) {
    let root = document.getElementById('acc-toasts');
    if (!root) { root = document.createElement('div'); root.id = 'acc-toasts'; document.body.appendChild(root); }
    const item = document.createElement('div'); item.textContent = message; root.appendChild(item);
    setTimeout(() => item.remove(), 3200);
  }

  function handleInput(event) {
    const input = event.target.closest('[data-acc-client-id]');
    if (!input) return;
    const client = state.clients.find((entry) => entry.id === input.dataset.accClientId);
    const platform = client?.platforms.find((entry) => entry.platform === input.dataset.accPlatform);
    if (platform && ACTIONS.some(([action]) => action === input.dataset.accAction)) {
      platform.links[input.dataset.accAction] = input.value;
    }
  }

  function handleClick(event) {
    const client = event.target.closest('[data-acc-client]');
    if (client) { state.selected = client.dataset.accClient; render(); return; }
    const action = event.target.closest('[data-acc-action]')?.dataset.accAction;
    if (action === 'close') setRoute(false);
    else if (action === 'save') save();
    else if (action === 'reload') load();
  }

  let scheduled = false;
  function enhance() {
    scheduled = false;
    installMenu();
    const root = ensureRoot();
    if (activeRoute() && !state.clients.length && !state.loading) load();
    else if (root.classList.contains('open') !== activeRoute()) render();
    else syncMenuState();
  }
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(enhance, 60);
  }

  new MutationObserver((mutations) => {
    const root = document.getElementById('admin-client-catalog-root');
    if (root && mutations.every((mutation) => root.contains(mutation.target))) return;
    schedule();
  }).observe(document.documentElement, { childList: true, subtree: true });
  addEventListener('hashchange', schedule);
  addEventListener('resize', schedule);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', schedule);
  else schedule();
})();
