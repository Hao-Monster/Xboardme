(function () {
  'use strict';

  if (window.XBoardClientCenter) return;

  const TOKEN_KEY = 'VUE_NAIVE_ACCESS_TOKEN';
  const API_BASE = `${window.location.origin}${(window.routerBase || '/').replace(/\/$/, '')}/api/v1`;
  const PLATFORM_LABELS = { android: 'Android', ios: 'iPhone / iPad', windows: 'Windows', macos: 'macOS', linux: 'Linux' };
  const mounted = new WeakMap();

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  }

  function authToken() {
    try {
      const stored = JSON.parse(localStorage.getItem(TOKEN_KEY) || 'null');
      if (!stored || !stored.value || (stored.expire && stored.expire <= Date.now())) return null;
      return stored.value;
    } catch (_) {
      return null;
    }
  }

  async function api(path) {
    const token = authToken();
    if (!token) throw new Error('登录状态已失效，请重新登录');
    const response = await fetch(`${API_BASE}${path}${path.includes('?') ? '&' : '?'}t=${Date.now()}`, {
      headers: { Authorization: token, 'Content-Language': 'zh-CN' },
      credentials: 'same-origin', cache: 'no-store',
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.status === 'fail') {
      throw new Error(payload.message || payload.data || `请求失败 (${response.status})`);
    }
    return payload.data !== undefined ? payload.data : payload;
  }

  function platformOptions(downloads) {
    return downloads.map((download) => `<option value="${escapeHtml(download.platform)}" data-url="${escapeHtml(download.download_url)}" data-cloud-url="${escapeHtml(download.cloud_url || '')}" data-tutorial-url="${escapeHtml(download.tutorial_url || '')}">${escapeHtml(PLATFORM_LABELS[download.platform] || download.platform)}</option>`).join('');
  }

  function actionButtons(download) {
    return `<button type="button" class="xcc-secondary" data-direct-download>直接下载</button>
      <button type="button" class="xcc-primary" data-qr-download>扫码下载</button>
      ${download && download.cloudUrl ? '<button type="button" class="xcc-secondary" data-cloud-download>网盘下载</button>' : ''}
      ${download && download.tutorialUrl ? '<button type="button" class="xcc-secondary" data-tutorial>使用教程</button>' : ''}`;
  }

  function renderCards(state) {
    const visible = state.clients.filter((client) => state.filter === 'all' || client.downloads.some((item) => item.platform === state.filter));
    return visible.map((client) => {
      const downloads = state.filter === 'all' ? client.downloads : client.downloads.filter((item) => item.platform === state.filter);
      const selected = downloads[0] ? {
        cloudUrl: downloads[0].cloud_url || '', tutorialUrl: downloads[0].tutorial_url || '',
      } : null;
      const initial = Array.from(client.name || '?')[0] || '?';
      return `<article class="xcc-card ${client.featured ? 'is-featured' : ''}" data-client-card="${escapeHtml(client.id)}">
        <div class="xcc-card-head"><span class="xcc-logo">${escapeHtml(initial)}</span><div><div class="xcc-badges"><span>${escapeHtml(client.core)}</span><b>✓ HWID</b>${client.featured ? '<em>推荐</em>' : ''}</div><h2>${escapeHtml(client.name)}</h2></div></div>
        <p>${escapeHtml(client.description)}</p>
        <div class="xcc-card-actions">
          <select aria-label="选择下载平台" data-client-platform>${platformOptions(downloads)}</select>
          <div class="xcc-buttons" data-client-buttons>${actionButtons(selected)}</div>
        </div>
      </article>`;
    }).join('') || '<div class="xcc-empty">该平台暂无支持 HWID 的客户端</div>';
  }

  function pageTemplate(state) {
    const filters = [['all', '全部'], ...Object.entries(PLATFORM_LABELS)];
    return `<section class="xcc-page">
      <header class="xcc-heading"><div><span class="xcc-eyebrow">HWID CLIENTS</span><h1>客户端下载</h1><p>仅收录支持 HWID 设备识别的客户端。Android 优先提供 GitHub 最新安装包，其次使用 Google Play。</p></div><span class="xcc-security">✓ 下载地址经过服务端白名单校验</span></header>
      <nav class="xcc-filters" aria-label="平台筛选">${filters.map(([key, label]) => `<button type="button" data-platform-filter="${key}" class="${state.filter === key ? 'active' : ''}">${escapeHtml(label)}</button>`).join('')}</nav>
      <div class="xcc-grid">${renderCards(state)}</div>
      <p class="xcc-footnote">安装包来自各客户端官方发布渠道。GitHub 客户端会自动匹配最新 Release 中的真实安装文件。</p>
    </section><div class="xcc-modal-root"></div>`;
  }

  function selectedDownload(card) {
    const select = card && card.querySelector('[data-client-platform]');
    const option = select && select.options[select.selectedIndex];
    return option ? {
      platform: option.value,
      url: option.dataset.url,
      cloudUrl: option.dataset.cloudUrl || '',
      tutorialUrl: option.dataset.tutorialUrl || '',
    } : null;
  }

  async function openQr(target, clientId, platform) {
    const root = target.querySelector('.xcc-modal-root');
    root.innerHTML = '<div class="xcc-backdrop"><div class="xcc-modal"><button type="button" class="xcc-modal-x" data-close-qr>×</button><div class="xcc-qr-loading">正在生成下载二维码…</div></div></div>';
    try {
      const result = await api(`/user/client-catalog/qr?client=${encodeURIComponent(clientId)}&platform=${encodeURIComponent(platform)}`);
      const client = mounted.get(target).clients.find((item) => item.id === clientId);
      root.innerHTML = `<div class="xcc-backdrop"><div class="xcc-modal"><button type="button" class="xcc-modal-x" data-close-qr>×</button><span class="xcc-eyebrow">${escapeHtml(PLATFORM_LABELS[platform] || platform)}</span><h2>扫码下载 ${escapeHtml(client ? client.name : '')}</h2><p>未单独配置扫码链接时，将使用直接下载地址。</p><div class="xcc-qr"><img src="${escapeHtml(result.qr_code)}" alt="${escapeHtml(client ? client.name : '')} 下载二维码"></div><a class="xcc-primary xcc-modal-download" href="${escapeHtml(result.download_url)}" target="_blank" rel="noopener noreferrer">当前设备打开下载链接</a></div></div>`;
    } catch (error) {
      root.innerHTML = `<div class="xcc-backdrop"><div class="xcc-modal"><button type="button" class="xcc-modal-x" data-close-qr>×</button><div class="xcc-error">${escapeHtml(error.message)}</div></div></div>`;
    }
  }

  function bind(target) {
    target.addEventListener('click', (event) => {
      const filter = event.target.closest('[data-platform-filter]');
      if (filter) {
        const state = mounted.get(target); state.filter = filter.dataset.platformFilter;
        target.innerHTML = pageTemplate(state); return;
      }
      if (event.target.closest('[data-close-qr]') || (event.target.classList.contains('xcc-backdrop'))) {
        target.querySelector('.xcc-modal-root').innerHTML = ''; return;
      }
      const card = event.target.closest('[data-client-card]');
      const selected = selectedDownload(card);
      const direct = event.target.closest('[data-direct-download]');
      if (direct && selected) { window.open(selected.url, '_blank', 'noopener,noreferrer'); return; }
      const qr = event.target.closest('[data-qr-download]');
      if (qr && selected) { openQr(target, card.dataset.clientCard, selected.platform); return; }
      const cloud = event.target.closest('[data-cloud-download]');
      if (cloud && selected?.cloudUrl) { window.open(selected.cloudUrl, '_blank', 'noopener,noreferrer'); return; }
      const tutorial = event.target.closest('[data-tutorial]');
      if (tutorial && selected?.tutorialUrl) window.open(selected.tutorialUrl, '_blank', 'noopener,noreferrer');
    });
    target.addEventListener('change', (event) => {
      if (!event.target.matches('[data-client-platform]')) return;
      const card = event.target.closest('[data-client-card]');
      const buttons = card && card.querySelector('[data-client-buttons]');
      if (buttons) buttons.innerHTML = actionButtons(selectedDownload(card));
    });
  }

  async function mount(target) {
    if (!target) return;
    target.innerHTML = '<div class="xcc-loading">正在加载客户端目录…</div>';
    const state = { clients: [], filter: 'all' };
    mounted.set(target, state);
    bind(target);
    try {
      state.clients = await api('/user/client-catalog');
      target.innerHTML = pageTemplate(state);
    } catch (error) {
      target.innerHTML = `<div class="xcc-error">${escapeHtml(error.message)}</div>`;
    }
  }

  function normalRouteActive() {
    return !document.documentElement.classList.contains('distributor-mode')
      && window.location.hash.includes('/knowledge')
      && new URLSearchParams((window.location.hash.split('?')[1] || '')).get('client-center') === '1';
  }

  function ensureNormalPage() {
    let page = document.getElementById('xboard-client-center-normal');
    if (!normalRouteActive()) { if (page) page.remove(); return; }
    if (!page) {
      page = document.createElement('div'); page.id = 'xboard-client-center-normal'; page.className = 'xcc-normal-host';
      document.body.appendChild(page); mount(page);
    }
  }

  function ensureNormalNav() {
    if (document.documentElement.classList.contains('distributor-mode')) return;
    const candidates = Array.from(document.querySelectorAll('a, button')).filter((element) => (element.textContent || '').trim() === '使用文档');
    candidates.forEach((candidate) => {
      const parent = candidate.parentElement;
      if (!parent || parent.querySelector(':scope > .xcc-normal-nav')) return;
      const item = candidate.cloneNode(true);
      item.classList.add('xcc-normal-nav'); item.removeAttribute('href'); item.removeAttribute('data-nav');
      const textNodes = Array.from(item.querySelectorAll('*')).filter((node) => (node.textContent || '').trim() === '使用文档');
      if (textNodes.length) textNodes[textNodes.length - 1].textContent = '客户端下载';
      else item.textContent = '▦  客户端下载';
      item.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); window.location.hash = '#/knowledge?client-center=1'; });
      candidate.insertAdjacentElement('afterend', item);
    });
  }

  let scheduled = false;
  function enhance() { scheduled = false; ensureNormalNav(); ensureNormalPage(); }
  function schedule() { if (scheduled) return; scheduled = true; window.setTimeout(enhance, 60); }
  new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('hashchange', schedule);
  window.XBoardClientCenter = { mount, refreshNormal: schedule };
  schedule();
})();
