(function () {
  'use strict';

  if (window.__xboardDistributorUi) return;
  window.__xboardDistributorUi = true;

  const TOKEN_KEY = 'VUE_NAIVE_ACCESS_TOKEN';
  const API_BASE = `${window.location.origin}${(window.routerBase || '/').replace(/\/$/, '')}/api/v1`;
  const PERIODS = [
    ['month_price', '月付'],
    ['quarter_price', '季付'],
    ['half_year_price', '半年付'],
    ['year_price', '年付'],
    ['two_year_price', '两年付'],
    ['three_year_price', '三年付'],
    ['onetime_price', '一次性'],
  ];
  const COPY = {
    'zh-CN': {
      buy: '购买订阅', orders: '我的订单', invite: '我的邀请', logout: '退出登录',
      title: '分销订阅中心', subtitle: '每个订单生成一份独立订阅，客户扫码领取后不可再次领取。',
      buyNow: '立即下单', original: '原价', free: '分销免支付', confirm: '确认下单', cancel: '取消',
      loading: '加载中…', empty: '暂无数据', settled: '已结算', unsettled: '未结算',
      pending: '待领取', claimed: '已领取', closed: '已关闭', showQr: '显示二维码',
      qrTitle: '客户订阅二维码', qrHint: '请让终端客户使用订阅客户端扫描。二维码只能成功领取一次。',
      done: '已添加成功', closeAgain: '再次点击确认关闭', closeWarning: '请确保节点已经可用，关闭之后无法再次获取。',
      claimedOk: '订阅已经领取，可以安全关闭。', orderNo: '订单号', amount: '订单金额', status: '订单状态',
      delivery: '交付状态', settlement: '结算状态', plan: '订阅计划', period: '周期', created: '创建时间',
      inviteUsers: '已邀请用户', validCommission: '有效佣金', pendingCommission: '确认中佣金',
      rate: '佣金比例', availableCommission: '可用佣金', generateCode: '生成邀请码', transfer: '佣金划转余额',
      inviteCode: '邀请码', copy: '复制邀请链接', commissionHistory: '佣金记录', noCode: '暂无邀请码',
      success: '操作成功', language: '语言', dark: '深色模式', light: '浅色模式', account: '账号',
    },
    'en-US': {
      buy: 'Buy Subscription', orders: 'My Orders', invite: 'My Invitations', logout: 'Sign out',
      title: 'Distributor Center', subtitle: 'Each order creates an independent subscription that can be claimed once.',
      buyNow: 'Place order', original: 'Original price', free: 'Distributor — no online payment', confirm: 'Confirm', cancel: 'Cancel',
      loading: 'Loading…', empty: 'No data', settled: 'Settled', unsettled: 'Unsettled',
      pending: 'Pending claim', claimed: 'Claimed', closed: 'Closed', showQr: 'Show QR',
      qrTitle: 'Customer subscription QR', qrHint: 'Scan with the customer subscription client. This QR can only be claimed once.',
      done: 'Added successfully', closeAgain: 'Click again to close', closeWarning: 'Make sure the nodes work. The QR cannot be recovered after closing.',
      claimedOk: 'The subscription was claimed. It is safe to close.', orderNo: 'Order', amount: 'Amount', status: 'Status',
      delivery: 'Delivery', settlement: 'Settlement', plan: 'Plan', period: 'Period', created: 'Created',
      inviteUsers: 'Invited users', validCommission: 'Valid commission', pendingCommission: 'Pending commission',
      rate: 'Commission rate', availableCommission: 'Available commission', generateCode: 'Generate code',
      transfer: 'Transfer commission', inviteCode: 'Invite code', copy: 'Copy invite link',
      commissionHistory: 'Commission history', noCode: 'No invite code', success: 'Success',
      language: 'Language', dark: 'Dark mode', light: 'Light mode', account: 'Account',
    },
  };

  const state = {
    active: false,
    user: null,
    locale: localStorage.getItem('xboard_distributor_locale') || 'zh-CN',
    dark: localStorage.getItem('xboard_distributor_dark') === '1',
    loading: false,
    modal: null,
    closeArmed: false,
    poller: null,
  };

  const t = (key) => (COPY[state.locale] || COPY['zh-CN'])[key] || key;
  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const stripHtml = (value) => {
    const element = document.createElement('div');
    element.innerHTML = String(value || '');
    return element.textContent || '';
  };
  const money = (cents) => `¥${((Number(cents) || 0) / 100).toFixed(2)}`;
  const formatTime = (seconds) => seconds ? new Date(Number(seconds) * 1000).toLocaleString() : '-';
  const dataOf = (payload) => payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;

  function authToken() {
    try {
      const stored = JSON.parse(localStorage.getItem(TOKEN_KEY) || 'null');
      if (!stored || !stored.value) return null;
      if (stored.expire && stored.expire <= Date.now()) return null;
      return stored.value;
    } catch (_) {
      return null;
    }
  }

  async function api(path, options = {}) {
    const token = authToken();
    if (!token) throw new Error('Unauthorized');
    const method = options.method || 'GET';
    const headers = {
      Authorization: token,
      'Content-Language': state.locale,
      ...(options.headers || {}),
    };
    let body;
    if (options.data) {
      headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
      body = new URLSearchParams();
      Object.entries(options.data).forEach(([key, value]) => {
        if (value !== undefined && value !== null) body.append(key, String(value));
      });
    }
    const separator = path.includes('?') ? '&' : '?';
    const response = await fetch(`${API_BASE}${path}${method === 'GET' ? `${separator}t=${Date.now()}` : ''}`, {
      method, headers, body, cache: 'no-store', credentials: 'same-origin',
    });
    let payload = null;
    try { payload = await response.json(); } catch (_) { /* plain response */ }
    if (!response.ok || payload?.status === 'fail') {
      const error = new Error(payload?.message || `Request failed (${response.status})`);
      error.status = response.status;
      error.payload = payload;
      throw error;
    }
    return payload;
  }

  function toast(message, type = 'ok') {
    let root = document.getElementById('dist-toast-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'dist-toast-root';
      document.body.appendChild(root);
    }
    const item = document.createElement('div');
    item.className = `dist-toast ${type}`;
    item.textContent = message;
    root.appendChild(item);
    setTimeout(() => item.remove(), 3200);
  }

  function currentPage() {
    const path = (window.location.hash || '#/plan').replace(/^#/, '').split('?')[0];
    return ['/plan', '/order', '/invite'].includes(path) ? path : '/plan';
  }

  function navigate(path) {
    window.location.hash = `#${path}`;
  }

  function shell(content) {
    const page = currentPage();
    return `
      <div class="dist-shell ${state.dark ? 'is-dark' : ''}">
        <aside class="dist-sidebar">
          <div class="dist-brand"><span class="dist-brand-mark">X</span><span>${escapeHtml(window.settings?.title || 'XBoard')}</span></div>
          <nav>
            <button data-nav="/plan" class="${page === '/plan' ? 'active' : ''}"><span>▣</span>${t('buy')}</button>
            <button data-nav="/order" class="${page === '/order' ? 'active' : ''}"><span>☷</span>${t('orders')}</button>
            <button data-nav="/invite" class="${page === '/invite' ? 'active' : ''}"><span>♧</span>${t('invite')}</button>
          </nav>
        </aside>
        <section class="dist-main">
          <header class="dist-topbar">
            <div class="dist-mobile-title">${escapeHtml(window.settings?.title || 'XBoard')}</div>
            <div class="dist-top-actions">
              <button data-action="theme" title="${state.dark ? t('light') : t('dark')}">${state.dark ? '☀' : '◐'}</button>
              <button data-action="language" title="${t('language')}">${state.locale === 'zh-CN' ? '中' : 'EN'}</button>
              <span class="dist-account">● ${escapeHtml(state.user?.email || '')}</span>
              <button class="dist-logout" data-action="logout">${t('logout')}</button>
            </div>
          </header>
          <main class="dist-content">${content}</main>
        </section>
      </div>`;
  }

  function setContent(content) {
    const root = document.getElementById('distributor-app');
    if (root) root.innerHTML = shell(content);
  }

  function loadingView() {
    setContent(`<div class="dist-loading"><span></span>${t('loading')}</div>`);
  }

  async function renderPage() {
    if (!state.active) return;
    const page = currentPage();
    if ((window.location.hash || '').replace(/^#/, '').split('?')[0] !== page) {
      navigate(page);
      return;
    }
    loadingView();
    try {
      if (page === '/order') await renderOrders();
      else if (page === '/invite') await renderInvite();
      else await renderPlans();
    } catch (error) {
      setContent(`<div class="dist-error"><h2>${escapeHtml(error.message)}</h2><button data-action="retry">${t('loading')}</button></div>`);
    }
  }

  async function renderPlans() {
    const plans = dataOf(await api('/user/plan/fetch')) || [];
    const cards = plans.map((plan) => {
      const prices = PERIODS.filter(([key]) => Number(plan[key]) > 0);
      if (!prices.length) return '';
      const options = prices.map(([key, label]) => `<option value="${key}" data-price="${Number(plan[key])}">${label} · ${money(plan[key])}</option>`).join('');
      return `<article class="dist-plan-card">
        <div class="dist-plan-body">
          <div class="dist-plan-heading"><h2>${escapeHtml(plan.name)}</h2><span>${money(plan[prices[0][0]])}</span></div>
          <p>${escapeHtml(stripHtml(plan.content))}</p>
          <ul>
            <li>流量：${escapeHtml(plan.transfer_enable)} GB</li>
            <li>速度：${plan.speed_limit ? `${escapeHtml(plan.speed_limit)} Mbps` : '不限'}</li>
            <li>设备：${plan.device_limit || '不限'}</li>
          </ul>
        </div>
        <div class="dist-plan-actions">
          <select id="period-${plan.id}">${options}</select>
          <button data-buy="${plan.id}" data-name="${escapeHtml(plan.name)}">${t('buyNow')}</button>
        </div>
      </article>`;
    }).join('');
    setContent(`<section class="dist-page-head"><h1>${t('title')}</h1><p>${t('subtitle')}</p></section>
      <div class="dist-plan-grid">${cards || `<div class="dist-empty">${t('empty')}</div>`}</div>`);
  }

  function confirmPurchase(planId, planName) {
    const select = document.getElementById(`period-${planId}`);
    const option = select?.selectedOptions?.[0];
    if (!option) return;
    state.modal = { type: 'purchase', planId, planName, period: option.value, periodLabel: option.textContent, price: option.dataset.price };
    renderModal();
  }

  async function submitPurchase() {
    const modal = state.modal;
    if (!modal || modal.type !== 'purchase') return;
    const button = document.querySelector('[data-modal-action="confirm-purchase"]');
    if (button) button.disabled = true;
    try {
      const tradeNo = dataOf(await api('/user/order/save', {
        method: 'POST', data: { plan_id: modal.planId, period: modal.period },
      }));
      await openDelivery(tradeNo);
    } catch (error) {
      toast(error.message, 'error');
      if (button) button.disabled = false;
    }
  }

  async function renderOrders() {
    const orders = dataOf(await api('/user/order/fetch')) || [];
    const rows = orders.map((order) => {
      const delivery = order.delivery_status === 0 ? t('pending') : order.delivery_status === 1 ? t('claimed') : t('closed');
      const settlement = order.settlement_status === 1 ? t('settled') : t('unsettled');
      return `<tr>
        <td><strong>${escapeHtml(order.trade_no)}</strong><small>${formatTime(order.created_at)}</small></td>
        <td>${escapeHtml(order.plan?.name || '-')}</td><td>${escapeHtml(periodLabel(order.period))}</td>
        <td>${money(order.total_amount)}<small class="dist-free">${t('free')}</small></td>
        <td><span class="dist-badge delivery-${order.delivery_status}">${delivery}</span></td>
        <td><span class="dist-badge settle-${order.settlement_status}">${settlement}</span></td>
        <td>${order.delivery_status === 0 ? `<button class="dist-link-btn" data-delivery="${escapeHtml(order.trade_no)}">${t('showQr')}</button>` : '-'}</td>
      </tr>`;
    }).join('');
    setContent(`<section class="dist-page-head"><h1>${t('orders')}</h1><p>${t('subtitle')}</p></section>
      <div class="dist-table-wrap"><table><thead><tr><th>${t('orderNo')}</th><th>${t('plan')}</th><th>${t('period')}</th><th>${t('amount')}</th><th>${t('delivery')}</th><th>${t('settlement')}</th><th></th></tr></thead>
      <tbody>${rows || `<tr><td colspan="7" class="dist-empty">${t('empty')}</td></tr>`}</tbody></table></div>`);
  }

  function periodLabel(period) {
    const found = PERIODS.find(([key]) => key === period);
    return found ? found[1] : period;
  }

  async function renderInvite() {
    const info = dataOf(await api('/user/invite/fetch')) || { codes: [], stat: [] };
    const historyPayload = await api('/user/invite/details?current=1&page_size=50');
    const history = historyPayload?.data || [];
    const stat = info.stat || [];
    const cards = [
      [t('inviteUsers'), stat[0] || 0], [t('validCommission'), money(stat[1])],
      [t('pendingCommission'), money(stat[2])], [t('rate'), `${stat[3] || 0}%`],
      [t('availableCommission'), money(stat[4])],
    ].map(([label, value]) => `<div class="dist-stat-card"><span>${label}</span><strong>${value}</strong></div>`).join('');
    const codes = (info.codes || []).map((code) => {
      const url = `${window.location.origin}/#/register?code=${encodeURIComponent(code.code)}`;
      return `<div class="dist-code"><div><small>${t('inviteCode')}</small><strong>${escapeHtml(code.code)}</strong></div><button data-copy="${escapeHtml(url)}">${t('copy')}</button></div>`;
    }).join('');
    const historyRows = history.map((item) => `<tr><td>${escapeHtml(item.trade_no)}</td><td>${money(item.order_amount)}</td><td>${money(item.get_amount)}</td><td>${formatTime(item.created_at)}</td></tr>`).join('');
    setContent(`<section class="dist-page-head"><h1>${t('invite')}</h1></section>
      <div class="dist-stats">${cards}</div>
      <div class="dist-two-column">
        <section class="dist-panel"><div class="dist-panel-head"><h2>${t('inviteCode')}</h2><button data-action="generate-code">${t('generateCode')}</button></div>${codes || `<p class="dist-empty">${t('noCode')}</p>`}</section>
        <section class="dist-panel"><h2>${t('transfer')}</h2><div class="dist-transfer"><input id="transfer-amount" type="number" min="0.01" step="0.01" placeholder="0.00"><button data-action="transfer">${t('transfer')}</button></div></section>
      </div>
      <section class="dist-panel"><h2>${t('commissionHistory')}</h2><div class="dist-table-wrap"><table><thead><tr><th>${t('orderNo')}</th><th>${t('amount')}</th><th>${t('validCommission')}</th><th>${t('created')}</th></tr></thead><tbody>${historyRows || `<tr><td colspan="4" class="dist-empty">${t('empty')}</td></tr>`}</tbody></table></div></section>`);
  }

  async function openDelivery(tradeNo) {
    const delivery = dataOf(await api(`/user/distributor/delivery?trade_no=${encodeURIComponent(tradeNo)}`));
    state.modal = { type: 'delivery', delivery };
    state.closeArmed = false;
    renderModal();
    startPolling();
  }

  function renderModal() {
    let root = document.getElementById('dist-modal-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'dist-modal-root';
      document.body.appendChild(root);
    }
    if (!state.modal) {
      root.innerHTML = '';
      root.classList.remove('open');
      document.body.classList.remove('dist-modal-open');
      stopPolling();
      return;
    }
    root.classList.add('open');
    document.body.classList.add('dist-modal-open');
    if (state.modal.type === 'purchase') {
      const m = state.modal;
      root.innerHTML = `<div class="dist-modal-backdrop"><section class="dist-modal"><button class="dist-modal-x" data-modal-action="cancel">×</button><h2>${t('confirm')}</h2>
        <dl><div><dt>${t('plan')}</dt><dd>${escapeHtml(m.planName)}</dd></div><div><dt>${t('period')}</dt><dd>${escapeHtml(m.periodLabel)}</dd></div><div><dt>${t('original')}</dt><dd>${money(m.price)}</dd></div><div><dt>${t('status')}</dt><dd class="dist-free">${t('free')}</dd></div></dl>
        <div class="dist-modal-actions"><button data-modal-action="cancel">${t('cancel')}</button><button class="primary" data-modal-action="confirm-purchase">${t('confirm')}</button></div></section></div>`;
      return;
    }
    const delivery = state.modal.delivery;
    const claimed = delivery.delivery_status === 1;
    const pending = delivery.delivery_status === 0;
    root.innerHTML = `<div class="dist-modal-backdrop"><section class="dist-modal dist-qr-modal"><button class="dist-modal-x" data-modal-action="done">×</button><h2>${t('qrTitle')}</h2>
      <p>${pending ? t('qrHint') : claimed ? t('claimedOk') : t('closed')}</p>
      ${pending && delivery.qr_code ? `<div class="dist-qr"><img src="${escapeHtml(delivery.qr_code)}" alt="Subscription QR"></div>` : `<div class="dist-delivery-result">${claimed ? '✓' : '×'}<strong>${claimed ? t('claimed') : t('closed')}</strong></div>`}
      <div class="dist-order-ref">${t('orderNo')}：${escapeHtml(delivery.trade_no)}</div>
      ${state.closeArmed && pending ? `<div class="dist-warning">${t('closeWarning')}</div>` : ''}
      <div class="dist-modal-actions"><button class="primary" data-modal-action="done">${state.closeArmed && pending ? t('closeAgain') : t('done')}</button></div>
      </section></div>`;
  }

  function closeModal() {
    state.modal = null;
    state.closeArmed = false;
    renderModal();
  }

  async function handleDone() {
    if (!state.modal || state.modal.type !== 'delivery') { closeModal(); return; }
    const delivery = state.modal.delivery;
    if (delivery.delivery_status !== 0) { closeModal(); return; }
    if (!state.closeArmed) {
      state.closeArmed = true;
      renderModal();
      return;
    }
    try {
      const updated = dataOf(await api('/user/distributor/delivery/close', {
        method: 'POST', data: { trade_no: delivery.trade_no, confirm: 1 },
      }));
      state.modal.delivery = updated;
      closeModal();
      await renderPage();
    } catch (error) { toast(error.message, 'error'); }
  }

  function startPolling() {
    stopPolling();
    state.poller = setInterval(async () => {
      if (!state.modal || state.modal.type !== 'delivery' || state.modal.delivery.delivery_status !== 0) return;
      try {
        const updated = dataOf(await api(`/user/distributor/delivery?trade_no=${encodeURIComponent(state.modal.delivery.trade_no)}`));
        if (updated.delivery_status !== state.modal.delivery.delivery_status) {
          state.modal.delivery = updated;
          state.closeArmed = false;
          renderModal();
        }
      } catch (_) { /* keep the current QR visible during transient failures */ }
    }, 3000);
  }

  function stopPolling() {
    if (state.poller) clearInterval(state.poller);
    state.poller = null;
  }

  async function recoverPendingDelivery() {
    try {
      const delivery = dataOf(await api('/user/distributor/delivery'));
      if (delivery?.delivery_status === 0) {
        state.modal = { type: 'delivery', delivery };
        renderModal();
        startPolling();
      }
    } catch (error) {
      if (error.status !== 404) console.warn('Unable to recover distributor delivery', error);
    }
  }

  async function handleAction(target) {
    const nav = target.closest('[data-nav]');
    if (nav) { navigate(nav.dataset.nav); return; }
    const buy = target.closest('[data-buy]');
    if (buy) { confirmPurchase(buy.dataset.buy, buy.dataset.name); return; }
    const delivery = target.closest('[data-delivery]');
    if (delivery) { try { await openDelivery(delivery.dataset.delivery); } catch (e) { toast(e.message, 'error'); } return; }
    const copy = target.closest('[data-copy]');
    if (copy) { await navigator.clipboard.writeText(copy.dataset.copy); toast(t('success')); return; }
    const action = target.closest('[data-action]')?.dataset.action;
    if (action === 'logout') {
      localStorage.removeItem(TOKEN_KEY);
      window.location.href = '/#/login';
    } else if (action === 'language') {
      state.locale = state.locale === 'zh-CN' ? 'en-US' : 'zh-CN';
      localStorage.setItem('xboard_distributor_locale', state.locale);
      await renderPage();
      if (state.modal) renderModal();
    } else if (action === 'theme') {
      state.dark = !state.dark;
      localStorage.setItem('xboard_distributor_dark', state.dark ? '1' : '0');
      await renderPage();
      if (state.modal) renderModal();
    } else if (action === 'retry') {
      await renderPage();
    } else if (action === 'generate-code') {
      try { await api('/user/invite/save'); toast(t('success')); await renderInvite(); } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'transfer') {
      const yuan = Number(document.getElementById('transfer-amount')?.value || 0);
      if (yuan <= 0) return;
      try { await api('/user/transfer', { method: 'POST', data: { transfer_amount: Math.round(yuan * 100) } }); toast(t('success')); await renderInvite(); } catch (e) { toast(e.message, 'error'); }
    }
  }

  async function handleModalAction(target) {
    const action = target.closest('[data-modal-action]')?.dataset.modalAction;
    if (!action) return;
    if (action === 'confirm-purchase') await submitPurchase();
    else if (action === 'done') await handleDone();
    else if (action === 'cancel') closeModal();
  }

  function activate(user) {
    state.active = true;
    state.user = user;
    const normalApp = document.getElementById('app');
    if (normalApp) normalApp.style.display = 'none';
    let root = document.getElementById('distributor-app');
    if (!root) {
      root = document.createElement('div');
      root.id = 'distributor-app';
      document.body.appendChild(root);
    }
    document.documentElement.classList.add('distributor-mode');
    if (!['/plan', '/order', '/invite'].includes(currentPage())) navigate('/plan');
    renderPage();
    recoverPendingDelivery();
  }

  async function detectDistributor() {
    if (state.active || !authToken()) return;
    try {
      const user = dataOf(await api('/user/info'));
      if (user?.is_distributor) activate(user);
    } catch (_) { /* login may still be in progress */ }
  }

  document.addEventListener('click', (event) => {
    if (!state.active) return;
    if (event.target.closest('[data-modal-action]')) handleModalAction(event.target);
    else handleAction(event.target);
  });
  window.addEventListener('hashchange', () => { if (state.active) renderPage(); });
  window.addEventListener('beforeunload', (event) => {
    if (state.modal?.type === 'delivery' && state.modal.delivery.delivery_status === 0) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  const detector = setInterval(() => {
    if (state.active) clearInterval(detector);
    else detectDistributor();
  }, 300);
  detectDistributor();
})();
