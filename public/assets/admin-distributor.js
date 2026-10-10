(function () {
  'use strict';

  if (window.__xboardAdminDistributor) return;
  window.__xboardAdminDistributor = true;

  const TOKEN_KEY = 'XBOARD_ACCESS_TOKEN';
  // Native user forms need the bridge; explicit internal payloads must not inherit it.
  const internalFetch = window.fetch.bind(window);
  const userCache = new Map();
  const verifiedDistributorIdentities = new Map();
  let distributorIdentityRevision = 0;
  const orderDetailCache = new Map();
  const state = {
    open: false,
    tab: 'orders',
    distributors: [],
    orders: [],
    total: 0,
    selectedDistributor: '',
    settlementStatus: '',
    settlementMonth: '',
    openMonthPicker: '',
    monthPickerYears: { admin: null, native: null },
    orderSearch: '',
    summary: null,
    page: 1,
    pageSize: 20,
    expandedDeviceOrders: {},
    visibilityPlans: [],
    visibilityPlan: null,
    visibilitySearchAudience: 'customer',
    visibilitySearchResults: [],
  };

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const money = (cents) => `¥${((Number(cents) || 0) / 100).toFixed(2)}`;
  const settlementMonthLabel = () => {
    const match = /^(\d{4})-(\d{2})$/.exec(state.settlementMonth);
    return match ? `${Number(match[1])}年${Number(match[2])}月` : '';
  };
  const settlementMonthFieldLabel = () => {
    const match = /^(\d{4})-(\d{2})$/.exec(state.settlementMonth);
    return match ? `${match[1]}年${match[2]}月` : '请选择月份';
  };
  const settlementMonthPickerYear = (scope) => {
    const selectedYear = Number(/^(\d{4})-\d{2}$/.exec(state.settlementMonth)?.[1]);
    return state.monthPickerYears[scope] || selectedYear || new Date().getFullYear();
  };

  function settlementMonthPicker(scope, id) {
    const year = settlementMonthPickerYear(scope);
    const selected = /^(\d{4})-(\d{2})$/.exec(state.settlementMonth);
    const isOpen = state.openMonthPicker === scope;
    const months = Array.from({ length: 12 }, (_, index) => {
      const month = index + 1;
      const isSelected = Number(selected?.[1]) === year && Number(selected?.[2]) === month;
      return `<button type="button" class="admin-dist-month-option${isSelected ? ' selected' : ''}" data-settlement-month-action="select" data-month-scope="${scope}" data-year="${year}" data-month="${month}" aria-pressed="${isSelected}">${month}月</button>`;
    }).join('');
    return `<div class="admin-dist-month-picker" data-settlement-month-picker="${scope}">
      <button id="${id}" type="button" class="admin-dist-month-trigger" data-settlement-month-action="toggle" data-month-scope="${scope}" aria-haspopup="dialog" aria-expanded="${isOpen}" aria-controls="${id}-popover"><span>${settlementMonthFieldLabel()}</span><span aria-hidden="true">▣</span></button>
      <div id="${id}-popover" class="admin-dist-month-popover" role="dialog" aria-label="选择结算月份" ${isOpen ? '' : 'hidden'}>
        <header><button type="button" data-settlement-month-action="previous-year" data-month-scope="${scope}" aria-label="上一年" ${year <= 2000 ? 'disabled' : ''}>‹</button><strong>${year}年</strong><button type="button" data-settlement-month-action="next-year" data-month-scope="${scope}" aria-label="下一年" ${year >= 2100 ? 'disabled' : ''}>›</button></header>
        <div class="admin-dist-month-grid" role="grid">${months}</div>
        <footer><button type="button" data-settlement-month-action="clear" data-month-scope="${scope}">清除</button><button type="button" data-settlement-month-action="current" data-month-scope="${scope}">本月</button></footer>
      </div>
    </div>`;
  }

  function rerenderMonthPicker(scope) {
    if (scope === 'native') renderNativeOrders();
    else if (state.open && state.tab === 'orders') renderOrders();
  }

  async function handleSettlementMonthAction(event, refresh, scope) {
    const target = event.target.closest?.('[data-settlement-month-action]');
    if (!target || target.dataset.monthScope !== scope) return false;
    const action = target.dataset.settlementMonthAction;
    if (action === 'toggle') {
      state.openMonthPicker = state.openMonthPicker === scope ? '' : scope;
      state.monthPickerYears[scope] = settlementMonthPickerYear(scope);
      rerenderMonthPicker(scope);
      return true;
    }
    if (action === 'previous-year' || action === 'next-year') {
      const year = settlementMonthPickerYear(scope) + (action === 'next-year' ? 1 : -1);
      state.monthPickerYears[scope] = Math.max(2000, Math.min(2100, year));
      state.openMonthPicker = scope;
      rerenderMonthPicker(scope);
      return true;
    }

    let value = '';
    if (action === 'select') {
      const year = Number(target.dataset.year);
      const month = Number(target.dataset.month);
      if (!Number.isInteger(year) || year < 2000 || year > 2100 || !Number.isInteger(month) || month < 1 || month > 12) return true;
      value = `${year}-${String(month).padStart(2, '0')}`;
      state.monthPickerYears[scope] = year;
    } else if (action === 'current') {
      const current = new Date();
      value = `${current.getFullYear()}-${String(current.getMonth() + 1).padStart(2, '0')}`;
      state.monthPickerYears[scope] = current.getFullYear();
    } else if (action !== 'clear') {
      return false;
    }

    state.settlementMonth = value;
    state.openMonthPicker = '';
    state.page = 1;
    await refresh();
    return true;
  }
  const selectedDistributor = () => state.distributors
    .find((user) => String(user.id) === String(state.selectedDistributor));
  const selectedDistributorName = () => {
    const distributor = selectedDistributor();
    return distributor?.distributor_name || distributor?.email || '';
  };
  const settlementScopeReady = () => Boolean(
    state.selectedDistributor
    && state.settlementMonth
    && state.settlementStatus === '0'
  );
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
    const response = await internalFetch(`/api/v2/${securePath()}${path}`, {
      method,
      headers: {
        Authorization: token,
        'Content-Type': 'application/json',
        'Content-Language': localStorage.getItem('i18nextLng') || 'zh-CN',
      },
      body: options.data ? JSON.stringify(options.data) : undefined,
      credentials: 'same-origin',
      cache: 'no-store',
      signal: options.signal,
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
    if (state.settlementMonth) params.set('settlement_month', state.settlementMonth);
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
      const identityRevision = distributorIdentityRevision;
      if (/\/user\/(update|generate)(?:\?|$)/.test(url)) {
        body = appendDistributorField(body, /\/user\/generate(?:\?|$)/.test(url));
      }
      this.addEventListener('load', function () {
        if (/\/user\/fetch(?:\?|$)/.test(url)) rememberUsers(this.responseText, identityRevision);
        if (/\/user\/getUserInfoById(?:\?|$)/.test(url)) rememberUsers(this.responseText, identityRevision);
        if (/\/order\/detail(?:\?|$)/.test(url)) rememberOrderDetail(this.responseText);
      });
      return originalSend.call(this, body);
    };

    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init = {}) {
      const url = typeof input === 'string' ? input : input?.url || '';
      const identityRevision = distributorIdentityRevision;
      if (/\/user\/(update|generate)(?:\?|$)/.test(url) && init.body) {
        init = { ...init, body: appendDistributorField(init.body, /\/user\/generate(?:\?|$)/.test(url)) };
      }
      return originalFetch(input, init).then((response) => {
        if (/\/user\/fetch(?:\?|$)/.test(url)) {
          response.clone().text().then((text) => rememberUsers(text, identityRevision)).catch(() => {});
        }
        if (/\/user\/getUserInfoById(?:\?|$)/.test(url)) {
          response.clone().text().then((text) => rememberUsers(text, identityRevision)).catch(() => {});
        }
        if (/\/order\/detail(?:\?|$)/.test(url)) {
          response.clone().text().then(rememberOrderDetail).catch(() => {});
        }
        return response;
      });
    };
  }

  function activeInjectedSwitch(id, creating) {
    return [...document.querySelectorAll('.xboard-distributor-injected input[type="checkbox"]')]
      .find((input) => {
        const field = input.closest('.xboard-distributor-injected');
        return input.offsetParent !== null && !input.disabled && (creating
          ? field?.dataset.distributorMode === 'create'
          : field?.dataset.distributorMode === 'edit' && id != null && String(id) === field.dataset.distributorUserId);
      });
  }

  function validateDistributorName(value) {
    const name = String(value || '').trim();
    if (!name) throw new Error('请输入分销商名称');
    if (name.length > 16) throw new Error('分销商名称最多 16 个字符');
    if (/\p{C}/u.test(name)) throw new Error('分销商名称不能包含控制字符或不可见字符');
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(name)) throw new Error('分销商名称请填写商户名称，不要使用邮箱');
    return name;
  }

  function appendDistributorField(body, creating = false) {
    let parsed = body;
    let encoded = false;
    if (typeof body === 'string') {
      try { parsed = JSON.parse(body); }
      catch (_) {
        if (!body.includes('=')) return body;
        parsed = new URLSearchParams(body);
        encoded = true;
      }
    }
    const isForm = parsed instanceof FormData || parsed instanceof URLSearchParams;
    if (!isForm && (typeof body !== 'string' || !parsed || typeof parsed !== 'object' || Array.isArray(parsed))) return body;
    const checkbox = activeInjectedSwitch(isForm ? parsed.get('id') : parsed.id, creating);
    if (!checkbox) return body;
    const value = checkbox.checked ? 1 : 0;
    const field = checkbox.closest('.xboard-distributor-injected');
    // A stale editor saving unrelated profile fields must not write cached identity.
    if (!creating && (field?.dataset.distributorInitialRole == null || String(value) === field.dataset.distributorInitialRole)) return body;
    const nameInput = field?.querySelector('[data-distributor-name]');
    const savedName = String(field?.dataset?.distributorName || '').trim();
    let distributorName = '';
    if (value) {
      try { distributorName = validateDistributorName(savedName || nameInput?.value); }
      catch (error) { toast(error.message, 'error'); throw error; }
    }
    if (isForm) {
      parsed.set('is_distributor', String(value));
      if (value || creating) parsed.set('distributor_name', distributorName);
      return encoded ? parsed.toString() : parsed;
    }
    parsed.is_distributor = value;
    if (value || creating) parsed.distributor_name = distributorName;
    return JSON.stringify(parsed);
  }

  function rememberUsers(responseText, requestRevision = distributorIdentityRevision) {
    try {
      const payload = typeof responseText === 'string' ? JSON.parse(responseText) : responseText;
      const users = Array.isArray(payload?.data) ? payload.data : payload?.data?.email ? [payload.data] : [];
      users.forEach((user) => {
        if (!user?.email) return;
        const verified = verifiedDistributorIdentities.get(Number(user.id));
        // A request begun before verification may finish later and otherwise restore the old name.
        if (verified && requestRevision < verified.revision) {
          user = { ...user, distributor_name: verified.name, is_distributor: verified.isDistributor };
        } else if (verified) {
          // Future reads remain authoritative, including changes made by another administrator.
          verified.name = user.distributor_name;
          verified.isDistributor = user.is_distributor;
        }
        const email = String(user.email).toLowerCase();
        // A saved email change must not leave an older entry first in the ID lookup.
        for (const [cachedEmail, cachedUser] of userCache) {
          if (cachedEmail !== email && Number(cachedUser.id) === Number(user.id)) userCache.delete(cachedEmail);
        }
        userCache.set(email, user);
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
      const field = checkbox.closest('.xboard-distributor-injected');
      if (field?.dataset.distributorMode !== 'edit') return;
      const user = field.dataset.distributorUserId
        ? [...userCache.values()].find((item) => String(item.id) === field.dataset.distributorUserId)
        : userCache.get(field.dataset.distributorLookupEmail);
      if (user) syncInjectedDistributorField(checkbox, user);
      syncDistributorNameField(checkbox);
    });
  }

  function syncInjectedDistributorField(checkbox, user) {
    const field = checkbox.closest('.xboard-distributor-injected');
    if (!field || (field.dataset.distributorUserId && field.dataset.distributorUserId !== String(user.id))) return;
    const initial = field.dataset.distributorInitialRole;
    const dirty = initial != null && checkbox.checked !== (initial === '1');
    field.dataset.distributorUserId = String(user.id);
    checkbox.disabled = false;
    if (!dirty) {
      checkbox.checked = Boolean(user.is_distributor);
      field.dataset.distributorInitialRole = user.is_distributor ? '1' : '0';
    }
    // Preserve an unsaved conversion name and role intent across cache refreshes.
    if (!dirty || initial === '1') {
      field.dataset.distributorName = String(user.distributor_name || '').trim();
      const input = field.querySelector('[data-distributor-name]');
      if (input) input.value = field.dataset.distributorName;
    }
    syncDistributorNameField(checkbox);
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
      let user = null;
      let lookupEmail = '';
      if (!isCreate) {
        const emailInput = [...dialog.querySelectorAll('input')]
          .find((input) => String(input.value || '').includes('@'));
        lookupEmail = String(emailInput?.value || '').toLowerCase();
        user = userCache.get(lookupEmail);
        checked = Boolean(user?.is_distributor);
        savedDistributorName = String(user?.distributor_name || '').trim();
      }

      const field = document.createElement('div');
      field.className = 'xboard-distributor-injected';
      field.dataset.distributorName = savedDistributorName;
      field.dataset.distributorMode = isCreate ? 'create' : 'edit';
      field.dataset.distributorLookupEmail = lookupEmail;
      if (user) field.dataset.distributorUserId = String(user.id);
      if (isCreate || user) field.dataset.distributorInitialRole = checked ? '1' : '0';
      field.innerHTML = `<div class="xboard-distributor-injected-toggle"><div><strong>是否分销商</strong><small>Distributor account</small></div><label class="admin-dist-switch"><input type="checkbox" ${checked ? 'checked' : ''}><span></span></label></div><label class="xboard-distributor-name" data-distributor-name-row>分销商名称<input type="text" maxlength="16" data-distributor-name placeholder="请输入分销商名称"><small>最多 16 个字符，用于客户端订阅名称；中文、字母、数字和空格计入长度。</small></label><div class="xboard-distributor-name-readonly" data-distributor-name-readonly-row><span>分销商名称</span><strong data-distributor-name-value></strong></div>`;

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
      const checkbox = field.querySelector('input[type="checkbox"]');
      checkbox.disabled = !isCreate && !user;
      syncDistributorNameField(checkbox);
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
      field.innerHTML = `${detail.subscription_name ? `<strong>订阅名称</strong><div>${escapeHtml(detail.subscription_name)}</div>` : ''}<strong>订阅链接</strong><div>${value}${manage}</div>${customerName}`;

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
      <nav><button data-tab="orders" class="${state.tab === 'orders' ? 'active' : ''}">分销订单</button><button data-tab="users" class="${state.tab === 'users' ? 'active' : ''}">分销商账号</button><button data-tab="visibility" class="${state.tab === 'visibility' ? 'active' : ''}">套餐可见范围</button></nav>
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
      if (tab === 'orders') { await loadDistributors(); await loadOrders(); }
      else if (tab === 'users') { await loadDistributors(); renderUsers(); }
      else await loadVisibilityPlans();
    } catch (error) {
      renderPanel(`<div class="admin-dist-error">${escapeHtml(error.message)}</div>`);
    }
  }

  async function loadDistributors() {
    state.distributors = dataOf(await api('/user/distributor/options')) || [];
  }

  async function loadVisibilityPlans() {
    await loadDistributors();
    const payload = await api('/plan/visibility');
    state.visibilityPlans = dataOf(payload)?.plans || [];
    const selectedId = state.visibilityPlan?.id || state.visibilityPlans[0]?.id;
    if (selectedId) await loadVisibilityPlan(selectedId);
    else { state.visibilityPlan = null; renderVisibility(); }
  }

  async function loadVisibilityPlan(id) {
    const payload = await api(`/plan/visibility?id=${encodeURIComponent(id)}`);
    state.visibilityPlan = dataOf(payload)?.plan || null;
    state.visibilitySearchResults = [];
    renderVisibility();
  }

  function renderVisibility() {
    const plan = state.visibilityPlan;
    if (!plan) {
      renderPanel('<div class="admin-dist-empty">暂无套餐</div>');
      return;
    }
    const customers = plan.customer_users || [];
    const dealers = plan.distributor_users || [];
    const recipientRows = (users, audience) => users.map((user) => `<div class="admin-dist-visibility-user"><span><strong>${escapeHtml(user.distributor_name || user.email)}</strong><small>${escapeHtml(user.email)} · ID ${user.id}</small></span><button type="button" data-visibility-remove="${audience}" data-user-id="${user.id}">移除</button></div>`).join('') || '<p class="admin-dist-empty">名单为空</p>';
    const results = state.visibilitySearchResults.map((user) => {
      const audience = state.visibilitySearchAudience;
      const selected = (audience === 'customer' ? customers : dealers).some((item) => Number(item.id) === Number(user.id));
      const name = user.distributor_name || user.email;
      return `<div class="admin-dist-visibility-user"><span><strong>${escapeHtml(name)}</strong><small>${escapeHtml(user.email)} · ID ${user.id}</small></span><button type="button" data-visibility-add="${audience}" data-user-id="${user.id}" ${selected ? 'disabled' : ''}>${selected ? '已添加' : '添加'}</button></div>`;
    }).join('');
    const selectedDealerIds = new Set(dealers.map((user) => Number(user.id)));
    const availableDealers = (state.distributors || []).filter((user) => !selectedDealerIds.has(Number(user.id)));
    const dealerPicker = `<label>添加分销商<select id="admin-dist-distributor-picker"><option value="">请选择未加入名单的分销商</option>${availableDealers.map((user) => `<option value="${user.id}">${escapeHtml(user.distributor_name || user.email)}（${escapeHtml(user.email)}）</option>`).join('')}</select></label>`;
    renderPanel(`<div class="admin-dist-visibility">
      <p>可见范围只控制套餐目录和新购资格，不改变套餐的服务权限组。旧套餐已保留原受众范围。</p>
      <label>套餐<select id="admin-dist-visibility-plan">${state.visibilityPlans.map((item) => `<option value="${item.id}" ${Number(item.id) === Number(plan.id) ? 'selected' : ''}>${escapeHtml(item.name)}（#${item.id}）</option>`).join('')}</select></label>
      <section><h2>普通用户</h2><label>谁能看到并新购<select id="admin-dist-customer-mode"><option value="all" ${plan.customer_visibility === 'all' ? 'selected' : ''}>所有普通用户</option><option value="selected" ${plan.customer_visibility === 'selected' ? 'selected' : ''}>仅名单中的普通用户</option></select></label>${plan.customer_visibility === 'selected' ? `<div class="admin-dist-visibility-list">${recipientRows(customers, 'customer')}</div><div class="admin-dist-visibility-search"><input id="admin-dist-customer-search" type="search" minlength="2" placeholder="按邮箱搜索普通用户"><button type="button" data-admin-dist="search-visibility-customer">搜索</button></div>${state.visibilitySearchAudience === 'customer' ? results : ''}` : ''}</section>
      <section><h2>分销商</h2><label>谁能看到并新购<select id="admin-dist-distributor-mode">${plan.distributor_visibility === 'all' ? '<option value="all" selected>旧套餐兼容：当前所有分销商可见</option>' : ''}<option value="none" ${plan.distributor_visibility === 'none' ? 'selected' : ''}>不向分销商开放</option><option value="selected" ${plan.distributor_visibility === 'selected' ? 'selected' : ''}>仅名单中的分销商</option></select></label>${plan.distributor_visibility === 'selected' ? `${dealerPicker}<div class="admin-dist-visibility-list">${recipientRows(dealers, 'distributor')}</div>` : ''}</section>
      <footer><button type="button" data-admin-dist="save-visibility">保存套餐可见范围</button></footer>
    </div>`);
  }

  async function searchVisibilityUsers(audience) {
    const inputId = audience === 'customer' ? 'admin-dist-customer-search' : 'admin-dist-dealer-search';
    const q = document.getElementById(inputId)?.value.trim() || '';
    if (q.length < 2) throw new Error('请输入至少两个字符');
    state.visibilitySearchAudience = audience;
    const payload = await api(`/plan/visibility/users?audience=${audience}&q=${encodeURIComponent(q)}`);
    state.visibilitySearchResults = dataOf(payload) || [];
    renderVisibility();
  }

  async function saveVisibility() {
    const plan = state.visibilityPlan;
    if (!plan) return;
    const response = await api('/plan/visibility', { method: 'POST', data: {
      plan_id: Number(plan.id),
      customer_visibility: document.getElementById('admin-dist-customer-mode')?.value || plan.customer_visibility,
      distributor_visibility: document.getElementById('admin-dist-distributor-mode')?.value || plan.distributor_visibility,
      customer_user_ids: (plan.customer_users || []).map((user) => Number(user.id)),
      distributor_user_ids: (plan.distributor_users || []).map((user) => Number(user.id)),
    } });
    void response;
    toast('套餐可见范围已保存');
    await loadVisibilityPlan(plan.id);
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
        settlement_month: state.settlementMonth || null,
        search: state.orderSearch || null,
      },
    });
    state.orders = payload?.data || [];
    state.total = Number(payload?.total || 0);
    state.summary = null;
    if (settlementScopeReady()) {
      const params = new URLSearchParams({
        distributor_user_id: state.selectedDistributor,
        settlement_month: state.settlementMonth,
      });
      state.summary = dataOf(await api(`/order/settlement/preview?${params}`));
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
      <td><strong>${escapeHtml(order.subscription_name || order.trade_no)}</strong>${order.subscription_name ? `<small>订单号：${escapeHtml(order.trade_no)}</small>` : ''}<small>${escapeHtml(order.order_type_label || '-')}</small>${Number(order.type) === 2 && order.subscription_trade_no ? `<small>关联原订单：${escapeHtml(order.subscription_trade_no)}</small>` : ''}</td>
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
    const summary = settlementSummary('data-admin-dist="settle"');
    renderPanel(`<div class="admin-dist-toolbar">
      <label>分销商<select id="admin-dist-distributor">${distributorOptions(true)}</select></label>
      <label>结算状态<select id="admin-dist-settlement"><option value="">全部</option><option value="0" ${state.settlementStatus === '0' ? 'selected' : ''}>未结算</option><option value="1" ${state.settlementStatus === '1' ? 'selected' : ''}>已结算</option></select></label>
      <label>结算月份${settlementMonthPicker('admin', 'admin-dist-settlement-month')}</label>
      <div class="admin-dist-search"><input id="admin-dist-order-search" type="search" maxlength="512" value="${escapeHtml(state.orderSearch)}" placeholder="短订阅号/订单号/用户名称/订阅链接"><button data-admin-dist="search-orders">查询</button><button class="secondary" data-admin-dist="clear-order-search" ${state.orderSearch ? '' : 'disabled'}>清空</button></div>
      <button data-admin-dist="refresh">刷新</button><button data-admin-dist="export">导出 Excel</button></div>${summary}
      <div class="admin-dist-table"><table><thead><tr><th>订阅名称 / 订单号</th><th>下单时间</th><th>用户名称</th><th>已绑定设备</th><th>已用流量</th><th>分销商</th><th>套餐</th><th>原价</th><th>结算状态</th><th>备注</th><th>操作</th></tr></thead><tbody>${rows || '<tr><td colspan="11" class="empty">暂无分销订单</td></tr>'}</tbody></table></div>
      <footer class="admin-dist-pagination"><span>共 ${state.total} 个订单</span><div><button data-page="prev" ${state.page <= 1 ? 'disabled' : ''}>上一页</button><span>第 ${state.page} 页</span><button data-page="next" ${state.page * state.pageSize >= state.total ? 'disabled' : ''}>下一页</button></div></footer>`);
  }

  function renderUsers(searchResult = null) {
    const result = searchResult ? `<div class="admin-dist-user-result"><div><strong>${escapeHtml(searchResult.email)}</strong><small>ID ${searchResult.id}${searchResult.banned ? ' · 已封禁' : ''}</small><label>分销商名称<input id="admin-dist-user-name" type="text" maxlength="16" value="${escapeHtml(searchResult.distributor_name || '')}" placeholder="请输入分销商名称" ${searchResult.is_distributor ? 'readonly' : ''}><small>最多 16 个字符，用于客户端订阅名称；中文、字母、数字和空格计入长度。</small></label></div><span class="admin-dist-user-actions">${searchResult.is_distributor ? `<button type="button" data-distributor-rename="${searchResult.id}">编辑名称</button>` : ''}<button data-user-toggle="${searchResult.id}" data-current="${searchResult.is_distributor ? 1 : 0}">${searchResult.is_distributor ? '取消分销商' : '设为分销商'}</button></span></div>` : '';
    renderPanel(`<div class="admin-dist-user-grid">
      <section><h2>设置已有用户</h2><p>输入完整邮箱，将普通用户设置为分销商，或取消已有分销身份。</p><div class="admin-dist-form-row"><input id="admin-dist-user-email" type="email" placeholder="user@example.com"><button data-admin-dist="search-user">查询</button></div>${result}</section>
      <section><h2>创建分销商</h2><p>创建后账号不获得普通订阅，只能进入分销页面。</p><label>分销商名称<input id="admin-dist-create-name" type="text" maxlength="16" placeholder="请输入分销商名称"><small>最多 16 个字符，用于客户端订阅名称；中文、字母、数字和空格计入长度。</small></label><label>邮箱<input id="admin-dist-create-email" type="email" placeholder="dealer@example.com"></label><label>密码（留空则与邮箱相同）<input id="admin-dist-create-password" type="password" minlength="8"></label><button data-admin-dist="create-user">创建分销商</button></section>
      <section class="wide"><h2>当前分销商</h2><p>编辑名称只影响后续新购订阅的命名，已有订阅及续费名称不变。</p><div class="admin-dist-user-list">${state.distributors.map((user) => `<div><span><strong>${escapeHtml(user.distributor_name || user.email)}${user.banned ? '（已封禁）' : ''}</strong><small>${escapeHtml(user.email)}</small></span><span class="admin-dist-user-actions"><button type="button" data-distributor-rename="${user.id}">编辑名称</button><button data-user-toggle="${user.id}" data-current="1">取消分销商</button></span></div>`).join('') || '<p>暂无分销商</p>'}</div></section>
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

  async function renameRequest(path, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 35000);
    try {
      return await api(path, { ...options, signal: controller.signal });
    } finally {
      clearTimeout(timeout);
    }
  }

  function syncRenamedDistributor(user) {
    verifiedDistributorIdentities.set(Number(user.id), {
      revision: ++distributorIdentityRevision, name: user.distributor_name, isDistributor: user.is_distributor,
    });
    // Preserve native editor fields (including its money representation); only update identity.
    for (const cached of userCache.values()) {
      if (Number(cached.id) === Number(user.id)) {
        cached.distributor_name = user.distributor_name;
        cached.is_distributor = user.is_distributor;
      }
    }
    state.distributors = state.distributors.flatMap((item) => Number(item.id) !== Number(user.id)
      ? [item] : user.is_distributor ? [{ ...item, distributor_name: user.distributor_name }] : []);
    for (const order of state.orders) {
      if (Number(order.user_id) === Number(user.id)) order.distributor_name = user.distributor_name;
    }
    for (const order of orderDetailCache.values()) {
      if (Number(order.user_id) === Number(user.id)) order.distributor_name = user.distributor_name;
    }
    document.querySelectorAll('[role="dialog"], [data-radix-dialog-content], .n-modal').forEach((dialog) => {
      const checkbox = dialog.querySelector('.xboard-distributor-injected input[type="checkbox"]');
      const field = checkbox?.closest('.xboard-distributor-injected');
      if (field?.dataset.distributorUserId === String(user.id)) syncInjectedDistributorField(checkbox, user);
    });
  }

  async function openDistributorNameEditor(id, trigger) {
    if (document.getElementById('admin-dist-rename')) return;
    const userId = Number(id);
    if (!Number.isSafeInteger(userId) || userId <= 0) throw new Error('分销商 ID 无效');
    const dialog = document.createElement('dialog');
    dialog.id = 'admin-dist-rename';
    dialog.className = 'admin-dist-rename-dialog';
    dialog.setAttribute('aria-labelledby', 'admin-dist-rename-title');
    dialog.setAttribute('aria-describedby', 'admin-dist-rename-help');
    dialog.innerHTML = `<form novalidate><h2 id="admin-dist-rename-title">编辑分销商名称</h2><p data-rename-account></p><p data-rename-current></p>
      <label for="admin-dist-rename-name">新名称</label><input id="admin-dist-rename-name" type="text" maxlength="16" required autocomplete="off" aria-describedby="admin-dist-rename-help admin-dist-rename-status">
      <p id="admin-dist-rename-help">最多 16 个字符。仅影响后续新购订阅的命名；已有订阅名称、链接及续费不变。</p>
      <p id="admin-dist-rename-status" role="status" aria-live="polite"></p>
      <footer><button type="button" data-rename-cancel>取消</button><button type="button" data-rename-recheck hidden>重新核对</button><button type="submit" class="primary">保存名称</button></footer></form>`;
    document.body.appendChild(dialog);
    const form = dialog.querySelector('form');
    const input = dialog.querySelector('input');
    const status = dialog.querySelector('[role="status"]');
    const save = dialog.querySelector('[type="submit"]');
    const cancel = dialog.querySelector('[data-rename-cancel]');
    const recheck = dialog.querySelector('[data-rename-recheck]');
    let busy = false;
    let currentUser = null;
    let attemptedName = null;
    let attemptedRevision = null;
    let needsVerification = true;
    let writeError = '';
    let writeRejected = false;

    function setBusy(value) {
      busy = value;
      form.setAttribute('aria-busy', String(value));
      save.disabled = value || needsVerification || !currentUser?.is_distributor;
      input.disabled = value || needsVerification || !currentUser?.is_distributor;
      cancel.disabled = value;
      recheck.disabled = value;
      save.textContent = value ? '处理中…' : '保存名称';
    }

    async function verify() {
      const user = dataOf(await renameRequest(`/user/getUserInfoById?id=${userId}`));
      if (!user || Number(user.id) !== userId || typeof user.is_distributor !== 'boolean') {
        throw new Error('用户详情响应无效');
      }
      if (!Object.prototype.hasOwnProperty.call(user, 'distributor_revision') || (user.distributor_revision !== null
        && (typeof user.distributor_revision !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(user.distributor_revision)))) {
        throw new Error('缺少有效的分销商版本，请刷新页面并确认服务已更新');
      }
      currentUser = user;
      needsVerification = false;
      recheck.hidden = true;
      syncRenamedDistributor(user);
      dialog.querySelector('[data-rename-account]').textContent = `${user.email} · ID ${userId}`;
      dialog.querySelector('[data-rename-current]').textContent = `当前名称：${user.distributor_name || '未设置'}`;
      if (!user.is_distributor) {
        status.textContent = '该账号已不是分销商，不能修改名称，请关闭后刷新列表。';
      } else if (attemptedName !== null && !writeRejected && user.distributor_revision !== attemptedRevision && user.distributor_name === attemptedName) {
        if (state.open && state.tab === 'users') renderUsers();
        if (document.getElementById('xboard-native-distributor-orders')) renderNativeOrders();
        toast('分销商名称已更新并核对');
        dialog.close();
      } else if (attemptedName !== null) {
        if (!writeRejected && user.distributor_revision === attemptedRevision) {
          needsVerification = true;
          recheck.hidden = false;
          status.textContent = `保存结果未确认，请勿重复提交：${writeError || '名称及版本尚未更新'}。请重新核对。`;
        } else {
          status.textContent = `${writeError || '分销商信息已被其他操作修改'}。已读取当前名称，请核对草稿后再保存。`;
          attemptedName = null;
        }
      } else {
        input.value = user.distributor_name || '';
        status.textContent = '';
      }
    }

    async function runVerification() {
      try {
        await verify();
      } catch (error) {
        needsVerification = true;
        recheck.hidden = false;
        status.textContent = `${attemptedName === null ? '无法读取当前名称' : '保存结果未确认，请勿重复提交'}：${error.message}。请重新核对。`;
      }
    }

    cancel.addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', (event) => { if (busy) event.preventDefault(); });
    dialog.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab') return;
      const focusable = [...dialog.querySelectorAll('input:not(:disabled), button:not(:disabled)')]
        .filter((element) => !element.hidden);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (!first || (event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
        event.preventDefault();
        (event.shiftKey ? last : first)?.focus();
      }
    });
    dialog.addEventListener('close', () => {
      dialog.remove();
      const target = trigger?.isConnected ? trigger : document.querySelector(`[data-distributor-rename="${userId}"]`) || document.getElementById('admin-dist-entry');
      target?.focus();
    });
    recheck.addEventListener('click', async () => {
      if (busy) return;
      setBusy(true);
      await runVerification();
      setBusy(false);
      if (dialog.open && !input.disabled) input.focus();
    });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (busy || needsVerification || !currentUser?.is_distributor) return;
      let name;
      try { name = validateDistributorName(input.value); }
      catch (error) { status.textContent = error.message; input.focus(); return; }
      if (name === currentUser.distributor_name) { dialog.close(); return; }
      attemptedName = name;
      attemptedRevision = currentUser.distributor_revision;
      writeError = '';
      writeRejected = false;
      setBusy(true);
      status.textContent = '正在保存并核对…';
      try {
        const result = await renameRequest('/user/distributor/rename', { method: 'POST', data: {
          id: userId, distributor_name: name, expected_distributor_revision: attemptedRevision,
        } });
        if (dataOf(result) !== true) throw new Error('保存响应无效');
      } catch (error) {
        writeError = error.message;
        writeRejected = [400, 401, 403, 404, 405, 409, 419, 422, 429].includes(error.status);
      }
      // A timed-out response can still mean the server committed. Never retry the write here.
      await runVerification();
      setBusy(false);
      if (dialog.open && !input.disabled) input.focus();
    });
    setBusy(true);
    status.textContent = '正在读取当前名称…';
    dialog.showModal();
    await runVerification();
    setBusy(false);
    if (dialog.open && !input.disabled) { input.focus(); input.select(); }
  }

  async function toggleUser(id, current) {
    const distributorName = document.getElementById('admin-dist-user-name')?.value.trim() || '';
    if (!current) validateDistributorName(distributorName);
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
    validateDistributorName(distributorName);
    await api('/user/generate', {
      method: 'POST',
      data: { email_prefix: email.slice(0, at), email_suffix: email.slice(at + 1), password, is_distributor: 1, distributor_name: distributorName },
    });
    toast('分销商创建成功');
    await loadDistributors();
    renderUsers();
  }

  async function settle(refresh = loadOrders, button = null) {
    if (!settlementScopeReady() || !state.summary?.count) return;
    const distributorName = selectedDistributorName();
    const monthLabel = settlementMonthLabel();
    const expectedCount = Number(state.summary.count);
    const expectedTotalAmount = Number(state.summary.total_amount);
    if (!window.confirm(`确认结算 ${distributorName} ${monthLabel}的 ${expectedCount} 个未结算订单，共 ${money(expectedTotalAmount)}？`)) return;
    if (button) button.disabled = true;
    try {
      const result = dataOf(await api('/order/settlement/settle', { method: 'POST', data: {
        distributor_user_id: Number(state.selectedDistributor),
        settlement_month: state.settlementMonth,
        expected_count: expectedCount,
        expected_total_amount: expectedTotalAmount,
      } }));
      toast(`已结算 ${result.count} 个订单，共 ${money(result.total_amount)}`);
      await refresh();
    } catch (error) {
      if (error.status === 409) await refresh();
      throw error;
    } finally {
      if (button) button.disabled = false;
    }
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
      ${order.subscription_name ? `<div><dt>订阅名称</dt><dd>${escapeHtml(order.subscription_name)}</dd></div>` : ''}<div><dt>订单号</dt><dd>${escapeHtml(order.trade_no)}</dd></div><div><dt>分销商</dt><dd>${escapeHtml(order.distributor_name || order.distributor_email || '-')}</dd></div>
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
    if (await handleSettlementMonthAction(event, loadOrders, 'admin')) return;
    const close = event.target.closest('[data-admin-dist="close"]');
    if (close) { state.open = false; renderPanel(''); return; }
    const tab = event.target.closest('[data-tab]');
    if (tab) { await openPanel(tab.dataset.tab); return; }
    const action = event.target.closest('[data-admin-dist]')?.dataset.adminDist;
    try {
      if (action === 'refresh') await loadOrders();
      else if (action === 'export') await exportOrders(event.target.closest('[data-admin-dist]'));
      else if (action === 'settle') await settle(loadOrders, event.target.closest('[data-admin-dist="settle"]'));
      else if (action === 'search-orders') { state.orderSearch = document.getElementById('admin-dist-order-search')?.value.trim() || ''; state.page = 1; await loadOrders(); }
      else if (action === 'clear-order-search') { state.orderSearch = ''; state.page = 1; await loadOrders(); }
      else if (action === 'search-user') await searchUser();
      else if (action === 'create-user') await createUser();
      else if (action === 'search-visibility-customer') await searchVisibilityUsers('customer');
      else if (action === 'search-visibility-distributor') await searchVisibilityUsers('distributor');
      else if (action === 'save-visibility') await saveVisibility();
      const addRecipient = event.target.closest('[data-visibility-add]');
      if (addRecipient) {
        const user = state.visibilitySearchResults.find((item) => Number(item.id) === Number(addRecipient.dataset.userId));
        const key = addRecipient.dataset.visibilityAdd === 'customer' ? 'customer_users' : 'distributor_users';
        if (user && state.visibilityPlan && !(state.visibilityPlan[key] || []).some((item) => Number(item.id) === Number(user.id))) {
          state.visibilityPlan[key] = [...(state.visibilityPlan[key] || []), user];
          renderVisibility();
        }
      }
      const removeRecipient = event.target.closest('[data-visibility-remove]');
      if (removeRecipient && state.visibilityPlan) {
        const key = removeRecipient.dataset.visibilityRemove === 'customer' ? 'customer_users' : 'distributor_users';
        state.visibilityPlan[key] = (state.visibilityPlan[key] || []).filter((item) => Number(item.id) !== Number(removeRecipient.dataset.userId));
        renderVisibility();
      }
      const toggle = event.target.closest('[data-user-toggle]');
      if (toggle) await toggleUser(toggle.dataset.userToggle, toggle.dataset.current === '1');
      const rename = event.target.closest('[data-distributor-rename]');
      if (rename) await openDistributorNameEditor(rename.dataset.distributorRename, rename);
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
    if (event.target.id === 'admin-dist-visibility-plan') {
      await loadVisibilityPlan(event.target.value);
    } else if (event.target.id === 'admin-dist-customer-mode') {
      if (state.visibilityPlan) state.visibilityPlan.customer_visibility = event.target.value;
      renderVisibility();
    } else if (event.target.id === 'admin-dist-distributor-mode') {
      if (state.visibilityPlan) state.visibilityPlan.distributor_visibility = event.target.value;
      renderVisibility();
    } else if (event.target.id === 'admin-dist-distributor-picker') {
      const userId = Number(event.target.value);
      const user = (state.distributors || []).find((item) => Number(item.id) === userId);
      if (user && state.visibilityPlan && !(state.visibilityPlan.distributor_users || []).some((item) => Number(item.id) === userId)) {
        state.visibilityPlan.distributor_users = [...(state.visibilityPlan.distributor_users || []), user];
      }
      renderVisibility();
    } else if (event.target.id === 'admin-dist-distributor') {
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

  function settlementSummary(buttonAttribute) {
    if (!state.selectedDistributor) {
      return '<div class="admin-dist-summary muted">请选择一个分销商。</div>';
    }
    if (!state.settlementMonth) {
      return '<div class="admin-dist-summary muted">请选择结算月份；批量结算不支持全部历史月份。</div>';
    }
    if (state.settlementStatus !== '0') {
      return '<div class="admin-dist-summary muted">将结算状态设为“未结算”后可预览并执行结算。</div>';
    }
    if (!state.summary) {
      return '<div class="admin-dist-summary muted">正在计算该结算批次的订单数量与金额…</div>';
    }

    const buttonLabel = `结算 ${selectedDistributorName()} ${settlementMonthLabel()}未结算订单`;
    return `<div class="admin-dist-summary"><span>未结算：<b>${state.summary.count}</b> 个订单，合计 <b>${money(state.summary.total_amount)}</b></span><button type="button" ${buttonAttribute} ${state.summary.count ? '' : 'disabled'}>${escapeHtml(buttonLabel)}</button></div>`;
  }

  function nativeSummary() {
    return settlementSummary('data-native-dist="settle"');
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
        <label>结算月份${settlementMonthPicker('native', 'native-dist-settlement-month')}</label>
        <div class="admin-dist-search"><input id="native-dist-order-search" type="search" maxlength="512" value="${escapeHtml(state.orderSearch)}" placeholder="短订阅号/订单号/用户名称/订阅链接"><button type="button" data-native-dist="search-orders">查询</button><button type="button" class="secondary" data-native-dist="clear-order-search" ${state.orderSearch ? '' : 'disabled'}>清空</button></div>
      </div>${nativeSummary()}
      <div class="admin-dist-table"><table><thead><tr><th>订阅名称 / 订单号</th><th>下单时间</th><th>用户名称</th><th>已绑定设备</th><th>已用流量</th><th>分销商</th><th>套餐</th><th>原价</th><th>结算状态</th><th>备注</th><th>操作</th></tr></thead><tbody>${rows || '<tr><td colspan="11" class="empty">暂无符合条件的分销订单</td></tr>'}</tbody></table></div>
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
      if (await handleSettlementMonthAction(event, loadNativeOrders, 'native')) return;
      const action = event.target.closest('[data-native-dist]')?.dataset.nativeDist;
      if (action === 'refresh') await loadNativeOrders();
      else if (action === 'export') await exportOrders(event.target.closest('[data-native-dist]'));
      else if (action === 'settle') await settle(loadNativeOrders, event.target.closest('[data-native-dist="settle"]'));
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
    if (state.openMonthPicker && !event.target.closest?.('[data-settlement-month-picker]')) {
      const scope = state.openMonthPicker;
      state.openMonthPicker = '';
      rerenderMonthPicker(scope);
    }
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
    if (event.key === 'Escape' && state.openMonthPicker) {
      const scope = state.openMonthPicker;
      state.openMonthPicker = '';
      rerenderMonthPicker(scope);
      return;
    }
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
