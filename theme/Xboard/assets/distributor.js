(function () {
  'use strict';

  if (window.__xboardDistributorUi) return;
  window.__xboardDistributorUi = true;

  const TOKEN_KEY = 'VUE_NAIVE_ACCESS_TOKEN';
  const API_BASE = `${window.location.origin}${(window.routerBase || '/').replace(/\/$/, '')}/api/v1`;
  const PERIODS = [
    ['month_price', '月付', 'Monthly', 1],
    ['quarter_price', '季付', 'Quarterly', 3],
    ['half_year_price', '半年付', 'Half-year', 6],
    ['year_price', '年付', 'Yearly', 12],
    ['two_year_price', '两年付', 'Two-year', 24],
    ['three_year_price', '三年付', 'Three-year', 36],
    ['onetime_price', '一次性', 'One-time', 0],
  ];
  const COPY = {
    'zh-CN': {
      buy: '购买订阅', orders: '我的订单', invite: '我的邀请', knowledge: '使用文档', clients: '客户端下载', logout: '退出登录',
      title: '分销订阅中心', subtitle: '每个订单生成一份独立订阅，客户扫码领取后不可再次领取。',
      buyNow: '立即下单', original: '原价', free: '分销免支付', cancel: '取消',
      allPlans: '全部套餐', highTraffic: '大流量', unlimitedSpeed: '不限速', unlimitedDevices: '不限设备',
      featured: '精选套餐', traffic: '套餐流量', speed: '速度限制', devices: '同时在线', resetMethod: '流量重置',
      followSystem: '跟随系统', firstDayMonth: '每月1日', monthlyReset: '按月重置', neverReset: '不重置', firstDayYear: '每年1月1日', yearlyReset: '按年重置',
      perMonth: '折合 {price}/月', save: '省 {percent}%', oneTimeHint: '一次性交付',
      saved: '已省', orderAction: '已确认，直接下单', soldOut: '已售罄',
      promoStable: '稳定', promoFast: '高速', promoCompensation: '慢必赔',
      deliveryStepOne: '选择套餐并下单', deliveryStepTwo: '客户扫描二维码', deliveryStepThree: '确认节点可用',
      loading: '加载中…', empty: '暂无数据', settled: '已结算', unsettled: '未结算',
      pending: '待领取', claimed: '已领取', closed: '已关闭', showQr: '显示二维码',
      checkDelivery: '检查交付', issuing: '二维码已领取，正在等待订阅配置成功下发。',
      qrTitle: '客户订阅二维码', premiumCustomerQrTitle: '高端客户{customer}的订阅码', premiumCustomerQrTitleFallback: '高端客户的订阅码', qrHint: '请让终端客户使用订阅客户端扫描。二维码只能成功领取一次。',
      done: '已添加成功', closePopup: '关闭弹窗', buyAgain: '再次购买该套餐', planUnavailable: '套餐已下架或当前周期不可购买',
      claimedOk: '订阅已经领取，可以安全关闭。', orderNo: '订单号', amount: '订单金额', status: '订单状态',
      waitingConnection: '等待用户开启代理进入网络', connectedThrough: '客户已经通过 {node} 节点进入网络',
      settlement: '结算状态', plan: '订阅计划', period: '周期', created: '创建时间',
      remark: '备注', actions: '操作', customerName: '用户名称',
      boundDevices: '已绑定设备', unboundDevice: '尚未绑定设备', hwidDisabled: '未启用设备绑定',
      viewSubscriptionQr: '查看订阅二维码', subscriptionPending: '订阅尚未生成',
      subscriptionBoundDevice: '订阅已绑定设备', subscriptionUnboundDevice: '订阅尚未绑定设备',
      subscriptionHwidDisabled: '该订阅未启用设备绑定', copyImage: '复制图片', downloadImage: '下载图片',
      copyImageUnsupported: '当前浏览器不支持复制图片，请使用下载图片',
      entitlement: '订阅权益', totalTraffic: '总流量', usedTraffic: '已用流量', remainingTraffic: '剩余流量',
      expiresAt: '到期时间', speedLimit: '限速', deviceLimit: '设备限制', permanent: '长期有效', unlimited: '不限',
      inviteUsers: '已邀请用户', validCommission: '有效佣金', pendingCommission: '确认中佣金',
      rate: '佣金比例', availableCommission: '可用佣金', generateCode: '生成邀请码', transfer: '佣金划转余额',
      inviteCode: '邀请码', copy: '复制邀请链接', commissionHistory: '佣金记录', noCode: '暂无邀请码',
      success: '操作成功', language: '语言', dark: '深色模式', light: '浅色模式', account: '账号',
      settlementFilter: '结算状态', allSettlements: '全部', exportExcel: '导出 Excel', exportSuccess: 'Excel 导出成功',
      orderSearchPlaceholder: '输入订单号或用户名称查询', search: '查询', clear: '清空',
      knowledgeSubtitle: '查看产品使用方法与常见问题。', knowledgeSearchPlaceholder: '搜索使用文档',
      lastUpdated: '最后更新', noArticles: '暂无使用文档', copyShare: '复制分享', copySuccess: '复制成功', copyFailed: '复制失败，请重试',
    },
    'en-US': {
      buy: 'Buy Subscription', orders: 'My Orders', invite: 'My Invitations', knowledge: 'Documentation', clients: 'Client downloads', logout: 'Sign out',
      title: 'Distributor Center', subtitle: 'Each order creates an independent subscription that can be claimed once.',
      buyNow: 'Place order', original: 'Original price', free: 'Distributor — no online payment', cancel: 'Cancel',
      allPlans: 'All plans', highTraffic: 'High traffic', unlimitedSpeed: 'Unlimited speed', unlimitedDevices: 'Unlimited devices',
      featured: 'Featured', traffic: 'Traffic', speed: 'Speed', devices: 'Devices', resetMethod: 'Traffic reset',
      followSystem: 'System default', firstDayMonth: '1st of each month', monthlyReset: 'Monthly', neverReset: 'Never', firstDayYear: 'January 1st', yearlyReset: 'Yearly',
      perMonth: 'About {price}/month', save: 'Save {percent}%', oneTimeHint: 'One-time delivery',
      saved: 'Saved', orderAction: 'Confirmed — place order', soldOut: 'Sold out',
      promoStable: 'Stable', promoFast: 'Fast', promoCompensation: 'Performance guaranteed',
      deliveryStepOne: 'Choose and order', deliveryStepTwo: 'Customer scans QR', deliveryStepThree: 'Verify service',
      loading: 'Loading…', empty: 'No data', settled: 'Settled', unsettled: 'Unsettled',
      pending: 'Pending claim', claimed: 'Claimed', closed: 'Closed', showQr: 'Show QR',
      checkDelivery: 'Check delivery', issuing: 'The QR was claimed. Waiting for the subscription configuration response.',
      qrTitle: 'Customer subscription QR', premiumCustomerQrTitle: 'Premium customer {customer} subscription QR', premiumCustomerQrTitleFallback: 'Premium customer subscription QR', qrHint: 'Scan with the customer subscription client. This QR can only be claimed once.',
      done: 'Added successfully', closePopup: 'Close window', buyAgain: 'Buy this plan again', planUnavailable: 'This plan or billing period is no longer available',
      claimedOk: 'The subscription was claimed. It is safe to close.', orderNo: 'Order', amount: 'Amount', status: 'Status',
      waitingConnection: 'Waiting for the customer to enable the proxy', connectedThrough: 'Customer connected through {node}',
      settlement: 'Settlement', plan: 'Plan', period: 'Period', created: 'Created',
      remark: 'Remark', actions: 'Actions', customerName: 'Customer name',
      boundDevices: 'Bound devices', unboundDevice: 'No device bound', hwidDisabled: 'Device binding disabled',
      viewSubscriptionQr: 'View subscription QR', subscriptionPending: 'Subscription not generated',
      subscriptionBoundDevice: 'Subscription bound to device', subscriptionUnboundDevice: 'Subscription has no bound device',
      subscriptionHwidDisabled: 'Device binding is disabled for this subscription', copyImage: 'Copy image', downloadImage: 'Download image',
      copyImageUnsupported: 'This browser cannot copy images. Use Download image instead.',
      entitlement: 'Subscription entitlement', totalTraffic: 'Total traffic', usedTraffic: 'Used traffic', remainingTraffic: 'Remaining traffic',
      expiresAt: 'Expires at', speedLimit: 'Speed limit', deviceLimit: 'Device limit', permanent: 'Never expires', unlimited: 'Unlimited',
      inviteUsers: 'Invited users', validCommission: 'Valid commission', pendingCommission: 'Pending commission',
      rate: 'Commission rate', availableCommission: 'Available commission', generateCode: 'Generate code',
      transfer: 'Transfer commission', inviteCode: 'Invite code', copy: 'Copy invite link',
      commissionHistory: 'Commission history', noCode: 'No invite code', success: 'Success',
      language: 'Language', dark: 'Dark mode', light: 'Light mode', account: 'Account',
      settlementFilter: 'Settlement', allSettlements: 'All', exportExcel: 'Export Excel', exportSuccess: 'Excel exported',
      orderSearchPlaceholder: 'Search by order or customer name', search: 'Search', clear: 'Clear',
      knowledgeSubtitle: 'Browse product guides and frequently asked questions.', knowledgeSearchPlaceholder: 'Search documentation',
      lastUpdated: 'Last updated', noArticles: 'No documentation available', copyShare: 'Copy share link', copySuccess: 'Copied', copyFailed: 'Copy failed. Try again.',
    },
  };

  const state = {
    active: false,
    user: null,
    locale: localStorage.getItem('xboard_distributor_locale') || 'zh-CN',
    dark: localStorage.getItem('xboard_distributor_dark') === '1',
    loading: false,
    modal: null,
    poller: null,
    orderSettlementStatus: '',
    orderSearch: '',
    knowledgeSearch: '',
    plans: [],
    planFilter: 'all',
    selectedPeriods: {},
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
  async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(value);
        return;
      } catch (_) { /* fall back for browsers that deny Clipboard API access */ }
    }
    const input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    let copied = false;
    try {
      copied = document.execCommand('copy');
    } finally {
      input.remove();
    }
    if (!copied) throw new Error(t('copyFailed'));
  }
  const loadImage = (src) => new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error('Unable to render QR image'));
    image.src = src;
  });
  const canvasBlob = (canvas) => new Promise((resolve, reject) => {
    canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('Unable to create PNG')), 'image/png');
  });
  function wrapCanvasText(context, value, maxWidth) {
    const characters = Array.from(String(value || ''));
    const lines = [];
    let current = '';
    characters.forEach((character) => {
      const candidate = current + character;
      if (current && context.measureText(candidate).width > maxWidth) {
        lines.push(current);
        current = character;
      } else {
        current = candidate;
      }
    });
    if (current) lines.push(current);
    return lines.length ? lines : ['-'];
  }
  async function composeSubscriptionQrPng(payload) {
    const qrImage = await loadImage(payload.qr_code);
    const width = 760;
    const padding = 52;
    const qrSize = 540;
    const titleLineHeight = 43;
    const detailLineHeight = 31;
    const canvas = document.createElement('canvas');
    const measure = canvas.getContext('2d');
    const customerName = String(payload.customer_name || '').trim();
    const title = customerName
      ? t('premiumCustomerQrTitle').replace('{customer}', customerName)
      : t('premiumCustomerQrTitleFallback');
    measure.font = '800 32px "Microsoft YaHei", "PingFang SC", sans-serif';
    const titleLines = wrapCanvasText(measure, title, width - padding * 2);
    measure.font = '600 20px "Microsoft YaHei", "PingFang SC", sans-serif';
    const deviceTexts = !payload.hwid_enabled
      ? [t('subscriptionHwidDisabled')]
      : (payload.hwid_devices || []).length
        ? payload.hwid_devices.map((hwid) => `${t('subscriptionBoundDevice')} ${hwid}`)
        : [t('subscriptionUnboundDevice')];
    const detailLines = [`${t('orderNo')} ${payload.trade_no}`, ...deviceTexts]
      .flatMap((line) => wrapCanvasText(measure, line, width - padding * 2));
    const headerHeight = titleLines.length * titleLineHeight + 10 + detailLines.length * detailLineHeight + 20;
    canvas.width = width;
    canvas.height = padding + headerHeight + qrSize + padding;
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.textAlign = 'center';
    context.textBaseline = 'top';
    context.fillStyle = '#111827';
    context.font = '800 32px "Microsoft YaHei", "PingFang SC", sans-serif';
    let y = padding;
    titleLines.forEach((line) => {
      context.fillText(line, width / 2, y);
      y += titleLineHeight;
    });
    y += 10;
    context.font = '600 20px "Microsoft YaHei", "PingFang SC", sans-serif';
    detailLines.forEach((line) => {
      context.fillText(line, width / 2, y);
      y += detailLineHeight;
    });
    context.drawImage(qrImage, (width - qrSize) / 2, padding + headerHeight, qrSize, qrSize);
    return { imageUrl: canvas.toDataURL('image/png'), blob: await canvasBlob(canvas) };
  }
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
  const formatLimit = (value, unit) => value === null || Number(value) === 0 ? t('unlimited') : `${value} ${unit}`;
  const dataOf = (payload) => payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;

  const periodName = (key) => {
    const found = PERIODS.find(([period]) => period === key);
    if (!found) return key;
    return state.locale === 'zh-CN' ? found[1] : found[2];
  };
  const availablePeriods = (plan) => PERIODS.filter(([key]) => Number(plan[key]) > 0);
  const planHasUnlimitedSpeed = (plan) => plan.speed_limit === null || Number(plan.speed_limit) === 0;
  const planHasUnlimitedDevices = (plan) => plan.device_limit === null || Number(plan.device_limit) === 0;
  const planMatchesFilter = (plan, filter) => {
    if (filter === 'high-traffic') return Number(plan.transfer_enable) >= 100;
    if (filter === 'unlimited-speed') return planHasUnlimitedSpeed(plan);
    if (filter === 'unlimited-devices') return planHasUnlimitedDevices(plan);
    return true;
  };
  const resetMethodLabel = (method) => method === null || method === undefined ? t('followSystem') : ({
    0: t('firstDayMonth'), 1: t('monthlyReset'), 2: t('neverReset'),
    3: t('firstDayYear'), 4: t('yearlyReset'),
  })[Number(method)] || t('followSystem');
  const planSummary = (plan) => {
    const normalized = stripHtml(plan.content)
      .replace(/#{1,6}\s*/g, '')
      .replace(/(^|\n)\s*[-*]\s*/g, '$1')
      .replace(/\s+/g, ' ')
      .trim();
    const specWords = normalized.match(/流量|速度|设备|traffic|speed|device/gi) || [];
    if (normalized && !/套餐详情|服务说明/i.test(normalized) && specWords.length < 2) {
      return normalized.length > 110 ? `${normalized.slice(0, 108)}…` : normalized;
    }
    return state.locale === 'zh-CN'
      ? `独立订阅交付，包含 ${plan.transfer_enable} GB 套餐流量，客户扫码即可领取。`
      : `An independent subscription with ${plan.transfer_enable} GB of traffic, ready for customer claim.`;
  };
  const periodInsight = (plan, period) => {
    const found = PERIODS.find(([key]) => key === period);
    const months = found?.[3] || 0;
    const price = Number(plan[period]) || 0;
    if (!months) return t('oneTimeHint');
    const effective = price / months;
    const monthlyPrice = Number(plan.month_price) || 0;
    const saving = monthlyPrice > 0 ? Math.max(0, Math.round((1 - price / (monthlyPrice * months)) * 100)) : 0;
    const monthlyText = t('perMonth').replace('{price}', money(effective));
    return saving > 0 ? `${monthlyText} · ${t('save').replace('{percent}', saving)}` : monthlyText;
  };
  const periodSavings = (plan, period) => {
    const months = PERIODS.find(([key]) => key === period)?.[3] || 0;
    const monthlyPrice = Number(plan.month_price) || 0;
    const currentPrice = Number(plan[period]) || 0;
    if (!months || !monthlyPrice) return 0;
    return Math.max(0, (monthlyPrice * months) - currentPrice);
  };

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

  async function downloadFile(path) {
    const token = authToken();
    if (!token) throw new Error('Unauthorized');
    const response = await fetch(`${API_BASE}${path}`, {
      method: 'GET',
      headers: { Authorization: token, 'Content-Language': state.locale },
      cache: 'no-store',
      credentials: 'same-origin',
    });
    const contentType = response.headers?.get('Content-Type') || '';
    if (!response.ok || contentType.includes('application/json')) {
      const payload = await response.json().catch(() => null);
      throw new Error(payload?.message || `Export failed (${response.status})`);
    }

    const disposition = response.headers?.get('Content-Disposition') || '';
    const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
    const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
    const filename = encodedName ? decodeURIComponent(encodedName) : plainName || '我的分销订单.xlsx';
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
    if (state.orderSettlementStatus !== '') params.set('settlement_status', state.orderSettlementStatus);
    if (state.orderSearch) params.set('search', state.orderSearch);
    try {
      await downloadFile(`/user/order/export${params.size ? `?${params}` : ''}`);
      toast(t('exportSuccess'));
    } finally {
      if (button) button.disabled = false;
    }
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
    const hash = (window.location.hash || '#/plan').replace(/^#/, '');
    const [path, query = ''] = hash.split('?');
    if (path === '/knowledge' && new URLSearchParams(query).get('client-center') === '1') return '/clients';
    return ['/plan', '/order', '/invite', '/knowledge', '/clients'].includes(path) ? path : '/plan';
  }

  function navigate(path) {
    window.location.hash = path === '/clients' ? '#/knowledge?client-center=1' : `#${path}`;
  }

  function isCurrentRouteCanonical(page) {
    const hash = (window.location.hash || '#/plan').replace(/^#/, '');
    const [path, query = ''] = hash.split('?');
    if (page === '/clients') {
      return path === '/knowledge' && new URLSearchParams(query).get('client-center') === '1';
    }
    return path === page;
  }

  function shell(content) {
    const page = currentPage();
    const topbarPromo = page === '/plan' ? `<div class="dist-topbar-promo">
      <strong><span>${t('promoStable')}</span><span>${t('promoFast')}</span><span>${t('promoCompensation')}</span></strong>
      <div class="dist-topbar-steps">${[t('deliveryStepOne'), t('deliveryStepTwo'), t('deliveryStepThree')]
        .map((title, index) => `<small><b>0${index + 1}</b>${title}</small>`).join('')}</div>
    </div>` : '';
    return `
      <div class="dist-shell ${state.dark ? 'is-dark' : ''}">
        <aside class="dist-sidebar">
          <div class="dist-brand"><img class="dist-brand-mark" src="https://cloud.thinderbox.com/assets/branding/thinderbox-logo.png?v=39e70a98" alt="${escapeHtml(window.settings?.title || 'XBoard')} logo"><span>${escapeHtml(window.settings?.title || 'XBoard')}</span></div>
          <nav>
            <button data-nav="/plan" class="${page === '/plan' ? 'active' : ''}"><span>▣</span>${t('buy')}</button>
            <button data-nav="/order" class="${page === '/order' ? 'active' : ''}"><span>☷</span>${t('orders')}</button>
            <button data-nav="/invite" class="${page === '/invite' ? 'active' : ''}"><span>♧</span>${t('invite')}</button>
            <button data-nav="/knowledge" class="${page === '/knowledge' ? 'active' : ''}"><span>▤</span>${t('knowledge')}</button>
            <button data-nav="/clients" class="${page === '/clients' ? 'active' : ''}"><span>▦</span>${t('clients')}</button>
          </nav>
        </aside>
        <section class="dist-main">
          <header class="dist-topbar ${page === '/plan' ? 'has-promo' : ''}">
            <div class="dist-mobile-title">${escapeHtml(window.settings?.title || 'XBoard')}</div>
            ${topbarPromo}
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
    if (!isCurrentRouteCanonical(page)) {
      navigate(page);
      return;
    }
    loadingView();
    try {
      if (page === '/order') await renderOrders();
      else if (page === '/invite') await renderInvite();
      else if (page === '/knowledge') await renderKnowledge();
      else if (page === '/clients') await renderClients();
      else await renderPlans();
    } catch (error) {
      setContent(`<div class="dist-error"><h2>${escapeHtml(error.message)}</h2><button data-action="retry">${t('loading')}</button></div>`);
    }
  }

  async function fetchAvailablePlans() {
    return (dataOf(await api('/user/plan/fetch')) || []).filter((plan) => availablePeriods(plan).length);
  }

  async function renderPlans() {
    state.plans = await fetchAvailablePlans();
    state.plans.forEach((plan) => {
      if (!availablePeriods(plan).some(([key]) => key === state.selectedPeriods[plan.id])) {
        state.selectedPeriods[plan.id] = availablePeriods(plan)[0][0];
      }
    });
    if (!state.plans.some((plan) => planMatchesFilter(plan, state.planFilter))) state.planFilter = 'all';
    renderPlanCatalog();
  }

  function renderPlanCatalog() {
    const filters = [
      ['all', t('allPlans'), () => true],
      ['high-traffic', t('highTraffic'), (plan) => Number(plan.transfer_enable) >= 100],
      ['unlimited-speed', t('unlimitedSpeed'), planHasUnlimitedSpeed],
      ['unlimited-devices', t('unlimitedDevices'), planHasUnlimitedDevices],
    ].filter(([key, , matches]) => key === 'all' || state.plans.some(matches));
    const filterButtons = filters.map(([key, label]) => `<button type="button" data-plan-filter="${key}" class="${state.planFilter === key ? 'active' : ''}">${label}</button>`).join('');
    const visiblePlans = state.plans.filter((plan) => planMatchesFilter(plan, state.planFilter));
    const cards = visiblePlans.map((plan) => {
      const prices = availablePeriods(plan);
      const selectedPeriod = state.selectedPeriods[plan.id] || prices[0][0];
      const selectedPrice = Number(plan[selectedPeriod]) || 0;
      const selectedSaving = periodSavings(plan, selectedPeriod);
      const isFeatured = plan.id === state.plans[0]?.id;
      const soldOut = typeof plan.capacity_limit === 'string';
      const tags = [
        isFeatured ? t('featured') : '',
        Number(plan.transfer_enable) >= 100 ? t('highTraffic') : '',
        planHasUnlimitedSpeed(plan) ? t('unlimitedSpeed') : '',
        planHasUnlimitedDevices(plan) ? t('unlimitedDevices') : '',
      ].filter(Boolean).slice(0, 3).map((tag, index) => `<span class="${index === 0 && isFeatured ? 'primary' : ''}">${tag}</span>`).join('');
      const periodButtons = prices.map(([key]) => `<button type="button" role="radio" aria-checked="${selectedPeriod === key}" class="${selectedPeriod === key ? 'active' : ''}" data-plan-period="${key}" data-plan-id="${plan.id}"><span>${periodName(key)}</span><strong>${money(plan[key])}</strong><small>${periodInsight(plan, key)}</small></button>`).join('');
      return `<article class="dist-plan-card ${isFeatured ? 'is-featured' : ''}">
        <div class="dist-plan-body">
          <div class="dist-plan-tags">${tags}</div>
          <div class="dist-plan-heading">
            <div><h2>${escapeHtml(plan.name)}</h2><p>${escapeHtml(planSummary(plan))}</p></div>
            <div class="dist-plan-current-price"><small>${periodName(selectedPeriod)}</small><strong>${money(selectedPrice)}</strong></div>
          </div>
          <div class="dist-plan-specs">
            <div><span>${t('traffic')}</span><strong>${escapeHtml(plan.transfer_enable)} GB</strong></div>
            <div><span>${t('speed')}</span><strong>${planHasUnlimitedSpeed(plan) ? t('unlimited') : `${escapeHtml(plan.speed_limit)} Mbps`}</strong></div>
            <div><span>${t('devices')}</span><strong>${planHasUnlimitedDevices(plan) ? t('unlimited') : `${escapeHtml(plan.device_limit)} ${state.locale === 'zh-CN' ? '台' : ''}`}</strong></div>
            <div><span>${t('resetMethod')}</span><strong>${resetMethodLabel(plan.reset_traffic_method)}</strong></div>
          </div>
          <div class="dist-plan-period-label"><span>${t('period')}</span></div>
          <div class="dist-period-options" role="radiogroup" aria-label="${t('period')}">${periodButtons}</div>
        </div>
        <div class="dist-plan-actions">
          <button type="button" data-buy="${plan.id}" data-name="${escapeHtml(plan.name)}" ${soldOut ? 'disabled' : ''}>${soldOut ? t('soldOut') : `<span>${t('original')} ${money(selectedPrice)}</span><span>${t('saved')} ${money(selectedSaving)}</span><strong>${t('orderAction')}</strong>`}</button>
        </div>
      </article>`;
    }).join('');
    setContent(`<section class="dist-catalog-topbar">
        <div class="dist-plan-filters">${filterButtons}</div>
      </section>
      <div class="dist-plan-grid">${cards || `<div class="dist-empty">${t('empty')}</div>`}</div>
    `);
  }

  async function purchasePlan(plan, period, button) {
    if (!plan || typeof plan.capacity_limit === 'string' || !period || !availablePeriods(plan).some(([key]) => key === period) || Number(plan[period]) <= 0) {
      toast(t('planUnavailable'), 'error');
      return;
    }
    state.selectedPeriods[plan.id] = period;
    if (button) button.disabled = true;
    try {
      const tradeNo = dataOf(await api('/user/order/save', {
        method: 'POST', data: { plan_id: plan.id, period },
      }));
      await openDelivery(tradeNo);
    } catch (error) {
      toast(error.message, 'error');
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function repurchaseDelivery() {
    const delivery = state.modal?.type === 'delivery' ? state.modal.delivery : null;
    if (!delivery) return;
    if (!state.plans.length) state.plans = await fetchAvailablePlans();
    const plan = state.plans.find((item) => String(item.id) === String(delivery.plan_id));
    if (!plan || !availablePeriods(plan).some(([key]) => key === delivery.period) || Number(plan[delivery.period]) <= 0) {
      toast(t('planUnavailable'), 'error');
      return;
    }
    await purchasePlan(plan, delivery.period, document.querySelector('[data-modal-action="buy-again"]'));
  }

  async function renderOrders() {
    const params = new URLSearchParams();
    if (state.orderSettlementStatus !== '') params.set('settlement_status', state.orderSettlementStatus);
    if (state.orderSearch) params.set('search', state.orderSearch);
    const orders = dataOf(await api(`/user/order/fetch${params.size ? `?${params}` : ''}`)) || [];
    const rows = orders.map((order) => {
      const settlement = order.settlement_status === 1 ? t('settled') : t('unsettled');
      const entitlement = order.subscription_entitlement;
      const boundDevices = Array.isArray(order.bound_devices) ? order.bound_devices : [];
      const boundDeviceContent = !order.hwid_enabled
        ? `<span class="dist-device-state">${t('hwidDisabled')}</span>`
        : boundDevices.length
          ? `<div class="dist-bound-device-list">${boundDevices.map((hwid) => `<code>${escapeHtml(hwid)}</code>`).join('')}</div>`
          : `<span class="dist-device-state">${t('unboundDevice')}</span>`;
      const entitlementRow = entitlement ? `<tr class="dist-entitlement-row"><td colspan="8"><div><strong>${t('entitlement')}</strong><dl>
        <span><dt>${t('plan')}</dt><dd>${escapeHtml(entitlement.plan_name || order.plan?.name || '-')}</dd></span>
        <span><dt>${t('totalTraffic')}</dt><dd>${formatTraffic(entitlement.transfer_enable)}</dd></span>
        <span><dt>${t('usedTraffic')}</dt><dd>${formatTraffic(entitlement.used_traffic)}</dd></span>
        <span><dt>${t('remainingTraffic')}</dt><dd>${formatTraffic(entitlement.remaining_traffic)}</dd></span>
        <span><dt>${t('expiresAt')}</dt><dd>${entitlement.expired_at ? formatTime(entitlement.expired_at) : t('permanent')}</dd></span>
        <span><dt>${t('speedLimit')}</dt><dd>${formatLimit(entitlement.speed_limit, 'Mbps')}</dd></span>
        <span><dt>${t('deviceLimit')}</dt><dd>${formatLimit(entitlement.device_limit, state.locale === 'zh-CN' ? '台' : 'devices')}</dd></span>
        <span><dt>${t('boundDevices')}</dt><dd>${boundDeviceContent}</dd></span>
      </dl></div></td></tr>` : '';
      const qrAction = order.can_view_subscription_qr
        ? `<button class="dist-link-btn" data-subscription-qr="${escapeHtml(order.trade_no)}">${t('viewSubscriptionQr')}</button>`
        : `<span class="dist-action-disabled">${t('subscriptionPending')}</span>`;
      return `<tr>
        <td><strong>${escapeHtml(order.trade_no)}</strong><small>${formatTime(order.created_at)}</small></td>
        <td>${escapeHtml(order.customer_name || '-')}</td>
        <td>${escapeHtml(order.plan?.name || '-')}</td><td>${escapeHtml(periodLabel(order.period))}</td>
        <td>${money(order.total_amount)}<small class="dist-free">${t('free')}</small></td>
        <td><span class="dist-badge settle-${order.settlement_status}">${settlement}</span></td>
        <td><div class="dist-order-remark">${order.remark ? escapeHtml(order.remark) : '—'}</div></td>
        <td><div class="dist-order-actions">${qrAction}</div></td>
      </tr>${entitlementRow}`;
    }).join('');
    setContent(`<section class="dist-page-head"><h1>${t('orders')}</h1><p>${t('subtitle')}</p></section>
      <div class="dist-order-toolbar"><div class="dist-order-search"><input id="dist-order-search" type="search" maxlength="512" value="${escapeHtml(state.orderSearch)}" placeholder="${t('orderSearchPlaceholder')}"><button data-action="search-orders">${t('search')}</button><button class="secondary" data-action="clear-order-search" ${state.orderSearch ? '' : 'disabled'}>${t('clear')}</button></div><label>${t('settlementFilter')}<select id="dist-order-settlement"><option value="">${t('allSettlements')}</option><option value="0" ${state.orderSettlementStatus === '0' ? 'selected' : ''}>${t('unsettled')}</option><option value="1" ${state.orderSettlementStatus === '1' ? 'selected' : ''}>${t('settled')}</option></select></label><button data-action="export-orders">${t('exportExcel')}</button></div>
      <div class="dist-table-wrap"><table><thead><tr><th>${t('orderNo')}</th><th>${t('customerName')}</th><th>${t('plan')}</th><th>${t('period')}</th><th>${t('amount')}</th><th>${t('settlement')}</th><th>${t('remark')}</th><th>${t('actions')}</th></tr></thead>
      <tbody>${rows || `<tr><td colspan="8" class="dist-empty">${t('empty')}</td></tr>`}</tbody></table></div>`);
  }

  function periodLabel(period) {
    return periodName(period);
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

  async function renderKnowledge() {
    const params = new URLSearchParams({ language: state.locale });
    if (state.knowledgeSearch) params.set('keyword', state.knowledgeSearch);
    const grouped = dataOf(await api(`/user/knowledge/fetch?${params}`)) || {};
    const categories = Object.entries(grouped).map(([category, articles]) => {
      const items = (Array.isArray(articles) ? articles : []).map((article) => {
        const shareUrl = article.share_url || `${window.location.origin}/guide/${article.id}`;
        return `<div class="dist-knowledge-item">
          <button type="button" class="dist-knowledge-open" data-knowledge-id="${escapeHtml(article.id)}">
            <span><strong>${escapeHtml(article.title)}</strong><small>${t('lastUpdated')}：${formatTime(article.updated_at)}</small></span><b>›</b>
          </button>
          <button type="button" class="dist-knowledge-copy" data-copy="${escapeHtml(shareUrl)}" data-copy-success>${t('copyShare')}</button>
        </div>`;
      }).join('');
      return items ? `<section class="dist-knowledge-category"><h2>${escapeHtml(category)}</h2><div class="dist-knowledge-list">${items}</div></section>` : '';
    }).join('');
    setContent(`<section class="dist-page-head"><h1>${t('knowledge')}</h1><p>${t('knowledgeSubtitle')}</p></section>
      <div class="dist-knowledge-toolbar"><input id="dist-knowledge-search" type="search" maxlength="255" value="${escapeHtml(state.knowledgeSearch)}" placeholder="${t('knowledgeSearchPlaceholder')}"><button data-action="search-knowledge">${t('search')}</button><button class="secondary" data-action="clear-knowledge-search" ${state.knowledgeSearch ? '' : 'disabled'}>${t('clear')}</button></div>
      <div class="dist-knowledge-groups">${categories || `<div class="dist-panel dist-empty">${t('noArticles')}</div>`}</div>`);
  }

  async function renderClients() {
    setContent('<div class="xcc-host"><div class="xcc-loading">正在加载客户端目录…</div></div>');
    if (!window.XBoardClientCenter) throw new Error('Client center is unavailable');
    await window.XBoardClientCenter.mount(document.querySelector('.dist-content .xcc-host'));
  }

  async function openKnowledge(id) {
    const params = new URLSearchParams({ id, language: state.locale, render: 'html' });
    const article = dataOf(await api(`/user/knowledge/fetch?${params}`));
    if (!article) throw new Error(t('noArticles'));
    state.modal = { type: 'knowledge', article };
    renderModal();
  }

  async function makeDeliveryModal(delivery) {
    const png = delivery.delivery_status === 0 && delivery.qr_code
      ? await composeSubscriptionQrPng(delivery)
      : {};
    return { type: 'delivery', delivery, ...png, copied: false };
  }

  async function openDelivery(tradeNo) {
    const delivery = dataOf(await api(`/user/distributor/delivery?trade_no=${encodeURIComponent(tradeNo)}`));
    state.modal = await makeDeliveryModal(delivery);
    renderModal();
    startPolling();
  }

  async function openSubscriptionQr(tradeNo) {
    const payload = dataOf(await api(`/user/distributor/subscription-qr?trade_no=${encodeURIComponent(tradeNo)}`));
    const png = await composeSubscriptionQrPng(payload);
    state.modal = { type: 'subscriptionQr', payload, ...png, copied: false };
    renderModal();
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
      document.documentElement.classList.remove('dist-modal-open');
      document.body.classList.remove('dist-modal-open');
      stopPolling();
      return;
    }
    root.classList.add('open');
    document.documentElement.classList.add('dist-modal-open');
    document.body.classList.add('dist-modal-open');
    if (state.modal.type === 'knowledge') {
      const article = state.modal.article;
      root.innerHTML = `<div class="dist-modal-backdrop"><article class="dist-modal dist-knowledge-modal"><button class="dist-modal-x" data-modal-action="cancel">×</button><h2>${escapeHtml(article.title)}</h2><button type="button" class="dist-knowledge-share" data-copy="${escapeHtml(article.share_url || `${window.location.origin}/guide/${article.id}`)}">复制分享链接</button><div class="dist-knowledge-updated">${t('lastUpdated')}：${formatTime(article.updated_at)}</div><div class="dist-knowledge-body">${article.body || ''}</div></article></div>`;
      return;
    }
    if (state.modal.type === 'subscriptionQr') {
      const modal = state.modal;
      root.innerHTML = `<div class="dist-modal-backdrop"><section class="dist-modal dist-subscription-qr-modal"><button class="dist-modal-x" data-modal-action="cancel">×</button><h2>${t('viewSubscriptionQr')}</h2>
        <img class="dist-subscription-qr-preview" src="${modal.imageUrl}" alt="${escapeHtml(t('viewSubscriptionQr'))}">
        <div class="dist-modal-actions dist-image-actions"><button data-modal-action="copy-subscription-qr">${modal.copied ? t('copySuccess') : t('copyImage')}</button><button class="primary" data-modal-action="download-subscription-qr">${t('downloadImage')}</button></div>
      </section></div>`;
      return;
    }
    const modal = state.modal;
    const delivery = modal.delivery;
    const claimed = delivery.delivery_status === 1;
    const pending = delivery.delivery_status === 0;
    const issued = Boolean(delivery.config_issued_at);
    const connected = Boolean(delivery.connected_at);
    const connectionText = connected
      ? t('connectedThrough').replace('{node}', delivery.connected_node_name || '-')
      : issued ? t('waitingConnection') : '';
    root.innerHTML = `<div class="dist-modal-backdrop"><section class="dist-modal dist-qr-modal"><button class="dist-modal-x" data-modal-action="close-delivery">×</button><h2>${t('qrTitle')}</h2>
      <p>${pending ? t('qrHint') : claimed && issued ? t('claimedOk') : claimed ? t('issuing') : t('closed')}</p>
      ${pending && modal.imageUrl ? `<img class="dist-subscription-qr-preview" src="${modal.imageUrl}" alt="${escapeHtml(t('viewSubscriptionQr'))}"><div class="dist-modal-actions dist-image-actions"><button data-modal-action="copy-subscription-qr">${modal.copied ? t('copySuccess') : t('copyImage')}</button><button class="primary" data-modal-action="download-subscription-qr">${t('downloadImage')}</button></div>` : `<div class="dist-delivery-result">${claimed && issued ? '✓' : claimed ? '…' : '×'}<strong>${claimed && issued ? t('claimed') : claimed ? t('issuing') : t('closed')}</strong>${claimed && issued ? `<small class="dist-network-status">${escapeHtml(connectionText)}</small>` : ''}</div>`}
      <div class="dist-modal-actions"><button data-modal-action="buy-again">${t('buyAgain')}</button><button class="primary" data-modal-action="close-delivery">${t('closePopup')}</button></div>
      </section></div>`;
  }

  function closeModal() {
    state.modal = null;
    renderModal();
  }

  function startPolling() {
    stopPolling();
    state.poller = setInterval(async () => {
      if (!state.modal || state.modal.type !== 'delivery' || state.modal.delivery.delivery_status === 2 || state.modal.delivery.connected_at) return;
      try {
        const currentTradeNo = state.modal.delivery.trade_no;
        const updated = dataOf(await api(`/user/distributor/delivery?trade_no=${encodeURIComponent(currentTradeNo)}`));
        const devicesChanged = JSON.stringify(updated.hwid_devices || []) !== JSON.stringify(state.modal.delivery.hwid_devices || []);
        if (updated.delivery_status !== state.modal.delivery.delivery_status || updated.config_issued_at !== state.modal.delivery.config_issued_at || updated.connected_at !== state.modal.delivery.connected_at || devicesChanged) {
          const nextModal = await makeDeliveryModal(updated);
          if (!state.modal || state.modal.type !== 'delivery' || state.modal.delivery.trade_no !== currentTradeNo) return;
          state.modal = nextModal;
          renderModal();
        }
      } catch (_) { /* keep the current QR visible during transient failures */ }
    }, 3000);
  }

  function stopPolling() {
    if (state.poller) clearInterval(state.poller);
    state.poller = null;
  }

  async function handleAction(target) {
    const nav = target.closest('[data-nav]');
    if (nav) { navigate(nav.dataset.nav); return; }
    const filter = target.closest('[data-plan-filter]');
    if (filter) {
      state.planFilter = filter.dataset.planFilter;
      renderPlanCatalog();
      return;
    }
    const period = target.closest('[data-plan-period]');
    if (period) {
      state.selectedPeriods[period.dataset.planId] = period.dataset.planPeriod;
      renderPlanCatalog();
      return;
    }
    const buy = target.closest('[data-buy]');
    if (buy) {
      const plan = state.plans.find((item) => String(item.id) === String(buy.dataset.buy));
      await purchasePlan(plan, state.selectedPeriods[buy.dataset.buy], buy);
      return;
    }
    const subscriptionQr = target.closest('[data-subscription-qr]');
    if (subscriptionQr) { try { await openSubscriptionQr(subscriptionQr.dataset.subscriptionQr); } catch (e) { toast(e.message, 'error'); } return; }
    const knowledge = target.closest('[data-knowledge-id]');
    if (knowledge) { try { await openKnowledge(knowledge.dataset.knowledgeId); } catch (e) { toast(e.message, 'error'); } return; }
    const copy = target.closest('[data-copy]');
    if (copy) {
      try {
        await copyText(copy.dataset.copy);
        if (copy.hasAttribute('data-copy-success')) {
          const original = copy.textContent;
          copy.textContent = t('copySuccess');
          copy.classList.add('copied');
          copy.disabled = true;
          window.setTimeout(() => {
            if (!copy.isConnected) return;
            copy.textContent = original;
            copy.classList.remove('copied');
            copy.disabled = false;
          }, 1800);
        } else {
          toast(t('success'));
        }
      } catch (_) {
        toast(t('copyFailed'), 'error');
      }
      return;
    }
    const action = target.closest('[data-action]')?.dataset.action;
    if (action === 'logout') {
      localStorage.removeItem(TOKEN_KEY);
      window.location.href = '/#/login';
    } else if (action === 'language') {
      state.locale = state.locale === 'zh-CN' ? 'en-US' : 'zh-CN';
      localStorage.setItem('xboard_distributor_locale', state.locale);
      if (state.modal?.type === 'knowledge') closeModal();
      await renderPage();
      if (state.modal) renderModal();
    } else if (action === 'theme') {
      state.dark = !state.dark;
      localStorage.setItem('xboard_distributor_dark', state.dark ? '1' : '0');
      await renderPage();
      if (state.modal) renderModal();
    } else if (action === 'retry') {
      await renderPage();
    } else if (action === 'export-orders') {
      try { await exportOrders(target.closest('[data-action]')); } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'search-orders') {
      state.orderSearch = document.getElementById('dist-order-search')?.value.trim() || '';
      try { await renderOrders(); } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'clear-order-search') {
      state.orderSearch = '';
      try { await renderOrders(); } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'search-knowledge') {
      state.knowledgeSearch = document.getElementById('dist-knowledge-search')?.value.trim() || '';
      try { await renderKnowledge(); } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'clear-knowledge-search') {
      state.knowledgeSearch = '';
      try { await renderKnowledge(); } catch (e) { toast(e.message, 'error'); }
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
    if (action === 'close-delivery') closeModal();
    else if (action === 'buy-again') {
      try { await repurchaseDelivery(); } catch (error) { toast(error.message, 'error'); }
    }
    else if (action === 'copy-subscription-qr') {
      if (!state.modal || !['subscriptionQr', 'delivery'].includes(state.modal.type) || !state.modal.blob) return;
      if (!navigator.clipboard?.write || typeof ClipboardItem === 'undefined' || !window.isSecureContext) {
        toast(t('copyImageUnsupported'), 'error');
        return;
      }
      try {
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': state.modal.blob })]);
        state.modal.copied = true;
        renderModal();
        window.setTimeout(() => {
          if (!state.modal || !['subscriptionQr', 'delivery'].includes(state.modal.type)) return;
          state.modal.copied = false;
          renderModal();
        }, 1800);
      } catch (_) { toast(t('copyImageUnsupported'), 'error'); }
    }
    else if (action === 'download-subscription-qr') {
      if (!state.modal || !['subscriptionQr', 'delivery'].includes(state.modal.type) || !state.modal.blob) return;
      const url = URL.createObjectURL(state.modal.blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `订阅二维码-${state.modal.payload?.trade_no || state.modal.delivery?.trade_no}.png`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 0);
    }
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
    if (!['/plan', '/order', '/invite', '/knowledge', '/clients'].includes(currentPage())) navigate('/plan');
    renderPage();
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
  document.addEventListener('change', (event) => {
    if (!state.active || event.target.id !== 'dist-order-settlement') return;
    state.orderSettlementStatus = event.target.value;
    renderPage();
  });
  document.addEventListener('keydown', (event) => {
    if (!state.active || event.key !== 'Enter') return;
    if (event.target.id === 'dist-order-search') {
      event.preventDefault();
      state.orderSearch = event.target.value.trim();
      renderOrders().catch((error) => toast(error.message, 'error'));
    } else if (event.target.id === 'dist-knowledge-search') {
      event.preventDefault();
      state.knowledgeSearch = event.target.value.trim();
      renderKnowledge().catch((error) => toast(error.message, 'error'));
    }
  });
  window.addEventListener('hashchange', () => { if (state.active) renderPage(); });
  const detector = setInterval(() => {
    if (state.active) clearInterval(detector);
    else detectDistributor();
  }, 300);
  detectDistributor();
})();
