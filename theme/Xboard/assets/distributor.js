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
  const PURCHASE_PERIOD_PRESENTATION = {
    month_price: ['一个月', '31天'],
    quarter_price: ['3个月', '93天'],
    half_year_price: ['半年', '185天'],
    year_price: ['1年', '366天'],
  };
  const COPY = {
    'zh-CN': {
      buy: '购买订阅', overview: '经营概览', orders: '我的订单', invite: '我的邀请', knowledge: '使用文档', clients: '客户端下载', logout: '退出登录',
      title: '分销订阅中心', subtitle: '每个订单生成一份独立订阅，客户扫码领取后不可再次领取。',
      buyNow: '立即下单', original: '原价', free: '分销免支付', cancel: '取消',
      allPlans: '全部套餐', highTraffic: '大流量', unlimitedSpeed: '不限速', unlimitedDevices: '不限设备',
      featured: '精选套餐', traffic: '套餐流量', speed: '速度限制', devices: '同时在线', resetMethod: '流量重置',
      followSystem: '跟随系统', firstDayMonth: '每月1日', monthlyReset: '按月重置', neverReset: '不重置', firstDayYear: '每年1月1日', yearlyReset: '按年重置',
      perMonth: '折合 {price}/月', save: '省 {percent}%', oneTimeHint: '一次性交付',
      saved: '已省', orderAction: '已确认，直接下单', soldOut: '已售罄',
      showPrices: '显示价格', hidePrices: '隐藏价格', retry: '重试',
      promoStable: '稳定', promoFast: '高速', promoCompensation: '慢必赔',
      deliveryStepOne: '选择套餐并下单', deliveryStepTwo: '客户扫描二维码', deliveryStepThree: '确认节点可用',
      loading: '加载中…', empty: '暂无数据', settled: '已结算', unsettled: '未结算',
      pending: '待领取', claimed: '已领取', closed: '已关闭', showQr: '显示二维码',
      checkDelivery: '检查交付', issuing: '二维码已领取，正在等待订阅配置成功下发。',
      qrTitle: '客户订阅二维码', premiumCustomerQrTitle: '高端客户{customer}的订阅码', premiumCustomerQrTitleFallback: '高端客户的订阅码', qrHint: '请让终端客户使用订阅客户端扫描。二维码只能成功领取一次。',
      done: '已添加成功', closePopup: '关闭弹窗', buyAgain: '再次购买该套餐', planUnavailable: '套餐已下架或当前周期不可购买',
      claimedOk: '订阅已经领取，可以安全关闭。', sequence: '序号', orderNo: '订单号', orderTime: '下单时间', amount: '订单金额', status: '订单状态',
      waitingConnection: '等待用户开启代理进入网络', connectedThrough: '客户已经通过 {node} 节点进入网络',
      settlement: '结算状态', plan: '订阅计划', period: '周期', created: '创建时间',
      remark: '备注', actions: '操作', customerName: '用户名称',
      boundDevices: '已绑定设备', unboundDevice: '尚未绑定设备', hwidDisabled: '未启用设备绑定',
      viewSubscriptionQr: '查看订阅二维码', subscriptionQrAction: '二维码', subscriptionPending: '订阅尚未生成',
      subscriptionBoundDevice: '订阅已绑定设备', subscriptionUnboundDevice: '订阅尚未绑定设备',
      subscriptionHwidDisabled: '该订阅未启用设备绑定', copyImage: '复制图片', downloadImage: '下载图片',
      copyImageUnsupported: '当前浏览器不支持复制图片，请使用下载图片',
      entitlement: '订阅权益', viewEntitlement: '查看订阅权益', hideEntitlement: '收起订阅权益', entitlementAction: '权益', totalTraffic: '总流量', usedTraffic: '已用流量', remainingTraffic: '剩余流量',
      expiresAt: '到期时间', speedLimit: '限速', deviceLimit: '设备限制', permanent: '长期有效', unlimited: '不限',
      renew: '续费', renewTitle: '续费现有订阅', renewHint: '续费后订阅链接、二维码、UUID 和已绑定设备保持不变。',
      renewPeriod: '续费周期', renewCurrentExpiry: '当前到期时间', renewConfirm: '确认续费', renewSuccess: '续费成功',
      renewOrder: '续费订单', renewNewExpiry: '新的到期时间', orderType: '订单类型', originalOrder: '关联原订单', newPurchase: '新购',
      inviteUsers: '已邀请用户', validCommission: '有效佣金', pendingCommission: '确认中佣金',
      rate: '佣金比例', availableCommission: '可用佣金', generateCode: '生成邀请码', transfer: '佣金划转余额',
      inviteCode: '邀请码', copy: '复制邀请链接', commissionHistory: '佣金记录', noCode: '暂无邀请码',
      success: '操作成功', language: '语言', dark: '深色模式', light: '浅色模式', account: '账号',
      settlementFilter: '结算状态', allSettlements: '全部', exportExcel: '导出 Excel', exportSuccess: 'Excel 导出成功',
      orderSearchPlaceholder: '输入订单号或用户名称查询', search: '查询', clear: '清空',
      orderOverview: '订单经营概览', orderOverviewHint: '统计与订单筛选相互独立，收入为所选时间内订单金额的累计值。',
      todayIncome: '今日收入', todayOrders: '今日订单', yesterdayIncome: '昨日收入', yesterdayOrders: '昨日订单',
      income: '收入', orderCount: '订单', customRange: '自定义', apply: '应用', advancedFilters: '高级筛选', hideFilters: '收起筛选',
      startDate: '开始日期', endDate: '结束日期', minimumAmount: '最低金额', maximumAmount: '最高金额', allPeriods: '全部周期',
      trend: '增长趋势', thisWeek: '本周', thisMonth: '本月', lastNinetyDays: '近三个月', dailyOrders: '每日订单量', dailyIncome: '每日收入',
      firstPage: '首页', previousPage: '上一页', nextPage: '下一页', lastPage: '尾页', jumpTo: '跳至', jump: '跳转', loadMore: '加载更多', pageSize: '每页', totalOrders: '共 {count} 个订单',
      viewAllDevices: '查看全部 {count} 个', collapseDevices: '收起设备', filterReset: '重置筛选',
      knowledgeSubtitle: '查看产品使用方法与常见问题。', knowledgeSearchPlaceholder: '搜索使用文档',
      lastUpdated: '最后更新', noArticles: '暂无使用文档', copyShare: '复制分享', copySuccess: '复制成功', copyFailed: '复制失败，请重试',
    },
    'en-US': {
      buy: 'Buy Subscription', overview: 'Business Overview', orders: 'My Orders', invite: 'My Invitations', knowledge: 'Documentation', clients: 'Client downloads', logout: 'Sign out',
      title: 'Distributor Center', subtitle: 'Each order creates an independent subscription that can be claimed once.',
      buyNow: 'Place order', original: 'Original price', free: 'Distributor — no online payment', cancel: 'Cancel',
      allPlans: 'All plans', highTraffic: 'High traffic', unlimitedSpeed: 'Unlimited speed', unlimitedDevices: 'Unlimited devices',
      featured: 'Featured', traffic: 'Traffic', speed: 'Speed', devices: 'Devices', resetMethod: 'Traffic reset',
      followSystem: 'System default', firstDayMonth: '1st of each month', monthlyReset: 'Monthly', neverReset: 'Never', firstDayYear: 'January 1st', yearlyReset: 'Yearly',
      perMonth: 'About {price}/month', save: 'Save {percent}%', oneTimeHint: 'One-time delivery',
      saved: 'Saved', orderAction: 'Confirmed — place order', soldOut: 'Sold out',
      showPrices: 'Show prices', hidePrices: 'Hide prices', retry: 'Retry',
      promoStable: 'Stable', promoFast: 'Fast', promoCompensation: 'Performance guaranteed',
      deliveryStepOne: 'Choose and order', deliveryStepTwo: 'Customer scans QR', deliveryStepThree: 'Verify service',
      loading: 'Loading…', empty: 'No data', settled: 'Settled', unsettled: 'Unsettled',
      pending: 'Pending claim', claimed: 'Claimed', closed: 'Closed', showQr: 'Show QR',
      checkDelivery: 'Check delivery', issuing: 'The QR was claimed. Waiting for the subscription configuration response.',
      qrTitle: 'Customer subscription QR', premiumCustomerQrTitle: 'Premium customer {customer} subscription QR', premiumCustomerQrTitleFallback: 'Premium customer subscription QR', qrHint: 'Scan with the customer subscription client. This QR can only be claimed once.',
      done: 'Added successfully', closePopup: 'Close window', buyAgain: 'Buy this plan again', planUnavailable: 'This plan or billing period is no longer available',
      claimedOk: 'The subscription was claimed. It is safe to close.', sequence: 'No.', orderNo: 'Order', orderTime: 'Order time', amount: 'Amount', status: 'Status',
      waitingConnection: 'Waiting for the customer to enable the proxy', connectedThrough: 'Customer connected through {node}',
      settlement: 'Settlement', plan: 'Plan', period: 'Period', created: 'Created',
      remark: 'Remark', actions: 'Actions', customerName: 'Customer name',
      boundDevices: 'Bound devices', unboundDevice: 'No device bound', hwidDisabled: 'Device binding disabled',
      viewSubscriptionQr: 'View subscription QR', subscriptionQrAction: 'QR', subscriptionPending: 'Subscription not generated',
      subscriptionBoundDevice: 'Subscription bound to device', subscriptionUnboundDevice: 'Subscription has no bound device',
      subscriptionHwidDisabled: 'Device binding is disabled for this subscription', copyImage: 'Copy image', downloadImage: 'Download image',
      copyImageUnsupported: 'This browser cannot copy images. Use Download image instead.',
      entitlement: 'Subscription entitlement', viewEntitlement: 'View subscription entitlement', hideEntitlement: 'Hide subscription entitlement', entitlementAction: 'Entitlement', totalTraffic: 'Total traffic', usedTraffic: 'Used traffic', remainingTraffic: 'Remaining traffic',
      expiresAt: 'Expires at', speedLimit: 'Speed limit', deviceLimit: 'Device limit', permanent: 'Never expires', unlimited: 'Unlimited',
      renew: 'Renew', renewTitle: 'Renew subscription', renewHint: 'The subscription URL, QR code, UUID, and bound devices will stay unchanged.',
      renewPeriod: 'Renewal period', renewCurrentExpiry: 'Current expiry', renewConfirm: 'Confirm renewal', renewSuccess: 'Renewed',
      renewOrder: 'Renewal order', renewNewExpiry: 'New expiry', orderType: 'Order type', originalOrder: 'Original order', newPurchase: 'New purchase',
      inviteUsers: 'Invited users', validCommission: 'Valid commission', pendingCommission: 'Pending commission',
      rate: 'Commission rate', availableCommission: 'Available commission', generateCode: 'Generate code',
      transfer: 'Transfer commission', inviteCode: 'Invite code', copy: 'Copy invite link',
      commissionHistory: 'Commission history', noCode: 'No invite code', success: 'Success',
      language: 'Language', dark: 'Dark mode', light: 'Light mode', account: 'Account',
      settlementFilter: 'Settlement', allSettlements: 'All', exportExcel: 'Export Excel', exportSuccess: 'Excel exported',
      orderSearchPlaceholder: 'Search by order or customer name', search: 'Search', clear: 'Clear',
      orderOverview: 'Order performance', orderOverviewHint: 'Analytics are independent from list filters. Income is the sum of order amounts in the selected dates.',
      todayIncome: "Today's income", todayOrders: "Today's orders", yesterdayIncome: "Yesterday's income", yesterdayOrders: "Yesterday's orders",
      income: 'Income', orderCount: 'orders', customRange: 'Custom', apply: 'Apply', advancedFilters: 'More filters', hideFilters: 'Hide filters',
      startDate: 'Start date', endDate: 'End date', minimumAmount: 'Minimum amount', maximumAmount: 'Maximum amount', allPeriods: 'All periods',
      trend: 'Growth trend', thisWeek: 'This week', thisMonth: 'This month', lastNinetyDays: 'Last 90 days', dailyOrders: 'Daily orders', dailyIncome: 'Daily income',
      firstPage: 'First', previousPage: 'Previous', nextPage: 'Next', lastPage: 'Last', jumpTo: 'Go to', jump: 'Go', loadMore: 'Load more', pageSize: 'Per page', totalOrders: '{count} orders',
      viewAllDevices: 'View all {count}', collapseDevices: 'Collapse devices', filterReset: 'Reset filters',
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
    modalTrigger: null,
    poller: null,
    orderSettlementStatus: '',
    orderSearch: '',
    orderFiltersOpen: false,
    orderFilters: { startDate: '', endDate: '', periods: [], minAmount: '', maxAmount: '' },
    orderPage: 1,
    orderPerPage: 20,
    orderTotal: 0,
    orderLastPage: 1,
    expandedDeviceOrders: {},
    orderSummaryRange: null,
    orderTrendPreset: 'week',
    orderTrendRange: null,
    orderSummary: null,
    orderTrend: null,
    knowledgeSearch: '',
    plans: [],
    planFilter: 'all',
    selectedPeriods: {},
    purchasingPlanId: null,
    planPricesVisible: false,
    planPricesTimer: null,
    renderGeneration: 0,
    analyticsRequestToken: 0,
    orders: [],
  };

  const t = (key) => (COPY[state.locale] || COPY['zh-CN'])[key] || key;
  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  function distributorAccountLabel(user) {
    const distributorName = String(user?.distributor_name ?? '').trim();
    return distributorName || String(user?.email ?? '').trim();
  }
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

  function shanghaiDate() {
    const parts = new Intl.DateTimeFormat('en', {
      timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
    }).formatToParts(new Date());
    const value = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return `${value.year}-${value.month}-${value.day}`;
  }

  function shiftDate(date, days) {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);
    return value.toISOString().slice(0, 10);
  }

  function dateRangeDays(startDate, endDate) {
    return Math.round((Date.parse(`${endDate}T00:00:00Z`) - Date.parse(`${startDate}T00:00:00Z`)) / 86400000) + 1;
  }

  function defaultTrendRange(preset = 'week') {
    const today = shanghaiDate();
    if (preset === 'month') return { startDate: `${today.slice(0, 7)}-01`, endDate: today };
    if (preset === 'ninety') return { startDate: shiftDate(today, -89), endDate: today };
    const weekday = new Date(`${today}T00:00:00Z`).getUTCDay();
    return { startDate: shiftDate(today, -((weekday + 6) % 7)), endDate: today };
  }

  function appendOrderFilters(params) {
    const filters = state.orderFilters;
    if (filters.startDate) params.set('start_date', filters.startDate);
    if (filters.endDate) params.set('end_date', filters.endDate);
    filters.periods.forEach((period) => params.append('periods[]', period));
    if (filters.minAmount) params.set('min_amount', filters.minAmount);
    if (filters.maxAmount) params.set('max_amount', filters.maxAmount);
  }

  async function exportOrders(button) {
    if (button) button.disabled = true;
    const params = new URLSearchParams();
    if (state.orderSettlementStatus !== '') params.set('settlement_status', state.orderSettlementStatus);
    if (state.orderSearch) params.set('search', state.orderSearch);
    appendOrderFilters(params);
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
    return ['/plan', '/overview', '/order', '/invite', '/knowledge', '/clients'].includes(path) ? path : '/plan';
  }

  function navigate(path) {
    if (path !== '/plan') closePlanPrices(false);
    const targetHash = path === '/clients' ? '#/knowledge?client-center=1' : `#${path}`;
    if (path === '/overview') {
      if (window.location.hash !== targetHash) {
        window.history.pushState({ ...(window.history.state || {}), distributorRoute: path }, '', targetHash);
      }
      renderPage();
      return;
    }
    window.location.hash = targetHash;
  }

  function beginRouteRender(route) {
    return { route, generation: ++state.renderGeneration };
  }

  function isCurrentRouteRender(renderContext) {
    return Boolean(renderContext)
      && state.active
      && renderContext.generation === state.renderGeneration
      && currentPage() === renderContext.route;
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
            <button type="button" data-nav="/plan" class="${page === '/plan' ? 'active' : ''}"><span>▣</span>${t('buy')}</button>
            <button type="button" data-nav="/overview" class="${page === '/overview' ? 'active' : ''}"><span>⌁</span>${t('overview')}</button>
            <button type="button" data-nav="/order" class="${page === '/order' ? 'active' : ''}"><span>☷</span>${t('orders')}</button>
            <button type="button" data-nav="/invite" class="${page === '/invite' ? 'active' : ''}"><span>♧</span>${t('invite')}</button>
            <button type="button" data-nav="/knowledge" class="${page === '/knowledge' ? 'active' : ''}"><span>▤</span>${t('knowledge')}</button>
            <button type="button" data-nav="/clients" class="${page === '/clients' ? 'active' : ''}"><span>▦</span>${t('clients')}</button>
          </nav>
          <button type="button" class="dist-mobile-nav-next" data-action="scroll-mobile-nav" aria-label="向右查看更多菜单">》</button>
        </aside>
        <section class="dist-main">
          <header class="dist-topbar ${page === '/plan' ? 'has-promo' : ''}">
            <div class="dist-mobile-title">${escapeHtml(window.settings?.title || 'XBoard')}</div>
            ${topbarPromo}
            <div class="dist-top-actions">
              <button data-action="theme" title="${state.dark ? t('light') : t('dark')}">${state.dark ? '☀' : '◐'}</button>
              <button data-action="language" title="${t('language')}">${state.locale === 'zh-CN' ? '中' : 'EN'}</button>
              <span class="dist-account">● ${escapeHtml(distributorAccountLabel(state.user))}</span>
              <button class="dist-logout" data-action="logout">${t('logout')}</button>
            </div>
          </header>
          <main class="dist-content">${content}</main>
        </section>
      </div>`;
  }

  function setContent(content) {
    const root = document.getElementById('distributor-app');
    if (!root) return;
    root.innerHTML = shell(content);
    window.requestAnimationFrame(() => {
      const nav = root.querySelector('.dist-sidebar nav');
      const active = nav?.querySelector('button.active');
      if (!nav) return;
      const updateNextState = () => updateMobileNavNextState(nav);
      nav.addEventListener('scroll', updateNextState, { passive: true });
      if (active && nav.scrollWidth > nav.clientWidth) {
        const left = active.offsetLeft;
        const right = left + active.offsetWidth;
        if (left < nav.scrollLeft) nav.scrollLeft = left;
        else if (right > nav.scrollLeft + nav.clientWidth) nav.scrollLeft = right - nav.clientWidth;
      }
      updateNextState();
    });
  }

  function updateMobileNavNextState(nav = document.querySelector('.dist-sidebar nav')) {
    const next = document.querySelector('.dist-mobile-nav-next');
    if (!nav || !next) return;
    next.disabled = nav.scrollLeft + nav.clientWidth >= nav.scrollWidth - 1;
  }

  function scrollMobileNav() {
    const nav = document.querySelector('.dist-sidebar nav');
    const buttons = nav ? Array.from(nav.querySelectorAll('[data-nav]')) : [];
    if (!nav || !buttons.length) return;
    const step = buttons.length > 1 ? buttons[1].offsetLeft - buttons[0].offsetLeft : buttons[0].offsetWidth;
    nav.scrollBy({ left: step, behavior: 'smooth' });
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
    const renderContext = beginRouteRender(page);
    loadingView();
    try {
      if (page === '/overview') await renderOverview(renderContext);
      else if (page === '/order') await renderOrders({ renderContext });
      else if (page === '/invite') await renderInvite(renderContext);
      else if (page === '/knowledge') await renderKnowledge(renderContext);
      else if (page === '/clients') await renderClients();
      else await renderPlans(renderContext);
    } catch (error) {
      if (isCurrentRouteRender(renderContext)) renderRouteError(error);
    }
  }

  function renderRouteError(error) {
    setContent(`<div class="dist-error" role="alert"><h2>${escapeHtml(error.message)}</h2><button data-action="retry">${t('retry')}</button></div>`);
  }

  async function fetchAvailablePlans() {
    return (dataOf(await api('/user/plan/fetch')) || []).filter((plan) => availablePeriods(plan).length);
  }

  async function renderPlans(renderContext) {
    renderContext ||= beginRouteRender('/plan');
    const plans = await fetchAvailablePlans();
    if (!isCurrentRouteRender(renderContext)) return;
    state.plans = plans;
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
      const purchaseInFlight = state.purchasingPlanId !== null;
      const isPurchasing = String(state.purchasingPlanId) === String(plan.id);
      const tags = [
        isFeatured ? t('featured') : '',
        Number(plan.transfer_enable) >= 100 ? t('highTraffic') : '',
        planHasUnlimitedSpeed(plan) ? t('unlimitedSpeed') : '',
        planHasUnlimitedDevices(plan) ? t('unlimitedDevices') : '',
      ].filter(Boolean).slice(0, 3).map((tag, index) => `<span class="${index === 0 && isFeatured ? 'primary' : ''}">${tag}</span>`).join('');
      const periodButtons = prices.map(([key]) => {
        const [purchasePeriodName, purchasePeriodDays] = PURCHASE_PERIOD_PRESENTATION[key] || [periodName(key), ''];
        const periodPriceDetails = state.planPricesVisible
          ? `<strong>${money(plan[key])}</strong><small>${periodInsight(plan, key)}</small>`
          : '';
        return `<button type="button" role="radio" aria-checked="${selectedPeriod === key}" class="${selectedPeriod === key ? 'active' : ''}" data-plan-period="${key}" data-plan-id="${plan.id}"><span>${purchasePeriodName}</span>${purchasePeriodDays ? `<span class="dist-period-days">${purchasePeriodDays}</span>` : ''}${periodPriceDetails}</button>`;
      }).join('');
      const currentPriceDetails = state.planPricesVisible
        ? `<div class="dist-plan-current-price"><small>${periodName(selectedPeriod)}</small><strong>${money(selectedPrice)}</strong></div>`
        : '';
      const actionPriceDetails = state.planPricesVisible
        ? `<span>${t('original')} ${money(selectedPrice)}</span><span>${t('saved')} ${money(selectedSaving)}</span>`
        : '';
      return `<article class="dist-plan-card ${isFeatured ? 'is-featured' : ''}">
        <div class="dist-plan-body">
          <div class="dist-plan-tags">${tags}</div>
          <div class="dist-plan-heading">
            <div><h2>${escapeHtml(plan.name)}</h2><p>${escapeHtml(planSummary(plan))}</p></div>
            ${currentPriceDetails}
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
          <button type="button" data-buy="${plan.id}" data-name="${escapeHtml(plan.name)}" ${soldOut || purchaseInFlight ? 'disabled' : ''} ${isPurchasing ? 'aria-busy="true"' : ''}>${soldOut ? t('soldOut') : `${actionPriceDetails}<strong>${t('orderAction')}</strong>`}</button>
        </div>
      </article>`;
    }).join('');
    const priceToggleIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle>${state.planPricesVisible ? '' : '<path d="m4 4 16 16"></path>'}</svg>`;
    setContent(`<section class="dist-catalog-topbar">
        <div class="dist-plan-filters">${filterButtons}</div>
        <button type="button" class="dist-price-privacy-toggle" data-action="toggle-plan-prices" aria-pressed="${state.planPricesVisible}" aria-label="${t(state.planPricesVisible ? 'hidePrices' : 'showPrices')}">${priceToggleIcon}</button>
      </section>
      <div class="dist-plan-grid">${cards || `<div class="dist-empty">${t('empty')}</div>`}</div>
    `);
  }

  function clearPlanPriceTimer() {
    if (state.planPricesTimer !== null) window.clearTimeout(state.planPricesTimer);
    state.planPricesTimer = null;
  }

  function closePlanPrices(render = true) {
    clearPlanPriceTimer();
    if (!state.planPricesVisible) return;
    state.planPricesVisible = false;
    if (render && state.active && currentPage() === '/plan') renderPlanCatalog();
  }

  function togglePlanPrices() {
    if (state.planPricesVisible) {
      closePlanPrices();
      return;
    }
    clearPlanPriceTimer();
    state.planPricesVisible = true;
    renderPlanCatalog();
    state.planPricesTimer = window.setTimeout(() => closePlanPrices(), 10000);
  }

  async function purchasePlan(plan, period, button) {
    if (!plan || typeof plan.capacity_limit === 'string' || !period || !availablePeriods(plan).some(([key]) => key === period) || Number(plan[period]) <= 0) {
      toast(t('planUnavailable'), 'error');
      return;
    }
    if (state.purchasingPlanId !== null) return;
    state.selectedPeriods[plan.id] = period;
    state.purchasingPlanId = plan.id;
    if (button) button.disabled = true;
    try {
      const tradeNo = dataOf(await api('/user/order/save', {
        method: 'POST', data: { plan_id: plan.id, period },
      }));
      invalidateOrderAnalytics();
      await openDelivery(tradeNo);
    } catch (error) {
      toast(error.message, 'error');
    } finally {
      state.purchasingPlanId = null;
      if (currentPage() === '/plan') renderPlanCatalog();
      else if (button) button.disabled = false;
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

  function toggleEntitlement(entitlementToggle, entitlementRow) {
    const willExpand = entitlementToggle.getAttribute('aria-expanded') !== 'true';
    entitlementToggle.setAttribute('aria-expanded', String(willExpand));
    entitlementToggle.setAttribute('aria-label', t(willExpand ? 'hideEntitlement' : 'viewEntitlement'));
    entitlementToggle.textContent = t('entitlementAction');
    entitlementRow.hidden = !willExpand;
    entitlementToggle.closest?.('tr')?.classList.toggle('is-entitlement-open', willExpand);
  }

  function orderSummaryTitle(kind, range) {
    const today = shanghaiDate();
    const yesterday = shiftDate(today, -1);
    const suffix = kind === 'income' ? t('income') : t('orderCount');
    if (range.startDate === range.endDate && range.startDate === today) return t(kind === 'income' ? 'todayIncome' : 'todayOrders');
    if (range.startDate === range.endDate && range.startDate === yesterday) return t(kind === 'income' ? 'yesterdayIncome' : 'yesterdayOrders');
    if (range.startDate === range.endDate) {
      const [, month, day] = range.startDate.split('-');
      return state.locale === 'zh-CN' ? `${Number(month)}月${Number(day)}日${suffix}` : `${month}/${day} ${suffix}`;
    }
    const days = dateRangeDays(range.startDate, range.endDate);
    return state.locale === 'zh-CN' ? `${days}天${suffix}` : `${days}-day ${suffix}`;
  }

  async function fetchOrderStatistics(range) {
    const params = new URLSearchParams({ start_date: range.startDate, end_date: range.endDate });
    return dataOf(await api(`/user/order/statistics?${params}`));
  }

  function invalidateOrderAnalytics() {
    state.orderSummary = null;
    state.orderTrend = null;
    state.analyticsRequestToken += 1;
  }

  async function ensureOrderAnalytics(forceSummary = false, forceTrend = false, renderContext) {
    const today = shanghaiDate();
    state.orderSummaryRange ||= { startDate: today, endDate: today };
    state.orderTrendRange ||= defaultTrendRange(state.orderTrendPreset);
    const requestToken = ++state.analyticsRequestToken;
    const summaryRange = { ...state.orderSummaryRange };
    const trendRange = { ...state.orderTrendRange };
    const [summary, trend] = await Promise.all([
      !state.orderSummary || forceSummary ? fetchOrderStatistics(summaryRange) : Promise.resolve(state.orderSummary),
      !state.orderTrend || forceTrend ? fetchOrderStatistics(trendRange) : Promise.resolve(state.orderTrend),
    ]);
    if (requestToken !== state.analyticsRequestToken || !isCurrentRouteRender(renderContext)) return false;
    state.orderSummary = summary;
    state.orderTrend = trend;
    return true;
  }

  function renderTrendChart(daily, key, label, formatter) {
    const width = 720;
    const height = 180;
    const left = 42;
    const right = 16;
    const top = 16;
    const bottom = 36;
    const values = daily.map((item) => Number(item[key]) || 0);
    const maximum = Math.max(1, ...values);
    const x = (index) => daily.length <= 1 ? (left + width - right) / 2 : left + (index * (width - left - right) / (daily.length - 1));
    const y = (value) => top + ((maximum - value) * (height - top - bottom) / maximum);
    const points = values.map((value, index) => `${index ? 'L' : 'M'}${x(index).toFixed(1)},${y(value).toFixed(1)}`).join(' ');
    const labelEvery = Math.max(1, Math.ceil(daily.length / 6));
    const dateLabels = daily.map((item, index) => (
      index % labelEvery === 0 || index === daily.length - 1
        ? `<text x="${x(index).toFixed(1)}" y="166" text-anchor="middle">${escapeHtml(item.date.slice(5))}</text>`
        : ''
    )).join('');
    const circles = daily.map((item, index) => `<circle cx="${x(index).toFixed(1)}" cy="${y(values[index]).toFixed(1)}" r="4" tabindex="0" data-chart-index="${index}" aria-label="${escapeHtml(`${item.date} ${label} ${formatter(values[index])}`)}"></circle>`).join('');
    const activeIndex = Math.max(0, daily.length - 1);
    const active = daily[activeIndex] || { date: '-', [key]: 0 };

    return `<section class="dist-chart" data-chart-key="${key}">
      <div class="dist-chart-heading"><h3>${label}</h3><output class="dist-chart-value" aria-live="polite">${escapeHtml(active.date)} · ${escapeHtml(formatter(active[key] || 0))}</output></div>
      <svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(label)}" preserveAspectRatio="none">
        <line class="dist-chart-axis" x1="${left}" y1="${height - bottom}" x2="${width - right}" y2="${height - bottom}"></line>
        <path class="dist-chart-line" d="${points || `M${left},${height - bottom}`}" vector-effect="non-scaling-stroke"></path>
        <g class="dist-chart-dates">${dateLabels}</g><g class="dist-chart-points">${circles}</g>
      </svg>
    </section>`;
  }

  function renderOrderAnalytics() {
    const summary = state.orderSummary || { summary: { order_count: 0, total_amount: 0 } };
    const daily = state.orderTrend?.daily || [];
    const summaryRange = state.orderSummaryRange;
    const trendRange = state.orderTrendRange;
    const presetButton = (preset, label) => `<button type="button" data-trend-preset="${preset}" class="${state.orderTrendPreset === preset ? 'active' : ''}" aria-pressed="${state.orderTrendPreset === preset}">${label}</button>`;

    return `<section class="dist-order-insights" aria-labelledby="dist-order-overview-title">
      <div class="dist-insights-heading"><div><h1 id="dist-order-overview-title">${t('orderOverview')}</h1><p>${t('orderOverviewHint')}</p></div>
        <div class="dist-summary-range"><label>${t('startDate')}<input id="dist-summary-start" type="date" value="${summaryRange.startDate}"></label><span>—</span><label>${t('endDate')}<input id="dist-summary-end" type="date" value="${summaryRange.endDate}"></label><button type="button" data-action="apply-summary-range">${t('apply')}</button></div>
      </div>
      <div class="dist-order-summary-cards">
        <article><span>${orderSummaryTitle('orders', summaryRange)}</span><strong>${Number(summary.summary.order_count || 0).toLocaleString()}</strong><small>${summaryRange.startDate} — ${summaryRange.endDate}</small></article>
        <article><span>${orderSummaryTitle('income', summaryRange)}</span><strong>${money(summary.summary.total_amount || 0)}</strong><small>${summaryRange.startDate} — ${summaryRange.endDate}</small></article>
      </div>
      <div class="dist-trend-panel">
        <div class="dist-trend-heading"><div><h2>${t('trend')}</h2><p>${trendRange.startDate} — ${trendRange.endDate}</p></div><div class="dist-trend-presets">${presetButton('week', t('thisWeek'))}${presetButton('month', t('thisMonth'))}${presetButton('ninety', t('lastNinetyDays'))}${presetButton('custom', t('customRange'))}</div></div>
        <div class="dist-trend-custom ${state.orderTrendPreset === 'custom' ? 'is-open' : ''}"><label>${t('startDate')}<input id="dist-trend-start" type="date" value="${trendRange.startDate}"></label><label>${t('endDate')}<input id="dist-trend-end" type="date" value="${trendRange.endDate}"></label><button type="button" data-action="apply-trend-range">${t('apply')}</button></div>
        <div class="dist-chart-stack">${renderTrendChart(daily, 'order_count', t('dailyOrders'), (value) => String(value))}${renderTrendChart(daily, 'total_amount', t('dailyIncome'), (value) => money(value))}</div>
      </div>
    </section>`;
  }

  async function renderOverview(renderContext) {
    renderContext ||= beginRouteRender('/overview');
    if (!await ensureOrderAnalytics(true, true, renderContext)) return;
    setContent(renderOrderAnalytics());
  }

  async function refreshOrderAnalytics(forceSummary = false, forceTrend = false) {
    const renderContext = beginRouteRender('/overview');
    try {
      if (!await ensureOrderAnalytics(forceSummary, forceTrend, renderContext)) return;
      const current = document.querySelector('.dist-order-insights');
      if (current) current.outerHTML = renderOrderAnalytics();
    } catch (error) {
      if (isCurrentRouteRender(renderContext)) renderRouteError(error);
    }
  }

  function renderBoundDevices(order) {
    const devices = Array.isArray(order.bound_devices) ? order.bound_devices : [];
    if (!order.hwid_enabled) return `<span class="dist-device-state">${t('hwidDisabled')}</span>`;
    if (!devices.length) return `<span class="dist-device-state">${t('unboundDevice')}</span>`;
    const limit = isMobileOrderViewport() ? 2 : 3;
    const expanded = Boolean(state.expandedDeviceOrders[order.id]);
    const items = devices.map((hwid, index) => `<code class="${!expanded && index >= limit ? 'is-device-extra' : ''}">${escapeHtml(hwid)}</code>`).join('');
    const toggle = devices.length > limit
      ? `<button type="button" class="dist-device-toggle" data-device-toggle="${escapeHtml(order.id)}" aria-expanded="${expanded}">${expanded ? t('collapseDevices') : t('viewAllDevices').replace('{count}', devices.length)}</button>`
      : '';
    return `<div class="dist-bound-device-list ${expanded ? 'is-expanded' : ''}">${items}${toggle}</div>`;
  }

  function collectOrderFilters() {
    const periods = Array.from(document.querySelectorAll('input[name="dist-order-period"]:checked')).map((input) => input.value);
    return {
      settlementStatus: document.getElementById('dist-order-settlement')?.value || '',
      startDate: document.getElementById('dist-order-start')?.value || '',
      endDate: document.getElementById('dist-order-end')?.value || '',
      periods,
      minAmount: document.getElementById('dist-order-min-amount')?.value.trim() || '',
      maxAmount: document.getElementById('dist-order-max-amount')?.value.trim() || '',
    };
  }

  function renderOrderFilters() {
    const filters = state.orderFilters;
    const periods = PERIODS.map(([key, zh, en]) => `<label><input type="checkbox" name="dist-order-period" value="${key}" ${filters.periods.includes(key) ? 'checked' : ''}><span>${state.locale === 'zh-CN' ? zh : en}</span></label>`).join('');
    return `<section id="dist-order-filters" class="dist-order-filters" ${state.orderFiltersOpen ? '' : 'hidden'} aria-label="${t('advancedFilters')}">
      <div class="dist-filter-grid"><label>${t('settlementFilter')}<select id="dist-order-settlement"><option value="">${t('allSettlements')}</option><option value="0" ${state.orderSettlementStatus === '0' ? 'selected' : ''}>${t('unsettled')}</option><option value="1" ${state.orderSettlementStatus === '1' ? 'selected' : ''}>${t('settled')}</option></select></label><label>${t('startDate')}<input id="dist-order-start" type="date" value="${filters.startDate}"></label><label>${t('endDate')}<input id="dist-order-end" type="date" value="${filters.endDate}"></label><label>${t('minimumAmount')}<input id="dist-order-min-amount" inputmode="decimal" value="${escapeHtml(filters.minAmount)}" placeholder="0.00"></label><label>${t('maximumAmount')}<input id="dist-order-max-amount" inputmode="decimal" value="${escapeHtml(filters.maxAmount)}" placeholder="0.00"></label></div>
      <fieldset><legend>${t('period')}</legend><div class="dist-period-filter-options">${periods}</div></fieldset>
      <div class="dist-filter-actions"><button type="button" class="secondary" data-action="reset-order-filters">${t('filterReset')}</button><button type="button" data-action="apply-order-filters">${t('apply')}</button></div>
    </section>`;
  }

  async function renderOrders(options = {}) {
    const renderContext = options.renderContext || beginRouteRender('/order');
    const append = Boolean(options.append);
    const requestedPage = append ? state.orderPage + 1 : state.orderPage;
    const params = new URLSearchParams({ page: String(requestedPage), per_page: String(state.orderPerPage) });
    if (state.orderSettlementStatus !== '') params.set('settlement_status', state.orderSettlementStatus);
    if (state.orderSearch) params.set('search', state.orderSearch);
    appendOrderFilters(params);
    const oldScrollY = window.scrollY;
    const payload = await api(`/user/order/fetch?${params}`);
    if (!isCurrentRouteRender(renderContext)) return;
    const fetchedOrders = Array.isArray(payload?.data) ? payload.data : [];
    state.orders = append ? [...state.orders, ...fetchedOrders] : fetchedOrders;
    state.orderPage = Number(payload?.current_page || requestedPage);
    state.orderPerPage = Number(payload?.per_page || state.orderPerPage);
    state.orderTotal = Number(payload?.total || state.orders.length);
    state.orderLastPage = Number(payload?.last_page || 1);
    const rows = state.orders.map((order, index) => {
      const sequence = append ? index + 1 : ((state.orderPage - 1) * state.orderPerPage) + index + 1;
      const isRenewal = Number(order.type) === 2;
      const settlement = order.settlement_status === 1 ? t('settled') : t('unsettled');
      const entitlement = order.subscription_entitlement;
      const boundDevices = Array.isArray(order.bound_devices) ? order.bound_devices : [];
      const usedTraffic = order.used_traffic ?? entitlement?.used_traffic ?? 0;
      const boundDeviceContent = !order.hwid_enabled
        ? `<span class="dist-device-state">${t('hwidDisabled')}</span>`
        : boundDevices.length
          ? `<div class="dist-bound-device-list">${boundDevices.map((hwid) => `<code>${escapeHtml(hwid)}</code>`).join('')}</div>`
          : `<span class="dist-device-state">${t('unboundDevice')}</span>`;
      const entitlementTarget = `dist-entitlement-${order.id}`;
      const entitlementRow = entitlement && order.is_subscription_origin ? `<tr id="${entitlementTarget}" class="dist-entitlement-row" data-entitlement-for="${escapeHtml(order.trade_no)}" hidden><td colspan="14"><div><strong>${t('entitlement')}</strong><dl>
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
        ? `<button type="button" class="dist-link-btn" data-subscription-qr="${escapeHtml(order.trade_no)}" aria-label="${t('viewSubscriptionQr')}">${t('subscriptionQrAction')}</button>`
        : `<span class="dist-action-disabled" role="status" aria-disabled="true">${t('subscriptionPending')}</span>`;
      const renewAction = order.can_renew
        ? `<button type="button" class="dist-link-btn dist-renew-btn" data-renew="${escapeHtml(order.trade_no)}">${t('renew')}</button>`
        : '';
      const entitlementAction = entitlement && order.is_subscription_origin
        ? `<button type="button" class="dist-link-btn dist-entitlement-toggle" data-entitlement-toggle="${entitlementTarget}" aria-expanded="false" aria-controls="${entitlementTarget}" aria-label="${t('viewEntitlement')}">${t('entitlementAction')}</button>`
        : '';
      const hasActions = Boolean(order.is_subscription_origin || entitlementAction || renewAction);
      const actionCellClass = hasActions ? 'dist-order-action-cell has-actions' : 'dist-order-action-cell';
      const utilityActionCount = Number(order.is_subscription_origin) + Number(Boolean(entitlementAction));
      const orderType = escapeHtml(isRenewal ? t('renew') : t('newPurchase'));
      const originalOrder = isRenewal && order.subscription_trade_no ? escapeHtml(order.subscription_trade_no) : '—';
      const rowClass = isRenewal ? 'dist-renewal-order-row' : 'dist-origin-order-row';
      return `<tr class="${rowClass}" data-subscription-trade-no="${escapeHtml(order.subscription_trade_no || order.trade_no)}">
        <td class="dist-order-sequence">${sequence}</td>
        <td class="${actionCellClass}"><div class="dist-order-actions utility-count-${utilityActionCount}">${order.is_subscription_origin ? qrAction : ''}${entitlementAction}${renewAction}</div></td>
        <td class="dist-order-identity"><strong>${escapeHtml(order.trade_no)}</strong></td>
        <td class="dist-order-time" data-label="${t('orderTime')}">${formatTime(order.created_at)}</td>
        <td class="dist-order-type" data-label="${t('orderType')}">${orderType}</td>
        <td class="dist-order-original" data-label="${t('originalOrder')}">${originalOrder}</td>
        <td class="dist-order-customer" data-label="${t('customerName')}">${escapeHtml(order.customer_name || '-')}</td>
        <td class="dist-order-plan" data-label="${t('plan')}">${escapeHtml(order.plan?.name || '-')}</td><td class="dist-order-period" data-label="${t('period')}">${escapeHtml(periodLabel(order.period))}</td>
        <td class="dist-order-amount" data-label="${t('amount')}">${money(order.total_amount)}<small class="dist-free">${t('free')}</small></td>
        <td class="dist-order-bound-devices" data-label="${t('boundDevices')}">${renderBoundDevices(order)}</td>
        <td class="dist-order-used-traffic" data-label="${t('usedTraffic')}">${formatTraffic(usedTraffic)}</td>
        <td class="dist-order-settlement"><span class="dist-badge settle-${order.settlement_status}">${settlement}</span></td>
        <td class="dist-order-remark-cell" data-label="${t('remark')}"><div class="dist-order-remark">${order.remark ? escapeHtml(order.remark) : '—'}</div></td>
      </tr>${entitlementRow}`;
    }).join('');
    const desktopPagination = `<div class="dist-desktop-pagination"><span>${t('totalOrders').replace('{count}', state.orderTotal)}</span><label>${t('pageSize')}<select id="dist-order-page-size" aria-label="${t('pageSize')}">${[20, 50, 100].map((size) => `<option value="${size}" ${size === state.orderPerPage ? 'selected' : ''}>${size}</option>`).join('')}</select></label><button type="button" data-order-page="1" ${state.orderPage <= 1 ? 'disabled' : ''}>${t('firstPage')}</button><button type="button" data-order-page="${state.orderPage - 1}" ${state.orderPage <= 1 ? 'disabled' : ''}>${t('previousPage')}</button><strong>${state.orderPage} / ${state.orderLastPage}</strong><button type="button" data-order-page="${state.orderPage + 1}" ${state.orderPage >= state.orderLastPage ? 'disabled' : ''}>${t('nextPage')}</button><button type="button" data-order-page="${state.orderLastPage}" ${state.orderPage >= state.orderLastPage ? 'disabled' : ''}>${t('lastPage')}</button><label class="dist-page-jump">${t('jumpTo')} <input id="dist-order-page-input" type="number" min="1" max="${state.orderLastPage}" inputmode="numeric" aria-label="${t('jumpTo')}"><span>${state.locale === 'zh-CN' ? '页' : ''}</span></label><button type="button" data-action="jump-order-page">${t('jump')}</button></div>`;
    setContent(`<div class="dist-order-toolbar"><div class="dist-order-search"><input id="dist-order-search" type="search" maxlength="512" value="${escapeHtml(state.orderSearch)}" placeholder="${t('orderSearchPlaceholder')}"><button data-action="search-orders">${t('search')}</button><button class="secondary" data-action="clear-order-search" ${state.orderSearch ? '' : 'disabled'}>${t('clear')}</button></div><button type="button" class="secondary dist-filter-toggle" data-action="toggle-order-filters" aria-expanded="${state.orderFiltersOpen}" aria-controls="dist-order-filters">${t(state.orderFiltersOpen ? 'hideFilters' : 'advancedFilters')}</button><button data-action="export-orders">${t('exportExcel')}</button></div>
      ${renderOrderFilters()}
      <div class="dist-table-wrap dist-order-list" tabindex="0" aria-label="${t('orders')}"><table class="dist-orders-table"><colgroup><col class="dist-col-sequence"><col class="dist-col-actions"><col class="dist-col-order-no"><col class="dist-col-order-time"><col class="dist-col-order-type"><col class="dist-col-original"><col class="dist-col-customer"><col class="dist-col-plan"><col class="dist-col-period"><col class="dist-col-amount"><col class="dist-col-devices"><col class="dist-col-traffic"><col class="dist-col-settlement"><col class="dist-col-remark"></colgroup><thead><tr><th>${t('sequence')}</th><th>${t('actions')}</th><th>${t('orderNo')}</th><th>${t('orderTime')}</th><th>${t('orderType')}</th><th>${t('originalOrder')}</th><th>${t('customerName')}</th><th>${t('plan')}</th><th>${t('period')}</th><th>${t('amount')}</th><th>${t('boundDevices')}</th><th>${t('usedTraffic')}</th><th>${t('settlement')}</th><th>${t('remark')}</th></tr></thead>
      <tbody>${rows || `<tr class="dist-orders-empty"><td colspan="14" class="dist-empty">${t('empty')}</td></tr>`}</tbody></table></div>${desktopPagination}`);
    if (append) window.scrollTo({ top: oldScrollY, behavior: 'instant' });
  }

  function periodLabel(period) {
    return periodName(period);
  }

  async function renderInvite(renderContext) {
    renderContext ||= beginRouteRender('/invite');
    const info = dataOf(await api('/user/invite/fetch')) || { codes: [], stat: [] };
    const historyPayload = await api('/user/invite/details?current=1&page_size=50');
    if (!isCurrentRouteRender(renderContext)) return;
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

  async function renderKnowledge(renderContext) {
    renderContext ||= beginRouteRender('/knowledge');
    const params = new URLSearchParams({ language: state.locale });
    if (state.knowledgeSearch) params.set('keyword', state.knowledgeSearch);
    const grouped = dataOf(await api(`/user/knowledge/fetch?${params}`)) || {};
    if (!isCurrentRouteRender(renderContext)) return;
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
    const renderContext = { route: '/clients', generation: state.renderGeneration };
    if (!isCurrentRouteRender(renderContext)) return;
    setContent('<div class="xcc-host"><div class="xcc-loading">正在加载客户端目录…</div></div>');
    if (!window.XBoardClientCenter) throw new Error('Client center is unavailable');
    if (!isCurrentRouteRender(renderContext)) return;
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
    focusOrderActionModal();
  }

  function makeIdempotencyKey() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
      const random = Math.floor(Math.random() * 16);
      const value = character === 'x' ? random : (random & 0x3) | 0x8;
      return value.toString(16);
    });
  }

  function renewalPeriods(order) {
    return availablePeriods(order.plan || {}).filter(([key, , , months]) => months > 0 && key !== 'onetime_price');
  }

  function openRenewal(tradeNo) {
    const order = state.orders.find((item) => item.trade_no === tradeNo && item.can_renew);
    if (!order) throw new Error(t('planUnavailable'));
    const periods = renewalPeriods(order);
    if (!periods.length) throw new Error(t('planUnavailable'));
    state.modal = {
      type: 'renewal',
      order,
      periods,
      period: periods.some(([key]) => key === order.period) ? order.period : periods[0][0],
      idempotencyKey: makeIdempotencyKey(),
      submitting: false,
      result: null,
    };
    renderModal();
    focusOrderActionModal();
  }

  function isMobileOrderViewport() {
    return Boolean(window.matchMedia?.('(max-width:640px), (max-width:900px) and (hover:none) and (pointer:coarse)').matches);
  }

  function isMobileOrderActionModal() {
    const orderActionModal = state.modal && ['subscriptionQr', 'renewal'].includes(state.modal.type);
    return Boolean(orderActionModal && isMobileOrderViewport());
  }

  function focusOrderActionModal() {
    if (!isMobileOrderActionModal()) return;
    document.querySelector('#dist-modal-root .dist-modal-x')?.focus({ preventScroll: true });
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
      root.innerHTML = `<div class="dist-modal-backdrop dist-order-action-backdrop"><section class="dist-modal dist-subscription-qr-modal" role="dialog" aria-modal="true" aria-labelledby="dist-subscription-qr-title"><button class="dist-modal-x" data-modal-action="cancel" aria-label="${t('cancel')}">×</button><h2 id="dist-subscription-qr-title">${t('viewSubscriptionQr')}</h2>
        <img class="dist-subscription-qr-preview" src="${modal.imageUrl}" alt="${escapeHtml(t('viewSubscriptionQr'))}">
        <div class="dist-modal-actions dist-image-actions"><button data-modal-action="copy-subscription-qr">${modal.copied ? t('copySuccess') : t('copyImage')}</button><button class="primary" data-modal-action="download-subscription-qr">${t('downloadImage')}</button></div>
      </section></div>`;
      return;
    }
    if (state.modal.type === 'renewal') {
      const modal = state.modal;
      if (modal.result) {
        root.innerHTML = `<div class="dist-modal-backdrop dist-order-action-backdrop"><section class="dist-modal dist-renewal-modal" role="dialog" aria-modal="true" aria-labelledby="dist-renewal-result-title"><button class="dist-modal-x" data-modal-action="renew-done" aria-label="${t('closePopup')}">×</button><h2 id="dist-renewal-result-title">${t('renewSuccess')}</h2>
          <p class="dist-renewal-hint">${t('renewHint')}</p><dl>
          <div><dt>${t('renewOrder')}</dt><dd>${escapeHtml(modal.result.trade_no)}</dd></div>
          <div><dt>${t('amount')}</dt><dd>${money(modal.result.total_amount)}</dd></div>
          <div><dt>${t('renewNewExpiry')}</dt><dd>${formatTime(modal.result.expired_at_after)}</dd></div>
          <div><dt>${t('settlement')}</dt><dd>${t('unsettled')}</dd></div></dl>
          <div class="dist-modal-actions"><button class="primary" data-modal-action="renew-done">${t('closePopup')}</button></div></section></div>`;
        return;
      }
      const selectedPrice = Number(modal.order.plan?.[modal.period]) || 0;
      const options = modal.periods.map(([key]) => `<option value="${key}" ${modal.period === key ? 'selected' : ''}>${periodName(key)} · ${money(modal.order.plan[key])}</option>`).join('');
      root.innerHTML = `<div class="dist-modal-backdrop dist-order-action-backdrop"><section class="dist-modal dist-renewal-modal" role="dialog" aria-modal="true" aria-labelledby="dist-renewal-title"><button class="dist-modal-x" data-modal-action="cancel" aria-label="${t('cancel')}">×</button><h2 id="dist-renewal-title">${t('renewTitle')}</h2>
        <p class="dist-renewal-hint">${t('renewHint')}</p><dl>
        <div><dt>${t('customerName')}</dt><dd>${escapeHtml(modal.order.customer_name || '-')}</dd></div>
        <div><dt>${t('plan')}</dt><dd>${escapeHtml(modal.order.plan?.name || '-')}</dd></div>
        <div><dt>${t('renewCurrentExpiry')}</dt><dd>${formatTime(modal.order.subscription_entitlement?.expired_at)}</dd></div>
        <div><dt>${t('amount')}</dt><dd>${money(selectedPrice)}</dd></div></dl>
        <label class="dist-renewal-period">${t('renewPeriod')}<select id="dist-renewal-period" ${modal.submitting ? 'disabled' : ''}>${options}</select></label>
        <div class="dist-modal-actions"><button data-modal-action="cancel" ${modal.submitting ? 'disabled' : ''}>${t('cancel')}</button><button class="primary" data-modal-action="confirm-renewal" ${modal.submitting ? 'disabled' : ''}>${modal.submitting ? t('loading') : t('renewConfirm')}</button></div></section></div>`;
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
    const modalTrigger = state.modalTrigger;
    state.modal = null;
    state.modalTrigger = null;
    renderModal();
    if (!modalTrigger) return;
    window.requestAnimationFrame(() => {
      const focusTarget = modalTrigger?.isConnected
        ? modalTrigger
        : document.querySelector('.dist-order-action-cell.has-actions button:not(:disabled)');
      focusTarget?.focus({ preventScroll: true });
    });
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

  function setActiveTrendPoint(index) {
    const daily = state.orderTrend?.daily || [];
    const item = daily[index];
    if (!item) return;
    document.querySelectorAll('.dist-chart').forEach((chart) => {
      const key = chart.dataset.chartKey;
      const value = key === 'total_amount' ? money(item[key] || 0) : String(item[key] || 0);
      const output = chart.querySelector('.dist-chart-value');
      if (output) output.textContent = `${item.date} · ${value}`;
      chart.querySelectorAll('[data-chart-index]').forEach((point) => {
        point.classList.toggle('is-active', Number(point.dataset.chartIndex) === index);
      });
    });
  }

  function checkedDateRange(startId, endId) {
    const startDate = document.getElementById(startId)?.value || '';
    const endDate = document.getElementById(endId)?.value || '';
    if (!startDate || !endDate || startDate > endDate) {
      throw new Error(state.locale === 'zh-CN' ? '请选择有效的开始和结束日期' : 'Select a valid start and end date');
    }
    if (dateRangeDays(startDate, endDate) > 366) {
      throw new Error(state.locale === 'zh-CN' ? '时间范围不能超过366天' : 'The date range cannot exceed 366 days');
    }
    return { startDate, endDate };
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
    const deviceToggle = target.closest('[data-device-toggle]');
    if (deviceToggle) {
      const orderId = deviceToggle.dataset.deviceToggle;
      const expanded = deviceToggle.getAttribute('aria-expanded') !== 'true';
      state.expandedDeviceOrders[orderId] = expanded;
      deviceToggle.setAttribute('aria-expanded', String(expanded));
      deviceToggle.textContent = expanded
        ? t('collapseDevices')
        : t('viewAllDevices').replace('{count}', deviceToggle.closest('.dist-bound-device-list')?.querySelectorAll('code').length || 0);
      deviceToggle.closest('.dist-bound-device-list')?.classList.toggle('is-expanded', expanded);
      return;
    }
    const pageButton = target.closest('[data-order-page]');
    if (pageButton && !pageButton.disabled) {
      state.orderPage = Number(pageButton.dataset.orderPage);
      try { await renderOrders(); window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { toast(e.message, 'error'); }
      return;
    }
    const jumpPageButton = target.closest('[data-action="jump-order-page"]');
    if (jumpPageButton) {
      const pageInput = document.getElementById('dist-order-page-input');
      const requestedPage = Number.parseInt(pageInput?.value || '', 10);
      if (!Number.isInteger(requestedPage)) { pageInput?.focus(); return; }
      state.orderPage = Math.min(Math.max(requestedPage, 1), state.orderLastPage);
      try { await renderOrders(); window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { toast(e.message, 'error'); }
      return;
    }
    const trendPreset = target.closest('[data-trend-preset]');
    if (trendPreset) {
      state.orderTrendPreset = trendPreset.dataset.trendPreset;
      if (state.orderTrendPreset === 'custom') {
        document.querySelector('.dist-trend-custom')?.classList.add('is-open');
        document.querySelectorAll('[data-trend-preset]').forEach((button) => {
          const active = button === trendPreset;
          button.classList.toggle('active', active);
          button.setAttribute('aria-pressed', String(active));
        });
      } else {
        state.orderTrendRange = defaultTrendRange(state.orderTrendPreset);
        try { await refreshOrderAnalytics(false, true); } catch (e) { toast(e.message, 'error'); }
      }
      return;
    }
    const buy = target.closest('[data-buy]');
    if (buy) {
      const plan = state.plans.find((item) => String(item.id) === String(buy.dataset.buy));
      await purchasePlan(plan, state.selectedPeriods[buy.dataset.buy], buy);
      return;
    }
    const subscriptionQr = target.closest('[data-subscription-qr]');
    if (subscriptionQr) {
      const mobileOrderAction = isMobileOrderViewport();
      if (mobileOrderAction && subscriptionQr.disabled) return;
      if (mobileOrderAction) {
        state.modalTrigger = subscriptionQr;
        subscriptionQr.disabled = true;
        subscriptionQr.setAttribute('aria-busy', 'true');
      }
      try {
        await openSubscriptionQr(subscriptionQr.dataset.subscriptionQr);
      } catch (e) {
        if (mobileOrderAction) state.modalTrigger = null;
        toast(e.message, 'error');
      } finally {
        if (mobileOrderAction && subscriptionQr.isConnected) {
          subscriptionQr.disabled = false;
          subscriptionQr.removeAttribute('aria-busy');
        }
      }
      return;
    }
    const entitlementToggle = target.closest('[data-entitlement-toggle]');
    if (entitlementToggle) {
      const entitlementRow = document.getElementById(entitlementToggle.dataset.entitlementToggle);
      if (!entitlementRow) return;
      toggleEntitlement(entitlementToggle, entitlementRow);
      return;
    }
    const renew = target.closest('[data-renew]');
    if (renew) {
      const mobileOrderAction = isMobileOrderViewport();
      if (mobileOrderAction) state.modalTrigger = renew;
      try { openRenewal(renew.dataset.renew); } catch (e) { if (mobileOrderAction) state.modalTrigger = null; toast(e.message, 'error'); }
      return;
    }
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
      closePlanPrices(false);
      localStorage.removeItem(TOKEN_KEY);
      window.location.href = '/#/login';
    } else if (action === 'toggle-plan-prices') {
      togglePlanPrices();
    } else if (action === 'scroll-mobile-nav') {
      scrollMobileNav();
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
      state.orderPage = 1;
      try { await renderOrders(); } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'clear-order-search') {
      state.orderSearch = '';
      state.orderPage = 1;
      try { await renderOrders(); } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'toggle-order-filters') {
      state.orderFiltersOpen = !state.orderFiltersOpen;
      const panel = document.getElementById('dist-order-filters');
      if (panel) panel.hidden = !state.orderFiltersOpen;
      const button = target.closest('[data-action]');
      button?.setAttribute('aria-expanded', String(state.orderFiltersOpen));
      if (button) button.textContent = t(state.orderFiltersOpen ? 'hideFilters' : 'advancedFilters');
    } else if (action === 'apply-order-filters') {
      const { settlementStatus, ...filters } = collectOrderFilters();
      state.orderSettlementStatus = settlementStatus;
      state.orderFilters = filters;
      state.orderPage = 1;
      try { await renderOrders(); } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'reset-order-filters') {
      state.orderSettlementStatus = '';
      state.orderFilters = { startDate: '', endDate: '', periods: [], minAmount: '', maxAmount: '' };
      state.orderPage = 1;
      try { await renderOrders(); } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'apply-summary-range') {
      try {
        state.orderSummaryRange = checkedDateRange('dist-summary-start', 'dist-summary-end');
        await refreshOrderAnalytics(true, false);
      } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'apply-trend-range') {
      try {
        state.orderTrendPreset = 'custom';
        state.orderTrendRange = checkedDateRange('dist-trend-start', 'dist-trend-end');
        await refreshOrderAnalytics(false, true);
      } catch (e) { toast(e.message, 'error'); }
    } else if (action === 'load-more-orders') {
      try { await renderOrders({ append: true }); } catch (e) { toast(e.message, 'error'); }
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
    else if (action === 'renew-done') closeModal();
    else if (action === 'confirm-renewal') {
      if (!state.modal || state.modal.type !== 'renewal' || state.modal.submitting) return;
      state.modal.submitting = true;
      renderModal();
      try {
        state.modal.result = dataOf(await api('/user/order/renew', {
          method: 'POST',
          data: {
            trade_no: state.modal.order.trade_no,
            period: state.modal.period,
            idempotency_key: state.modal.idempotencyKey,
          },
        }));
        state.modal.submitting = false;
        invalidateOrderAnalytics();
        await renderOrders();
        renderModal();
      } catch (error) {
        state.modal.submitting = false;
        renderModal();
        toast(error.message, 'error');
      }
    }
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
    if (!['/plan', '/overview', '/order', '/invite', '/knowledge', '/clients'].includes(currentPage())) navigate('/plan');
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
    else if (event.target.classList.contains('dist-modal-backdrop') && isMobileOrderActionModal()) closeModal();
    else handleAction(event.target);
  });
  document.addEventListener('change', (event) => {
    if (!state.active) return;
    if (event.target.id === 'dist-order-page-size') {
      state.orderPerPage = Number(event.target.value) || 20;
      state.orderPage = 1;
      renderOrders().catch((error) => toast(error.message, 'error'));
    } else if (event.target.id === 'dist-renewal-period' && state.modal?.type === 'renewal') {
      state.modal.period = event.target.value;
      renderModal();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (!state.active) return;
    if (event.key === 'Escape' && isMobileOrderActionModal()) {
      event.preventDefault();
      closeModal();
      return;
    }
    if (event.key !== 'Enter') return;
    if (event.target.id === 'dist-order-search') {
      event.preventDefault();
      state.orderSearch = event.target.value.trim();
      state.orderPage = 1;
      renderOrders().catch((error) => toast(error.message, 'error'));
    } else if (event.target.id === 'dist-knowledge-search') {
      event.preventDefault();
      state.knowledgeSearch = event.target.value.trim();
      renderKnowledge().catch((error) => toast(error.message, 'error'));
    }
  });
  document.addEventListener('focusin', (event) => {
    const point = event.target.closest?.('[data-chart-index]');
    if (point) setActiveTrendPoint(Number(point.dataset.chartIndex));
  });
  document.addEventListener('pointermove', (event) => {
    const chart = event.target.closest?.('.dist-chart svg');
    const daily = state.orderTrend?.daily || [];
    if (!chart || !daily.length) return;
    const bounds = chart.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (event.clientX - bounds.left) / Math.max(1, bounds.width)));
    setActiveTrendPoint(Math.round(ratio * (daily.length - 1)));
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) closePlanPrices();
  });
  let routeRenderQueued = false;
  function queueRouteRender() {
    if (!state.active) return;
    if (routeRenderQueued) return;
    routeRenderQueued = true;
    window.queueMicrotask(() => {
      routeRenderQueued = false;
      if (!state.active) return;
      if (currentPage() !== '/plan') closePlanPrices(false);
      renderPage();
    });
  }
  window.addEventListener('hashchange', queueRouteRender);
  window.addEventListener('popstate', queueRouteRender);
  const detector = setInterval(() => {
    if (state.active) clearInterval(detector);
    else detectDistributor();
  }, 300);
  detectDistributor();
})();
