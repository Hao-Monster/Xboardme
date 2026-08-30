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
    orderSearch: '',
    summary: null,
    page: 1,
    pageSize: 20,
    expandedDeviceOrders: {},
  };

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const money = (cents) => `¥${((Number(cents) || 0) / 100).toFixed(2)}`;
  const formatTime = (seconds) => seconds ? new Date(Number(seconds) * 1000).toLocaleString() : '-';
  const GIB = 1024 * 1024 * 1024;
  const formatTraffic = (bytes) => {
    const value = Math.max(0, Number(bytes) || 0);
    if (value >= GIB) return `${(value / GIB).toFixed(value % GIB === 0 ? 0 : 2)} GB`;
    if (value >= 1024 * 1024) return `${(value / 1024 / 1024).toFixed(2)} MB`;
    if (value >= 1024) return `${(value / 1024).toFixed(2)} KB`;
    return `${value} B`;
  };
  const trafficGigabytes = (bytes) => Number((Math.max(0, Number(bytes) || 0) / GIB).toFixed(3));
  const nullableLimit = (value, unit, unlimited) => value === null || Number(value) === 0 ? unlimited : `${value} ${unit}`;

  function datetimeLocalValue(seconds) {
    if (!seconds) return '';
    const date = new Date(Number(seconds) * 1000);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 19);
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

  async function downloadFile(path) {
    const token = authToken();
    if (!token) throw new Error('管理员登录已失效');
    const response = await fetch(`/api/v2/${securePath()}${path}`, {
      method: 'GET',
      headers: {
        Authorization: token,
        'Content-Language': localStorage.getItem('i18nextLng') || 'zh-CN',
      },
      credentials: 'same-origin',
      cache: 'no-store',
    });
    const contentType = response.headers?.get('Content-Type') || '';
    if (!response.ok || contentType.includes('application/json')) {
      const payload = await response.json().catch(() => null);
      throw new Error(payload?.message || `导出失败 (${response.status})`);
    }

    const disposition = response.headers?.get('Content-Disposition') || '';
    const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
    const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
    const filename = encodedName ? decodeURIComponent(encodedName) : plainName || '分销订单.xlsx';
    const objectUrl = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
  }

  async function exportOrders(button) {
    if (button) button.disabled = true;
    const params = new URLSearchParams();
    if (state.selectedDistributor) params.set('distributor_user_id', state.selectedDistributor);
    if (state.settlementStatus !== '') params.set('settlement_status', state.settlementStatus);
    if (state.orderSearch) params.set('search', state.orderSearch);
    try {
      await downloadFile(`/order/export${params.size ? `?${params}` : ''}`);
      toast('Excel 导出成功');
    } finally {
      if (button) button.disabled = false;
    }
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
    const field = checkbox.closest('.xboard-distributor-injected');
    const nameInput = field?.querySelector('[data-distributor-name]');
    const savedName = String(field?.dataset?.distributorName || '').trim();
    const distributorName = value ? savedName || String(nameInput?.value || '').trim() : '';
    if (body instanceof FormData || body instanceof URLSearchParams) {
      body.set('is_distributor', String(value));
      body.set('distributor_name', distributorName);
      return body;
    }
    if (typeof body === 'string') {
      try {
        const parsed = JSON.parse(body);
        parsed.is_distributor = value;
        parsed.distributor_name = distributorName;
        return JSON.stringify(parsed);
      } catch (_) {
        const params = new URLSearchParams(body);
        params.set('is_distributor', String(value));
        params.set('distributor_name', distributorName);
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
      if (user) {
        checkbox.checked = Boolean(user.is_distributor);
        const field = checkbox.closest('.xboard-distributor-injected');
        const distributorName = String(user.distributor_name || '').trim();
        if (field) field.dataset.distributorName = distributorName;
        const nameInput = dialog.querySelector('[data-distributor-name]');
        if (nameInput) nameInput.value = distributorName;
      }
      syncDistributorNameField(checkbox);
    });
  }

  function syncDistributorNameField(checkbox) {
    const field = checkbox?.closest('.xboard-distributor-injected');
    const inputRow = field?.querySelector('[data-distributor-name-row]');
    const readonlyRow = field?.querySelector('[data-distributor-name-readonly-row]');
    const readonlyValue = field?.querySelector('[data-distributor-name-value]');
    const input = field?.querySelector('[data-distributor-name]');
    if (!inputRow || !readonlyRow || !readonlyValue || !input) return;
    const savedName = String(field.dataset.distributorName || '').trim();
    const showReadonly = checkbox.checked && savedName !== '';
    inputRow.hidden = !checkbox.checked || showReadonly;
    readonlyRow.hidden = !showReadonly;
    readonlyValue.textContent = savedName;
    input.disabled = !checkbox.checked || showReadonly;
    input.required = checkbox.checked && !showReadonly;
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
      let savedDistributorName = '';
      if (isEdit) {
        const emailInput = [...dialog.querySelectorAll('input')]
          .find((input) => String(input.value || '').includes('@'));
        const user = emailInput ? userCache.get(String(emailInput.value).toLowerCase()) : null;
        checked = Boolean(user?.is_distributor);
        savedDistributorName = String(user?.distributor_name || '').trim();
      }

      const field = document.createElement('div');
      field.className = 'xboard-distributor-injected';
      field.dataset.distributorName = savedDistributorName;
      field.innerHTML = `<div class="xboard-distributor-injected-toggle"><div><strong>是否分销商</strong><small>Distributor account</small></div><label class="admin-dist-switch"><input type="checkbox" ${checked ? 'checked' : ''}><span></span></label></div><label class="xboard-distributor-name" data-distributor-name-row>分销商名称<input type="text" maxlength="100" data-distributor-name placeholder="请输入分销商名称"></label><div class="xboard-distributor-name-readonly" data-distributor-name-readonly-row><span>分销商名称</span><strong data-distributor-name-value></strong></div>`;

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
      syncDistributorNameField(field.querySelector('input[type="checkbox"]'));
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
      const manage = detail.is_distributor_order
        ? `<button type="button" data-native-manage-entitlement="${detail.id}">管理订阅权益</button>`
        : '';
      const customerName = detail.is_distributor_order
        ? `<strong>用户名称</strong><div>${escapeHtml(detail.customer_name || '-')}</div>`
        : '';
      field.innerHTML = `<strong>订阅链接</strong><div>${value}${manage}</div>${customerName}`;

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

  async function fetchOrders() {
    const payload = await api('/order/fetch', {
      method: 'POST',
      data: {
        current: state.page,
        pageSize: state.pageSize,
        distributor_only: true,
        distributor_user_id: state.selectedDistributor || null,
        settlement_status: state.settlementStatus === '' ? null : Number(state.settlementStatus),
        search: state.orderSearch || null,
      },
    });
    state.orders = payload?.data || [];
    state.total = Number(payload?.total || 0);
    state.summary = null;
    if (state.selectedDistributor) {
      state.summary = dataOf(await api(`/order/settlement/preview?distributor_user_id=${encodeURIComponent(state.selectedDistributor)}`));
    }
  }

  async function loadOrders() {
    await fetchOrders();
    renderOrders();
  }

  function distributorOptions(includeAll = true) {
    return `${includeAll ? '<option value="">全部分销商</option>' : '<option value="">请选择分销商</option>'}${state.distributors.map((user) => `<option value="${user.id}" ${String(user.id) === String(state.selectedDistributor) ? 'selected' : ''}>${escapeHtml(user.distributor_name || user.email)}${user.banned ? '（已封禁）' : ''}</option>`).join('')}`;
  }

  function renderBoundDevices(order) {
    const devices = Array.isArray(order.bound_devices) ? order.bound_devices : [];
    if (!devices.length) return '<span class="admin-dist-device-empty">尚未绑定</span>';
    const expanded = Boolean(state.expandedDeviceOrders[order.id]);
    const items = devices.map((device, index) => `<code class="${!expanded && index >= 3 ? 'is-device-extra' : ''}">${escapeHtml(device)}</code>`).join('');
    const toggle = devices.length > 3
      ? `<button type="button" data-admin-device-toggle="${order.id}" aria-expanded="${expanded}">${expanded ? '收起设备' : `查看全部 ${devices.length} 个`}</button>`
      : '';
    return `<div class="admin-dist-device-list ${expanded ? 'is-expanded' : ''}">${items}${toggle}</div>`;
  }

  function toggleBoundDevices(button) {
    const orderId = button.dataset.adminDeviceToggle;
    const expanded = button.getAttribute('aria-expanded') !== 'true';
    state.expandedDeviceOrders[orderId] = expanded;
    button.setAttribute('aria-expanded', String(expanded));
    const list = button.closest('.admin-dist-device-list');
    list?.classList.toggle('is-expanded', expanded);
    button.textContent = expanded ? '收起设备' : `查看全部 ${list?.querySelectorAll('code').length || 0} 个`;
  }

  function orderRows(detailAttribute = 'data-order-detail') {
    return state.orders.map((order) => {
      const remark = String(order.remark || '');
      return `<tr>
      <td><strong>${escapeHtml(order.trade_no)}</strong><small>${escapeHtml(order.order_type_label || '-')}</small>${Number(order.type) === 2 && order.subscription_trade_no ? `<small>关联原订单：${escapeHtml(order.subscription_trade_no)}</small>` : ''}</td>
      <td class="admin-dist-order-time">${formatTime(order.created_at)}</td>
      <td>${escapeHtml(order.customer_name || '-')}</td>
      <td class="admin-dist-bound-devices">${renderBoundDevices(order)}</td>
      <td class="admin-dist-used-traffic">${formatTraffic(order.used_traffic)}</td>
      <td>${escapeHtml(order.distributor_name || order.distributor_email || '-')}</td><td>${escapeHtml(order.plan?.name || '-')}</td>
      <td>${money(order.total_amount)}</td>
      <td><span class="admin-dist-status s-${order.settlement_status}">${order.settlement_status === 1 ? '已结算' : '未结算'}</span></td>
      <td><div class="admin-dist-remark-cell"><span title="${escapeHtml(remark)}">${remark ? escapeHtml(remark) : '—'}</span><button type="button" data-edit-remark="${order.id}" title="编辑备注" aria-label="编辑备注">🖊</button></div></td>
      <td><button class="admin-dist-link" ${detailAttribute}="${order.id}">详情 / 订阅链接</button></td>
    </tr>`;
    }).join('');
  }

  function openRemarkEditor(orderId) {
    const order = state.orders.find((item) => String(item.id) === String(orderId));
    if (!order) throw new Error('分销订单不存在或列表已刷新');

    let modal = document.getElementById('admin-dist-remark');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'admin-dist-remark';
      document.body.appendChild(modal);
      modal.addEventListener('click', async (event) => {
        if (event.target.closest('[data-remark-cancel]')) {
          modal.classList.remove('open');
          return;
        }
        const save = event.target.closest('[data-remark-save]');
        if (!save) return;
        const textarea = modal.querySelector('textarea');
        save.disabled = true;
        try {
          const result = dataOf(await api('/order/remark/update', {
            method: 'POST',
            data: { order_id: Number(save.dataset.remarkSave), remark: textarea?.value || '' },
          }));
          state.orders
            .filter((item) => item.subscription_trade_no === result.subscription_trade_no)
            .forEach((item) => { item.remark = result.remark; });
          modal.classList.remove('open');
          if (document.getElementById('xboard-native-distributor-orders')) renderNativeOrders();
          if (state.open && state.tab === 'orders') renderOrders();
          toast('订单备注已保存');
        } catch (error) {
          save.disabled = false;
          toast(error.message, 'error');
        }
      });
    }

    modal.innerHTML = `<div class="admin-dist-remark-backdrop"><section role="dialog" aria-modal="true" aria-labelledby="admin-dist-remark-title">
      <h2 id="admin-dist-remark-title">编辑订单备注</h2>
      <p>备注会展示给该订单所属的分销商，并随双方的 Excel 一起导出。</p>
      <textarea maxlength="500" rows="7" placeholder="请输入备注；保存空内容可清空备注">${escapeHtml(order.remark || '')}</textarea>
      <small>最多 500 个字符，支持换行</small>
      <footer><button type="button" data-remark-cancel>取消</button><button type="button" class="primary" data-remark-save="${order.id}">保存</button></footer>
    </section></div>`;
    modal.classList.add('open');
    modal.querySelector('textarea')?.focus();
  }

  function renderOrders() {
    const rows = orderRows();
    const summary = state.summary
      ? `<div class="admin-dist-summary"><span>未结算：<b>${state.summary.count}</b> 个订单，合计 <b>${money(state.summary.total_amount)}</b></span><button data-admin-dist="settle" ${state.summary.count ? '' : 'disabled'}>结算全部未结算订单</button></div>`
      : '<div class="admin-dist-summary muted">选择一个分销商后可计算并执行结算。</div>';
    renderPanel(`<div class="admin-dist-toolbar">
      <label>分销商<select id="admin-dist-distributor">${distributorOptions(true)}</select></label>
      <label>结算状态<select id="admin-dist-settlement"><option value="">全部</option><option value="0" ${state.settlementStatus === '0' ? 'selected' : ''}>未结算</option><option value="1" ${state.settlementStatus === '1' ? 'selected' : ''}>已结算</option></select></label>
      <div class="admin-dist-search"><input id="admin-dist-order-search" type="search" maxlength="512" value="${escapeHtml(state.orderSearch)}" placeholder="订单号/用户名称/订阅链接"><button data-admin-dist="search-orders">查询</button><button class="secondary" data-admin-dist="clear-order-search" ${state.orderSearch ? '' : 'disabled'}>清空</button></div>
      <button data-admin-dist="refresh">刷新</button><button data-admin-dist="export">导出 Excel</button></div>${summary}
      <div class="admin-dist-table"><table><thead><tr><th>订单号</th><th>下单时间</th><th>用户名称</th><th>已绑定设备</th><th>已用流量</th><th>分销商</th><th>套餐</th><th>原价</th><th>结算状态</th><th>备注</th><th>操作</th></tr></thead><tbody>${rows || '<tr><td colspan="11" class="empty">暂无分销订单</td></tr>'}</tbody></table></div>
      <footer class="admin-dist-pagination"><span>共 ${state.total} 个订单</span><div><button data-page="prev" ${state.page <= 1 ? 'disabled' : ''}>上一页</button><span>第 ${state.page} 页</span><button data-page="next" ${state.page * state.pageSize >= state.total ? 'disabled' : ''}>下一页</button></div></footer>`);
  }

  function renderUsers(searchResult = null) {
    const result = searchResult ? `<div class="admin-dist-user-result"><div><strong>${escapeHtml(searchResult.email)}</strong><small>ID ${searchResult.id}${searchResult.banned ? ' · 已封禁' : ''}</small><label>分销商名称<input id="admin-dist-user-name" type="text" maxlength="100" value="${escapeHtml(searchResult.distributor_name || '')}" placeholder="请输入分销商名称"></label></div><button data-user-toggle="${searchResult.id}" data-current="${searchResult.is_distributor ? 1 : 0}">${searchResult.is_distributor ? '取消分销商' : '设为分销商'}</button></div>` : '';
    renderPanel(`<div class="admin-dist-user-grid">
      <section><h2>设置已有用户</h2><p>输入完整邮箱，将普通用户设置为分销商，或取消已有分销身份。</p><div class="admin-dist-form-row"><input id="admin-dist-user-email" type="email" placeholder="user@example.com"><button data-admin-dist="search-user">查询</button></div>${result}</section>
      <section><h2>创建分销商</h2><p>创建后账号不获得普通订阅，只能进入分销页面。</p><label>分销商名称<input id="admin-dist-create-name" type="text" maxlength="100" placeholder="请输入分销商名称"></label><label>邮箱<input id="admin-dist-create-email" type="email" placeholder="dealer@example.com"></label><label>密码（留空则与邮箱相同）<input id="admin-dist-create-password" type="password" minlength="8"></label><button data-admin-dist="create-user">创建分销商</button></section>
      <section class="wide"><h2>当前分销商</h2><div class="admin-dist-user-list">${state.distributors.map((user) => `<div><span><strong>${escapeHtml(user.distributor_name || user.email)}${user.banned ? '（已封禁）' : ''}</strong><small>${escapeHtml(user.email)}</small></span><button data-user-toggle="${user.id}" data-current="1">取消分销商</button></div>`).join('') || '<p>暂无分销商</p>'}</div></section>
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
    const distributorName = document.getElementById('admin-dist-user-name')?.value.trim() || '';
    if (!current && !distributorName) throw new Error('请输入分销商名称');
    await api('/user/update', { method: 'POST', data: { id: Number(id), is_distributor: current ? 0 : 1, distributor_name: current ? '' : distributorName } });
    toast('用户身份已更新');
    await loadDistributors();
    renderUsers();
  }

  async function createUser() {
    const email = document.getElementById('admin-dist-create-email')?.value.trim().toLowerCase();
    const distributorName = document.getElementById('admin-dist-create-name')?.value.trim() || '';
    const password = document.getElementById('admin-dist-create-password')?.value || null;
    const at = email?.lastIndexOf('@') ?? -1;
    if (at <= 0 || at === email.length - 1) throw new Error('请输入有效邮箱');
    if (!distributorName) throw new Error('请输入分销商名称');
    await api('/user/generate', {
      method: 'POST',
      data: { email_prefix: email.slice(0, at), email_suffix: email.slice(at + 1), password, is_distributor: 1, distributor_name: distributorName },
    });
    toast('分销商创建成功');
    await loadDistributors();
    renderUsers();
  }

  async function settle(refresh = loadOrders) {
    if (!state.selectedDistributor || !state.summary?.count) return;
    const distributor = state.distributors.find((user) => String(user.id) === String(state.selectedDistributor));
    const distributorName = distributor?.distributor_name || distributor?.email || '';
    if (!window.confirm(`确认结算 ${distributorName} 的 ${state.summary.count} 个订单，共 ${money(state.summary.total_amount)}？`)) return;
    const result = dataOf(await api('/order/settlement/settle', { method: 'POST', data: { distributor_user_id: Number(state.selectedDistributor) } }));
    toast(`已结算 ${result.count} 个订单，共 ${money(result.total_amount)}`);
    await refresh();
  }

  function hwidDeviceRows(devices) {
    return devices.map((device) => `<tr><td><code>${escapeHtml(device.hwid)}</code></td><td>${escapeHtml([device.device_os, device.os_version].filter(Boolean).join(' ') || '-')}</td><td>${escapeHtml(device.device_model || '-')}</td><td>${escapeHtml(device.ip || '-')}</td><td>${formatTime(device.first_seen_at)}</td><td>${formatTime(device.last_seen_at)}</td><td><button type="button" data-delete-hwid="${device.id}">删除</button></td></tr>`).join('');
  }

  async function showOrderDetail(id, hwidSearch = '') {
    const order = dataOf(await api('/order/detail', { method: 'POST', data: { id: Number(id) } }));
    const entitlement = order.subscription_entitlement;
    const devices = order.hwid ? dataOf(await api(`/order/hwid/devices?order_id=${encodeURIComponent(order.id)}${hwidSearch ? `&search=${encodeURIComponent(hwidSearch)}` : ''}`)) || [] : [];
    let modal = document.getElementById('admin-dist-detail');
    if (!modal) { modal = document.createElement('div'); modal.id = 'admin-dist-detail'; document.body.appendChild(modal); }
    modal.innerHTML = `<div class="admin-dist-detail-backdrop"><section><button data-detail-close>×</button><h2>分销订单详情</h2><dl>
      <div><dt>订单号</dt><dd>${escapeHtml(order.trade_no)}</dd></div><div><dt>分销商</dt><dd>${escapeHtml(order.distributor_name || order.distributor_email || '-')}</dd></div>
      <div><dt>订单类型</dt><dd>${escapeHtml(order.order_type_label || '-')}</dd></div><div><dt>关联原订单</dt><dd>${Number(order.type) === 2 ? escapeHtml(order.subscription_trade_no || '-') : '-'}</dd></div>
      <div><dt>套餐</dt><dd>${escapeHtml(order.plan?.name || '-')}</dd></div><div><dt>原价</dt><dd>${money(order.total_amount)}</dd></div>
      <div><dt>结算状态</dt><dd>${order.settlement_status === 1 ? '已结算' : '未结算'}</dd></div><div><dt>订阅链接</dt><dd class="url">${order.subscribe_url ? `<code>${escapeHtml(order.subscribe_url)}</code><button data-copy-subscription="${escapeHtml(order.subscribe_url)}">复制</button>` : '订单未完成，暂无订阅链接'}</dd></div>
      ${Number(order.type) === 2 ? `<div><dt>续费前到期</dt><dd>${formatTime(order.entitlement_expired_at_before)}</dd></div><div><dt>续费后到期</dt><dd>${formatTime(order.entitlement_expired_at_after)}</dd></div>` : ''}
      <div><dt>用户名称</dt><dd>${escapeHtml(order.customer_name || '-')}</dd></div><div><dt>配置下发</dt><dd>${order.config_issued_at ? formatTime(order.config_issued_at) : '尚未下发'}</dd></div>
      <div><dt>接入状态</dt><dd>${order.connected_at ? `客户已经通过 ${escapeHtml(order.connected_node_name || '-')} 节点进入网络（${formatTime(order.connected_at)}）` : '等待用户开启代理 进入网络'}</dd></div>
      </dl>${entitlement ? `<div class="admin-dist-entitlement"><h3>订阅权益</h3>
        <div class="admin-dist-entitlement-readonly"><span><b>套餐</b>${escapeHtml(entitlement.plan_name || order.plan?.name || '-')}</span><span><b>已用流量</b>${formatTraffic(entitlement.used_traffic)}</span><span><b>剩余流量</b>${formatTraffic(entitlement.remaining_traffic)}</span></div>
        <div class="admin-dist-entitlement-form">
          <label>总流量<div><input id="admin-dist-entitlement-traffic" type="number" min="0" step="0.001" value="${trafficGigabytes(entitlement.transfer_enable)}"><span>GB</span></div></label>
          <label>到期时间<div><input id="admin-dist-entitlement-expired" type="datetime-local" step="1" value="${datetimeLocalValue(entitlement.expired_at)}"><button type="button" data-entitlement-permanent>永久有效</button></div></label>
          <label>限速<div><input id="admin-dist-entitlement-speed" type="number" min="0" step="1" value="${entitlement.speed_limit ?? ''}" placeholder="留空则不限速"><span>Mbps</span></div></label>
          <label>设备限制<div><input id="admin-dist-entitlement-device" type="number" min="0" step="1" value="${entitlement.device_limit ?? ''}" placeholder="留空则不限制"><span>台</span></div></label>
        </div><p class="admin-dist-entitlement-current">当前：${formatTraffic(entitlement.transfer_enable)} · ${entitlement.expired_at ? formatTime(entitlement.expired_at) : '长期有效'} · ${nullableLimit(entitlement.speed_limit, 'Mbps', '不限速')} · ${nullableLimit(entitlement.device_limit, '台', '不限制设备')}</p>
        <footer><button type="button" data-save-entitlement="${order.id}">保存订阅权益</button></footer>
      </div>` : '<p class="admin-dist-entitlement-error">该分销订单没有可用的订阅权益。</p>'}
      ${order.hwid ? `<div class="admin-dist-hwid"><h3>HWID 设备限制</h3><div class="admin-dist-hwid-settings"><label><input id="admin-dist-hwid-enabled" type="checkbox" ${order.hwid.enabled ? 'checked' : ''}> 启用 HWID</label><label>允许设备数<input id="admin-dist-hwid-limit" type="number" min="1" max="100" step="1" value="${order.hwid.limit}"></label><button type="button" data-save-hwid="${order.id}">保存 HWID 设置</button></div><p>已登记 ${order.hwid.registered_count} / ${order.hwid.limit} 台设备。降低上限不会删除已有设备，只会阻止新设备。</p><div class="admin-dist-hwid-search"><input id="admin-dist-hwid-search" type="search" maxlength="64" value="${escapeHtml(hwidSearch)}" placeholder="按 HWID 查询"><button type="button" data-search-hwid="${order.id}">查询</button><button type="button" data-clear-hwid="${order.id}" ${hwidSearch ? '' : 'disabled'}>清空</button></div><div class="admin-dist-hwid-table"><table><thead><tr><th>HWID</th><th>系统</th><th>设备型号</th><th>IP</th><th>首次登记</th><th>最近更新</th><th></th></tr></thead><tbody>${hwidDeviceRows(devices) || '<tr><td colspan="7">暂无已登记设备</td></tr>'}</tbody></table></div></div>` : ''}</section></div>`;
    modal.classList.add('open');
    modal.onclick = async (event) => {
      if (event.target.closest('[data-detail-close]')) { modal.classList.remove('open'); modal.innerHTML = ''; }
      const copy = event.target.closest('[data-copy-subscription]');
      if (copy) { await navigator.clipboard.writeText(copy.dataset.copySubscription); toast('订阅链接已复制'); }
      if (event.target.closest('[data-entitlement-permanent]')) {
        const input = modal.querySelector('#admin-dist-entitlement-expired');
        if (input) input.value = '';
      }
      const save = event.target.closest('[data-save-entitlement]');
      if (save) {
        const trafficValue = modal.querySelector('#admin-dist-entitlement-traffic')?.value.trim() || '';
        const traffic = Number(trafficValue);
        const expiredValue = modal.querySelector('#admin-dist-entitlement-expired')?.value || '';
        const speedValue = modal.querySelector('#admin-dist-entitlement-speed')?.value.trim() || '';
        const deviceValue = modal.querySelector('#admin-dist-entitlement-device')?.value.trim() || '';
        const speed = speedValue === '' ? null : Number(speedValue);
        const device = deviceValue === '' ? null : Number(deviceValue);
        if (trafficValue === '' || !Number.isFinite(traffic) || traffic < 0) { toast('请输入有效的总流量', 'error'); return; }
        if ((speed !== null && (!Number.isInteger(speed) || speed < 0)) || (device !== null && (!Number.isInteger(device) || device < 0))) {
          toast('限速和设备限制必须是非负整数或留空', 'error'); return;
        }
        const expired = expiredValue ? Math.floor(new Date(expiredValue).getTime() / 1000) : null;
        if (expiredValue && !Number.isFinite(expired)) { toast('请输入有效的到期时间', 'error'); return; }
        save.disabled = true;
        try {
          await api('/order/entitlement/update', { method: 'POST', data: {
            order_id: Number(save.dataset.saveEntitlement),
            transfer_enable: Math.round(traffic * GIB),
            expired_at: expired,
            speed_limit: speed,
            device_limit: device,
          } });
          toast('订阅权益已更新');
          await showOrderDetail(save.dataset.saveEntitlement);
        } catch (error) {
          save.disabled = false;
          toast(error.message, 'error');
        }
      }
      const saveHwid = event.target.closest('[data-save-hwid]');
      if (saveHwid) {
        const enabled = Boolean(modal.querySelector('#admin-dist-hwid-enabled')?.checked);
        const limit = Number(modal.querySelector('#admin-dist-hwid-limit')?.value);
        if (!Number.isInteger(limit) || limit < 1 || limit > 100) { toast('HWID 数量必须在 1 到 100 之间', 'error'); return; }
        saveHwid.disabled = true;
        try {
          await api('/order/hwid/update', { method: 'POST', data: { order_id: Number(saveHwid.dataset.saveHwid), enabled, limit } });
          toast('HWID 设置已保存');
          await showOrderDetail(saveHwid.dataset.saveHwid, hwidSearch);
        } catch (error) { saveHwid.disabled = false; toast(error.message, 'error'); }
      }
      const searchHwid = event.target.closest('[data-search-hwid]');
      if (searchHwid) await showOrderDetail(searchHwid.dataset.searchHwid, modal.querySelector('#admin-dist-hwid-search')?.value.trim() || '');
      const clearHwid = event.target.closest('[data-clear-hwid]');
      if (clearHwid) await showOrderDetail(clearHwid.dataset.clearHwid);
      const deleteHwid = event.target.closest('[data-delete-hwid]');
      if (deleteHwid && window.confirm('确认删除这台 HWID 设备并释放名额？')) {
        await api('/order/hwid/device/delete', { method: 'POST', data: { order_id: Number(order.id), device_id: Number(deleteHwid.dataset.deleteHwid) } });
        toast('HWID 设备已删除');
        await showOrderDetail(order.id, hwidSearch);
      }
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
      else if (action === 'export') await exportOrders(event.target.closest('[data-admin-dist]'));
      else if (action === 'settle') await settle();
      else if (action === 'search-orders') { state.orderSearch = document.getElementById('admin-dist-order-search')?.value.trim() || ''; state.page = 1; await loadOrders(); }
      else if (action === 'clear-order-search') { state.orderSearch = ''; state.page = 1; await loadOrders(); }
      else if (action === 'search-user') await searchUser();
      else if (action === 'create-user') await createUser();
      const toggle = event.target.closest('[data-user-toggle]');
      if (toggle) await toggleUser(toggle.dataset.userToggle, toggle.dataset.current === '1');
      const detail = event.target.closest('[data-order-detail]');
      if (detail) await showOrderDetail(detail.dataset.orderDetail);
      const remark = event.target.closest('[data-edit-remark]');
      if (remark) openRemarkEditor(remark.dataset.editRemark);
      const devices = event.target.closest('[data-admin-device-toggle]');
      if (devices) toggleBoundDevices(devices);
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

  function findOrderManagementTable() {
    const heading = [...document.querySelectorAll('h1,h2')]
      .find((node) => /^(订单管理|Order Management)$/i.test((node.textContent || '').trim()));
    if (!heading) return null;

    const page = heading.closest('main') || heading.parentElement?.parentElement || heading.parentElement;
    const table = page?.querySelector('table');
    if (!page || !table) return null;

    return { page, table };
  }

  function nativeSummary() {
    return state.summary
      ? `<div class="admin-dist-summary"><span>未结算：<b>${state.summary.count}</b> 个订单，合计 <b>${money(state.summary.total_amount)}</b></span><button type="button" data-native-dist="settle" ${state.summary.count ? '' : 'disabled'}>结算全部未结算订单</button></div>`
      : '<div class="admin-dist-summary muted">选择一个分销商后，将自动计算全部已完成且未结算的订单数量与金额。</div>';
  }

  function renderNativeOrders() {
    const host = document.getElementById('xboard-native-distributor-orders');
    if (!host) return;
    const rows = orderRows('data-native-order-detail');
    host.removeAttribute('aria-busy');
    host.innerHTML = `<header class="xboard-native-dist-heading"><div><h2>分销订单与结算</h2><p>按购买该订单的分销商名称筛选，并对全部已完成、未结算订单执行线下结算。</p></div><div class="xboard-native-dist-actions"><button type="button" data-native-dist="refresh">刷新</button><button type="button" data-native-dist="export">导出 Excel</button></div></header>
      <div class="admin-dist-toolbar xboard-native-dist-toolbar">
        <label>分销商<select id="native-dist-distributor">${distributorOptions(true)}</select></label>
        <label>结算状态<select id="native-dist-settlement"><option value="">全部</option><option value="0" ${state.settlementStatus === '0' ? 'selected' : ''}>未结算</option><option value="1" ${state.settlementStatus === '1' ? 'selected' : ''}>已结算</option></select></label>
        <div class="admin-dist-search"><input id="native-dist-order-search" type="search" maxlength="512" value="${escapeHtml(state.orderSearch)}" placeholder="订单号/用户名称/订阅链接"><button type="button" data-native-dist="search-orders">查询</button><button type="button" class="secondary" data-native-dist="clear-order-search" ${state.orderSearch ? '' : 'disabled'}>清空</button></div>
      </div>${nativeSummary()}
      <div class="admin-dist-table"><table><thead><tr><th>订单号</th><th>下单时间</th><th>用户名称</th><th>已绑定设备</th><th>已用流量</th><th>分销商</th><th>套餐</th><th>原价</th><th>结算状态</th><th>备注</th><th>操作</th></tr></thead><tbody>${rows || '<tr><td colspan="11" class="empty">暂无符合条件的分销订单</td></tr>'}</tbody></table></div>
      <footer class="admin-dist-pagination"><span>共 ${state.total} 个分销订单</span><div><button type="button" data-native-page="prev" ${state.page <= 1 ? 'disabled' : ''}>上一页</button><span>第 ${state.page} 页</span><button type="button" data-native-page="next" ${state.page * state.pageSize >= state.total ? 'disabled' : ''}>下一页</button></div></footer>`;
  }

  async function loadNativeOrders() {
    const host = document.getElementById('xboard-native-distributor-orders');
    if (!host) return;
    host.setAttribute('aria-busy', 'true');
    host.innerHTML = '<div class="admin-dist-loading">正在加载分销订单与结算信息…</div>';
    try {
      await loadDistributors();
      await fetchOrders();
      renderNativeOrders();
    } catch (error) {
      host.removeAttribute('aria-busy');
      host.innerHTML = `<div class="admin-dist-error">${escapeHtml(error.message)}<button type="button" data-native-dist="refresh">重试</button></div>`;
    }
  }

  async function handleNativeOrderClick(event) {
    try {
      const action = event.target.closest('[data-native-dist]')?.dataset.nativeDist;
      if (action === 'refresh') await loadNativeOrders();
      else if (action === 'export') await exportOrders(event.target.closest('[data-native-dist]'));
      else if (action === 'settle') await settle(loadNativeOrders);
      else if (action === 'search-orders') { state.orderSearch = document.getElementById('native-dist-order-search')?.value.trim() || ''; state.page = 1; await loadNativeOrders(); }
      else if (action === 'clear-order-search') { state.orderSearch = ''; state.page = 1; await loadNativeOrders(); }

      const detail = event.target.closest('[data-native-order-detail]');
      if (detail) await showOrderDetail(detail.dataset.nativeOrderDetail);
      const remark = event.target.closest('[data-edit-remark]');
      if (remark) openRemarkEditor(remark.dataset.editRemark);
      const devices = event.target.closest('[data-admin-device-toggle]');
      if (devices) toggleBoundDevices(devices);

      const page = event.target.closest('[data-native-page]');
      if (page) {
        state.page += page.dataset.nativePage === 'next' ? 1 : -1;
        await loadNativeOrders();
      }
    } catch (error) {
      toast(error.message, 'error');
    }
  }

  async function handleNativeOrderChange(event) {
    if (event.target.id === 'native-dist-distributor') {
      state.selectedDistributor = event.target.value;
    } else if (event.target.id === 'native-dist-settlement') {
      state.settlementStatus = event.target.value;
    } else {
      return;
    }
    state.page = 1;
    await loadNativeOrders();
  }

  function mountNativeOrderManagement() {
    if (!authToken() || document.getElementById('xboard-native-distributor-orders')) return;
    const context = findOrderManagementTable();
    if (!context) return;

    const host = document.createElement('section');
    host.id = 'xboard-native-distributor-orders';
    host.className = 'xboard-native-distributor-orders';
    host.addEventListener('click', handleNativeOrderClick);
    host.addEventListener('change', (event) => {
      handleNativeOrderChange(event).catch((error) => toast(error.message, 'error'));
    });

    const tableContainer = context.table.parentElement || context.table;
    tableContainer.parentElement?.insertBefore(host, tableContainer);
    loadNativeOrders();
  }

  installRequestBridge();
  document.addEventListener('click', async (event) => {
    const manage = event.target.closest('[data-native-manage-entitlement]');
    if (manage) {
      try { await showOrderDetail(manage.dataset.nativeManageEntitlement); }
      catch (error) { toast(error.message, 'error'); }
      return;
    }
    const copy = event.target.closest('[data-native-copy-subscription]');
    if (!copy) return;
    try {
      await navigator.clipboard.writeText(copy.dataset.nativeCopySubscription);
      toast('订阅链接已复制');
    } catch (_) {
      toast('复制失败，请手动复制', 'error');
    }
  });
  document.addEventListener('change', (event) => {
    if (event.target.matches?.('.xboard-distributor-injected input[type="checkbox"]')) {
      syncDistributorNameField(event.target);
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || !['admin-dist-order-search', 'native-dist-order-search'].includes(event.target.id)) return;
    event.preventDefault();
    state.orderSearch = event.target.value.trim();
    state.page = 1;
    const refresh = event.target.id === 'native-dist-order-search' ? loadNativeOrders : loadOrders;
    refresh().catch((error) => toast(error.message, 'error'));
  });
  const observer = new MutationObserver(() => { mount(); mountNativeOrderManagement(); injectDistributorFields(); injectOrderSubscriptionLinks(); });
  observer.observe(document.documentElement, { childList: true, subtree: true });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { mount(); mountNativeOrderManagement(); });
  else { mount(); mountNativeOrderManagement(); }
  setInterval(() => { mount(); mountNativeOrderManagement(); }, 1000);
})();
