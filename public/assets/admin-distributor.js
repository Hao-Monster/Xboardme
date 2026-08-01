(function () {
  'use strict';

  if (window.__xboardAdminDistributor) return;
  window.__xboardAdminDistributor = true;

  const TOKEN_KEY = 'XBOARD_ACCESS_TOKEN';
  const userCache = new Map();
  const orderDetailCache = new Map();
  const state = {
    open: false,
    tab: 'orders',
    distributors: [],
    orders: [],
    total: 0,
    selectedDistributor: '',
    settlementStatus: '',
    summary: null,
    page: 1,
    pageSize: 20,
  };

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const money = (cents) => `¥${((Number(cents) || 0) / 100).toFixed(2)}`;
  const formatTime = (seconds) => seconds ? new Date(Number(seconds) * 1000).toLocaleString() : '-';

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

  async function api(path, options = {}) {
    const token = authToken();
    if (!token) throw new Error('管理员登录已失效');
    const method = options.method || 'GET';
    const response = await fetch(`/api/v2/${securePath()}${path}`, {
      method,
      headers: {
        Authorization: token,
        'Content-Type': 'application/json',
        'Content-Language': localStorage.getItem('i18nextLng') || 'zh-CN',
      },
      body: options.data ? JSON.stringify(options.data) : undefined,
      credentials: 'same-origin',
      cache: 'no-store',
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || payload?.status === 'fail') {
      const error = new Error(payload?.message || `请求失败 (${response.status})`);
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function dataOf(payload) {
    return payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
  }

  function toast(message, type = 'ok') {
    let root = document.getElementById('admin-dist-toasts');
    if (!root) {
      root = document.createElement('div');
      root.id = 'admin-dist-toasts';
      document.body.appendChild(root);
    }
    const item = document.createElement('div');
    item.className = `admin-dist-toast ${type}`;
    item.textContent = message;
    root.appendChild(item);
    setTimeout(() => item.remove(), 3200);
  }

  function installRequestBridge() {
    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
      this.__adminDistUrl = String(url || '');
      this.__adminDistMethod = method;
      return originalOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function (body) {
      const url = this.__adminDistUrl || '';
      if (/\/user\/(update|generate)(?:\?|$)/.test(url)) {
        body = appendDistributorField(body);
      }
      this.addEventListener('load', function () {
        if (/\/user\/fetch(?:\?|$)/.test(url)) rememberUsers(this.responseText);
        if (/\/user\/getUserInfoById(?:\?|$)/.test(url)) rememberUsers(this.responseText);
        if (/\/order\/detail(?:\?|$)/.test(url)) rememberOrderDetail(this.responseText);
      });
      return originalSend.call(this, body);
    };

    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init = {}) {
      const url = typeof input === 'string' ? input : input?.url || '';
      if (/\/user\/(update|generate)(?:\?|$)/.test(url) && init.body) {
        init = { ...init, body: appendDistributorField(init.body) };
      }
      return originalFetch(input, init).then((response) => {
        if (/\/user\/fetch(?:\?|$)/.test(url)) {
          response.clone().text().then(rememberUsers).catch(() => {});
        }
        if (/\/user\/getUserInfoById(?:\?|$)/.test(url)) {
          response.clone().text().then(rememberUsers).catch(() => {});
        }
        if (/\/order\/detail(?:\?|$)/.test(url)) {
          response.clone().text().then(rememberOrderDetail).catch(() => {});
        }
        return response;
      });
    };
  }

  function activeInjectedSwitch() {
    return [...document.querySelectorAll('.xboard-distributor-injected input[type="checkbox"]')]
      .find((input) => input.offsetParent !== null);
  }

  function appendDistributorField(body) {
    const checkbox = activeInjectedSwitch();
    if (!checkbox) return body;
    const value = checkbox.checked ? 1 : 0;
    if (body instanceof FormData || body instanceof URLSearchParams) {
      body.set('is_distributor', String(value));
      return body;
    }
    if (typeof body === 'string') {
      try {
        const parsed = JSON.parse(body);
        parsed.is_distributor = value;
        return JSON.stringify(parsed);
      } catch (_) {
        const params = new URLSearchParams(body);
        params.set('is_distributor', String(value));
        return params.toString();
      }
    }
    return body;
  }

  function rememberUsers(responseText) {
    try {
      const payload = typeof responseText === 'string' ? JSON.parse(responseText) : responseText;
      const users = Array.isArray(payload?.data) ? payload.data : payload?.data?.email ? [payload.data] : [];
      users.forEach((user) => {
        if (user?.email) userCache.set(String(user.email).toLowerCase(), user);
      });
      setTimeout(syncInjectedDistributorSwitches, 0);
    } catch (_) { /* not a user list response */ }
  }

  function rememberOrderDetail(responseText) {
    try {
      const payload = typeof responseText === 'string' ? JSON.parse(responseText) : responseText;
      const order = payload?.data;
      if (order?.trade_no) {
        orderDetailCache.set(String(order.trade_no), order);
        setTimeout(injectOrderSubscriptionLinks, 0);
      }
    } catch (_) { /* not an order detail response */ }
  }

  function syncInjectedDistributorSwitches() {
    document.querySelectorAll('[role="dialog"], [data-radix-dialog-content], .n-modal').forEach((dialog) => {
      const checkbox = dialog.querySelector('.xboard-distributor-injected input[type="checkbox"]');
      if (!checkbox) return;
      const emailInput = [...dialog.querySelectorAll('input')]
        .find((input) => String(input.value || '').includes('@'));
      const user = emailInput ? userCache.get(String(emailInput.value).toLowerCase()) : null;
      if (user) checkbox.checked = Boolean(user.is_distributor);
    });
  }

  function injectDistributorFields() {
    const dialogs = document.querySelectorAll('[role="dialog"], [data-radix-dialog-content], .n-modal');
    dialogs.forEach((dialog) => {
      if (dialog.querySelector('.xboard-distributor-injected')) return;
      const text = dialog.textContent || '';
      const isCreate = /创建用户|Create User/i.test(text);
      const isEdit = /是否员工|Is Staff|Staff/i.test(text) && /用户|User/i.test(text);
      if (!isCreate && !isEdit) return;

      let checked = false;
      if (isEdit) {
        const emailInput = [...dialog.querySelectorAll('input')]
          .find((input) => String(input.value || '').includes('@'));
        const user = emailInput ? userCache.get(String(emailInput.value).toLowerCase()) : null;
        checked = Boolean(user?.is_distributor);
      }

      const field = document.createElement('div');
      field.className = 'xboard-distributor-injected';
      field.innerHTML = `<div><strong>是否分销商</strong><small>Distributor account</small></div><label class="admin-dist-switch"><input type="checkbox" ${checked ? 'checked' : ''}><span></span></label>`;

      const staffNode = [...dialog.querySelectorAll('label,div,span')]
        .find((node) => /^(是否员工|Is Staff|Staff)$/i.test((node.textContent || '').trim()));
      const anchor = staffNode?.closest('.space-y-2, .n-form-item, [data-slot="form-item"]') || staffNode?.parentElement;
      if (anchor?.parentElement) anchor.insertAdjacentElement('afterend', field);
      else {
        const form = dialog.querySelector('form') || dialog;
        const footer = [...form.children].find((node) => /取消|确认|提交|Cancel|Confirm|Submit/i.test(node.textContent || ''));
        if (footer) form.insertBefore(field, footer);
        else form.appendChild(field);
      }
    });
  }

  function injectOrderSubscriptionLinks() {
    const dialogs = document.querySelectorAll('[role="dialog"], [data-radix-dialog-content], .n-modal');
    dialogs.forEach((dialog) => {
      if (dialog.querySelector('.xboard-order-subscription-injected')) return;
      const text = dialog.textContent || '';
      const detail = [...orderDetailCache.values()].find((order) => text.includes(String(order.trade_no)));
      if (!detail) return;

      const field = document.createElement('section');
      field.className = 'xboard-order-subscription-injected';
      const value = detail.subscribe_url
        ? `<code>${escapeHtml(detail.subscribe_url)}</code><button type="button" data-native-copy-subscription="${escapeHtml(detail.subscribe_url)}">复制</button>`
        : '<span>订单未完成，暂无订阅链接</span>';
      field.innerHTML = `<strong>订阅链接</strong><div>${value}</div>`;

      const scrollArea = dialog.querySelector('[data-radix-scroll-area-viewport], .overflow-y-auto, .n-scrollbar-content') || dialog;
      const footer = [...scrollArea.children].find((node) => /关闭|取消|确认|Close|Cancel|Confirm/i.test(node.textContent || ''));
      if (footer) scrollArea.insertBefore(field, footer);
      else scrollArea.appendChild(field);
    });
  }

  function mount() {
    if (!authToken() || document.getElementById('admin-dist-entry')) return;
    const entry = document.createElement('button');
    entry.id = 'admin-dist-entry';
    entry.innerHTML = '<span>分</span><b>分销管理</b>';
    entry.title = '分销商账号、订单与结算';
    document.body.appendChild(entry);

    const root = document.createElement('div');
    root.id = 'admin-dist-root';
    document.body.appendChild(root);

    entry.addEventListener('click', () => openPanel('orders'));
    root.addEventListener('click', handleClick);
    root.addEventListener('change', handleChange);
  }

  function panelShell(content) {
    return `<div class="admin-dist-backdrop"><section class="admin-dist-panel">
      <header><div><h1>分销管理</h1><p>分销商账号、独立订阅订单与线下结算</p></div><button data-admin-dist="close">×</button></header>
      <nav><button data-tab="orders" class="${state.tab === 'orders' ? 'active' : ''}">分销订单</button><button data-tab="users" class="${state.tab === 'users' ? 'active' : ''}">分销商账号</button></nav>
      <main>${content}</main>
    </section></div>`;
  }

  function renderPanel(content) {
    const root = document.getElementById('admin-dist-root');
    if (!root) return;
    root.classList.toggle('open', state.open);
    root.innerHTML = state.open ? panelShell(content) : '';
  }

  async function openPanel(tab) {
    state.open = true;
    state.tab = tab;
    renderPanel('<div class="admin-dist-loading">加载中…</div>');
    try {
      await loadDistributors();
      if (tab === 'orders') await loadOrders();
      else renderUsers();
    } catch (error) {
      renderPanel(`<div class="admin-dist-error">${escapeHtml(error.message)}</div>`);
    }
  }

  async function loadDistributors() {
    state.distributors = dataOf(await api('/user/distributor/options')) || [];
  }

  async function loadOrders() {
    const payload = await api('/order/fetch', {
      method: 'POST',
      data: {
        current: state.page,
        pageSize: state.pageSize,
        distributor_only: true,
        distributor_user_id: state.selectedDistributor || null,
        settlement_status: state.settlementStatus === '' ? null : Number(state.settlementStatus),
      },
    });
    state.orders = payload?.data || [];
    state.total = Number(payload?.total || 0);
    state.summary = null;
    if (state.selectedDistributor) {
      state.summary = dataOf(await api(`/order/settlement/preview?distributor_user_id=${encodeURIComponent(state.selectedDistributor)}`));
    }
    renderOrders();
  }

  function distributorOptions(includeAll = true) {
    return `${includeAll ? '<option value="">全部分销商</option>' : '<option value="">请选择分销商</option>'}${state.distributors.map((user) => `<option value="${user.id}" ${String(user.id) === String(state.selectedDistributor) ? 'selected' : ''}>${escapeHtml(user.email)}${user.banned ? '（已封禁）' : ''}</option>`).join('')}`;
  }

  function renderOrders() {
    const rows = state.orders.map((order) => `<tr>
      <td><strong>${escapeHtml(order.trade_no)}</strong><small>${formatTime(order.created_at)}</small></td>
      <td>${escapeHtml(order.distributor_email || '-')}</td><td>${escapeHtml(order.plan?.name || '-')}</td>
      <td>${money(order.total_amount)}</td><td>${order.delivery_status === 0 ? '待领取' : order.delivery_status === 1 ? '已领取' : '已关闭'}</td>
      <td><span class="admin-dist-status s-${order.settlement_status}">${order.settlement_status === 1 ? '已结算' : '未结算'}</span></td>
      <td><button class="admin-dist-link" data-order-detail="${order.id}">详情 / 订阅链接</button></td>
    </tr>`).join('');
    const summary = state.summary
      ? `<div class="admin-dist-summary"><span>未结算：<b>${state.summary.count}</b> 个订单，合计 <b>${money(state.summary.total_amount)}</b></span><button data-admin-dist="settle" ${state.summary.count ? '' : 'disabled'}>结算全部未结算订单</button></div>`
      : '<div class="admin-dist-summary muted">选择一个分销商后可计算并执行结算。</div>';
    renderPanel(`<div class="admin-dist-toolbar">
      <label>分销商<select id="admin-dist-distributor">${distributorOptions(true)}</select></label>
      <label>结算状态<select id="admin-dist-settlement"><option value="">全部</option><option value="0" ${state.settlementStatus === '0' ? 'selected' : ''}>未结算</option><option value="1" ${state.settlementStatus === '1' ? 'selected' : ''}>已结算</option></select></label>
      <button data-admin-dist="refresh">刷新</button></div>${summary}
      <div class="admin-dist-table"><table><thead><tr><th>订单号</th><th>分销商</th><th>套餐</th><th>原价</th><th>交付</th><th>结算状态</th><th></th></tr></thead><tbody>${rows || '<tr><td colspan="7" class="empty">暂无分销订单</td></tr>'}</tbody></table></div>
      <footer class="admin-dist-pagination"><span>共 ${state.total} 个订单</span><div><button data-page="prev" ${state.page <= 1 ? 'disabled' : ''}>上一页</button><span>第 ${state.page} 页</span><button data-page="next" ${state.page * state.pageSize >= state.total ? 'disabled' : ''}>下一页</button></div></footer>`);
  }

  function renderUsers(searchResult = null) {
    const result = searchResult ? `<div class="admin-dist-user-result"><div><strong>${escapeHtml(searchResult.email)}</strong><small>ID ${searchResult.id}${searchResult.banned ? ' · 已封禁' : ''}</small></div><button data-user-toggle="${searchResult.id}" data-current="${searchResult.is_distributor ? 1 : 0}">${searchResult.is_distributor ? '取消分销商' : '设为分销商'}</button></div>` : '';
    renderPanel(`<div class="admin-dist-user-grid">
      <section><h2>设置已有用户</h2><p>输入完整邮箱，将普通用户设置为分销商，或取消已有分销身份。</p><div class="admin-dist-form-row"><input id="admin-dist-user-email" type="email" placeholder="user@example.com"><button data-admin-dist="search-user">查询</button></div>${result}</section>
      <section><h2>创建分销商</h2><p>创建后账号不获得普通订阅，只能进入分销页面。</p><label>邮箱<input id="admin-dist-create-email" type="email" placeholder="dealer@example.com"></label><label>密码（留空则与邮箱相同）<input id="admin-dist-create-password" type="password" minlength="8"></label><button data-admin-dist="create-user">创建分销商</button></section>
      <section class="wide"><h2>当前分销商</h2><div class="admin-dist-user-list">${state.distributors.map((user) => `<div><span>${escapeHtml(user.email)}${user.banned ? '（已封禁）' : ''}</span><button data-user-toggle="${user.id}" data-current="1">取消分销商</button></div>`).join('') || '<p>暂无分销商</p>'}</div></section>
    </div>`);
  }

  async function searchUser() {
    const email = document.getElementById('admin-dist-user-email')?.value.trim().toLowerCase();
    if (!email) return;
    const payload = await api('/user/fetch', { method: 'POST', data: { current: 1, pageSize: 20, filter: [{ id: 'email', value: email }] } });
    const user = (payload?.data || []).find((item) => String(item.email).toLowerCase() === email);
    if (!user) throw new Error('没有找到该用户');
    renderUsers(user);
  }

  async function toggleUser(id, current) {
    await api('/user/update', { method: 'POST', data: { id: Number(id), is_distributor: current ? 0 : 1 } });
    toast('用户身份已更新');
    await loadDistributors();
    renderUsers();
  }

  async function createUser() {
    const email = document.getElementById('admin-dist-create-email')?.value.trim().toLowerCase();
    const password = document.getElementById('admin-dist-create-password')?.value || null;
    const at = email?.lastIndexOf('@') ?? -1;
    if (at <= 0 || at === email.length - 1) throw new Error('请输入有效邮箱');
    await api('/user/generate', {
      method: 'POST',
      data: { email_prefix: email.slice(0, at), email_suffix: email.slice(at + 1), password, is_distributor: 1 },
    });
    toast('分销商创建成功');
    await loadDistributors();
    renderUsers();
  }

  async function settle() {
    if (!state.selectedDistributor || !state.summary?.count) return;
    const email = state.distributors.find((user) => String(user.id) === String(state.selectedDistributor))?.email || '';
    if (!window.confirm(`确认结算 ${email} 的 ${state.summary.count} 个订单，共 ${money(state.summary.total_amount)}？`)) return;
    const result = dataOf(await api('/order/settlement/settle', { method: 'POST', data: { distributor_user_id: Number(state.selectedDistributor) } }));
    toast(`已结算 ${result.count} 个订单，共 ${money(result.total_amount)}`);
    await loadOrders();
  }

  async function showOrderDetail(id) {
    const order = dataOf(await api('/order/detail', { method: 'POST', data: { id: Number(id) } }));
    let modal = document.getElementById('admin-dist-detail');
    if (!modal) { modal = document.createElement('div'); modal.id = 'admin-dist-detail'; document.body.appendChild(modal); }
    modal.innerHTML = `<div class="admin-dist-detail-backdrop"><section><button data-detail-close>×</button><h2>分销订单详情</h2><dl>
      <div><dt>订单号</dt><dd>${escapeHtml(order.trade_no)}</dd></div><div><dt>分销商</dt><dd>${escapeHtml(order.distributor_email || '-')}</dd></div>
      <div><dt>套餐</dt><dd>${escapeHtml(order.plan?.name || '-')}</dd></div><div><dt>原价</dt><dd>${money(order.total_amount)}</dd></div>
      <div><dt>结算状态</dt><dd>${order.settlement_status === 1 ? '已结算' : '未结算'}</dd></div><div><dt>订阅链接</dt><dd class="url">${order.subscribe_url ? `<code>${escapeHtml(order.subscribe_url)}</code><button data-copy-subscription="${escapeHtml(order.subscribe_url)}">复制</button>` : '订单未完成，暂无订阅链接'}</dd></div>
      </dl></section></div>`;
    modal.classList.add('open');
    modal.onclick = async (event) => {
      if (event.target.closest('[data-detail-close]')) { modal.classList.remove('open'); modal.innerHTML = ''; }
      const copy = event.target.closest('[data-copy-subscription]');
      if (copy) { await navigator.clipboard.writeText(copy.dataset.copySubscription); toast('订阅链接已复制'); }
    };
  }

  async function handleClick(event) {
    const close = event.target.closest('[data-admin-dist="close"]');
    if (close) { state.open = false; renderPanel(''); return; }
    const tab = event.target.closest('[data-tab]');
    if (tab) { await openPanel(tab.dataset.tab); return; }
    const action = event.target.closest('[data-admin-dist]')?.dataset.adminDist;
    try {
      if (action === 'refresh') await loadOrders();
      else if (action === 'settle') await settle();
      else if (action === 'search-user') await searchUser();
      else if (action === 'create-user') await createUser();
      const toggle = event.target.closest('[data-user-toggle]');
      if (toggle) await toggleUser(toggle.dataset.userToggle, toggle.dataset.current === '1');
      const detail = event.target.closest('[data-order-detail]');
      if (detail) await showOrderDetail(detail.dataset.orderDetail);
      const page = event.target.closest('[data-page]');
      if (page) { state.page += page.dataset.page === 'next' ? 1 : -1; await loadOrders(); }
    } catch (error) { toast(error.message, 'error'); }
  }

  async function handleChange(event) {
    if (event.target.id === 'admin-dist-distributor') {
      state.selectedDistributor = event.target.value;
      state.page = 1;
      try { await loadOrders(); } catch (e) { toast(e.message, 'error'); }
    } else if (event.target.id === 'admin-dist-settlement') {
      state.settlementStatus = event.target.value;
      state.page = 1;
      try { await loadOrders(); } catch (e) { toast(e.message, 'error'); }
    }
  }

  installRequestBridge();
  document.addEventListener('click', async (event) => {
    const copy = event.target.closest('[data-native-copy-subscription]');
    if (!copy) return;
    try {
      await navigator.clipboard.writeText(copy.dataset.nativeCopySubscription);
      toast('订阅链接已复制');
    } catch (_) {
      toast('复制失败，请手动复制', 'error');
    }
  });
  const observer = new MutationObserver(() => { mount(); injectDistributorFields(); injectOrderSubscriptionLinks(); });
  observer.observe(document.documentElement, { childList: true, subtree: true });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
  setInterval(mount, 1000);
})();
