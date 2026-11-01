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
  const MAX_ORDER_QUANTITY = 500;
  const QUICK_ORDER_QUANTITIES = [5, 10];
  const PURCHASE_RECOVERY_STORAGE_KEY = 'xboard_distributor_purchase_recovery';
  const PURCHASE_RECOVERY_SCHEMA = 2;
  // An empty order check only proves an uncertain write failed after the original request can no
  // longer be executing server-side (Octane max execution 60s plus queueing and lock waits).
  const PURCHASE_ABANDON_SAFE_DELAY_MS = 180000;
  const BATCH_DELIVERY_TIMEOUT_MS = 30000;
  const BATCH_QR_INLINE_THRESHOLD = 25;
  const COPY = {
    'zh-CN': {
      buy: '购买订阅', overview: '经营概览', orders: '我的订单', invite: '我的邀请', knowledge: '使用文档', clients: '客户端下载', logout: '退出登录',
      title: '分销订阅中心', subtitle: '每个订单生成一份独立订阅，客户扫码领取后不可再次领取。',
      buyNow: '立即下单', original: '原价', free: '分销免支付', cancel: '取消',
      allPlans: '全部套餐', highTraffic: '大流量', unlimitedSpeed: '不限速', unlimitedDevices: '不限设备',
      featured: '精选套餐', traffic: '套餐流量', speed: '速度限制', devices: '同时在线', resetMethod: '流量重置',
      followSystem: '跟随系统', firstDayMonth: '每月1日', monthlyReset: '按月重置', neverReset: '不重置', firstDayYear: '每年1月1日', yearlyReset: '按年重置',
      perMonth: '折合 {price}/月', save: '省 {percent}%', oneTimeHint: '一次性交付',
      saved: '已省', orderAction: '已确认，直接下单', orderActionBatch: '已确认，直接下单（{count}个）', soldOut: '已售罄',
      quantity: '下单数量', quickQuantity: '一次{count}个', decreaseQuantity: '减少下单数量', increaseQuantity: '增加下单数量',
      orderQuantityInput: '下单数量输入', totalPrice: '合计', totalSaved: '共省', quantityMaxHint: '单次最多支持 500 个订阅',
      batchQrTitle: '客户订阅二维码（共 {total} 个 · 待领取 {pending} / 已领取 {claimed}）',
      batchQrHint: '点击右侧下载按钮可批量保存全部二维码图片，每张二维码仅限一位客户扫描领取一次。',
      batchQrPreparing: '正在生成二维码图片（{done}/{total}）…',
      downloadBatchQrs: '下载全部二维码 ({count}张)',
      downloadingBatchQrs: '正在下载 ({count}张)…',
      batchOrderUnconfirmed: '未能确认本次（{count}个）下单结果，请先查看我的订单',
      showPrices: '显示价格', hidePrices: '隐藏价格', retry: '重试',
      networkUnavailable: '网络暂时不可用，请检查网络后重试', serviceUnavailable: '服务暂时不可用，请稍后重试',
      orderUnconfirmed: '未能确认下单结果，请先查看我的订单', checkPurchaseOrders: '查看我的订单',
      writeUnconfirmed: '未能确认操作结果，请先核对记录，勿重复提交',
      purchaseChecked: '我已核对，继续下单', retryDelivery: '重新获取交付信息', resumePurchase: '恢复本次下单',
      abandonPurchase: '核对无订单，放弃本次下单', abandonPurchaseConfirmText: '确认已核对订单列表中没有本次订单，放弃后可重新下单。', confirmAbandonPurchase: '确认放弃', cancelAbandonPurchase: '暂不放弃',
      abandonPurchaseTooEarly: '暂未查到本次订单，原请求可能仍在处理中，请在 {minutes} 分钟后再次查看我的订单', abandonPurchaseStale: '本次下单状态已在其他页面更新，请重新核对', purchaseAbandoned: '已放弃本次下单',
      deliveryUnavailable: '订单已创建，暂未获取到交付信息，请查看订单或重新获取',
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
      remark: '备注', actions: '操作', customerName: '用户名称', subscriptionName: '订阅名称',
      boundDevices: '已绑定设备', unboundDevice: '尚未绑定设备', hwidDisabled: '未启用设备绑定',
      viewSubscriptionQr: '查看订阅二维码', subscriptionQrAction: '二维码', subscriptionPending: '订阅尚未生成',
      resetSubscription: '重置订阅', resetSubscriptionConfirm: '确认重置全部设备',
      resetSubscriptionHint: '旧订阅链接和二维码将失效，全部设备绑定将被清除。所有设备需要重新扫码。套餐流量、已用流量和到期时间保持不变。',
      resetSubscriptionPending: '正在重置，请勿使用旧二维码。', resetSubscriptionUncertain: '尚未确认本次重置结果，旧二维码已隐藏。点击“恢复本次重置”安全重试同一次操作。',
      resetSubscriptionRetry: '恢复本次重置', resetSubscriptionSuccess: '订阅已重置，请让所有设备重新扫码。旧连接凭据将在节点同步后失效。',
      resetSubscriptionConflict: '订阅状态已变化，请重新获取二维码后再确认重置。', resetSubscriptionRefresh: '重新获取二维码', resetSubscriptionDevices: '将清除 {count} 个设备绑定',
      resetInOrdersTag: '我的订单页面也可以重置',
      subscriptionBoundDevice: '订阅已绑定设备', subscriptionUnboundDevice: '订阅尚未绑定设备',
      subscriptionHwidDisabled: '该订阅未启用设备绑定', copyImage: '复制图片', downloadImage: '下载图片',
      copyImageUnsupported: '当前浏览器不支持复制图片，请使用下载图片',
      entitlement: '订阅权益', viewEntitlement: '查看订阅权益', hideEntitlement: '收起订阅权益', entitlementAction: '权益', totalTraffic: '总流量', usedTraffic: '已用流量', remainingTraffic: '剩余流量',
      expiresAt: '到期时间', speedLimit: '限速', deviceLimit: '设备限制', permanent: '长期有效', unlimited: '不限',
      renew: '续费', renewTitle: '续费现有订阅', renewHint: '续费后订阅链接、二维码、UUID 和已绑定设备保持不变。',
      renewUnconfirmed: '续费结果尚未确认。点击重试将继续核对同一次续费，请勿另外发起续费。',
      renewPeriod: '续费周期', renewCurrentExpiry: '当前到期时间', renewConfirm: '确认续费', renewSuccess: '续费成功',
      renewOrder: '续费订单', renewNewExpiry: '新的到期时间', orderType: '订单类型', originalOrder: '关联原订单', newPurchase: '新购',
      inviteUsers: '已邀请用户', validCommission: '有效佣金', pendingCommission: '确认中佣金',
      rate: '佣金比例', availableCommission: '可用佣金', generateCode: '生成邀请码', transfer: '佣金划转余额',
      inviteCode: '邀请码', copy: '复制邀请链接', commissionHistory: '佣金记录', noCode: '暂无邀请码',
      success: '操作成功', language: '语言', dark: '深色模式', light: '浅色模式', account: '账号',
      settlementFilter: '结算状态', allSettlements: '全部', exportExcel: '导出 Excel', exportSuccess: 'Excel 导出成功',
      orderSearchPlaceholder: '输入短订阅号、订单号或用户名称查询', search: '查询', clear: '清空',
      orderOverview: '订单经营概览', orderOverviewHint: '统计与订单筛选相互独立，收入为所选时间内订单金额的累计值。',
      todayIncome: '今日收入', todayOrders: '今日订单', yesterdayIncome: '昨日收入', yesterdayOrders: '昨日订单',
      income: '收入', orderCount: '订单', customRange: '自定义', apply: '应用', advancedFilters: '高级筛选', hideFilters: '收起筛选',
      expandOrderColumns: '展开', collapseOrderColumns: '收起',
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
      saved: 'Saved', orderAction: 'Confirmed — place order', orderActionBatch: 'Confirmed — place {count} orders', soldOut: 'Sold out',
      quantity: 'Quantity', quickQuantity: '{count} pcs', decreaseQuantity: 'Decrease quantity', increaseQuantity: 'Increase quantity',
      orderQuantityInput: 'Order quantity', totalPrice: 'Total', totalSaved: 'Total saved', quantityMaxHint: 'Up to 500 subscriptions per order',
      batchQrTitle: 'Customer subscription QRs ({total} total · {pending} pending / {claimed} claimed)',
      batchQrHint: 'Click the download button to save all QR images. Each QR can only be claimed once.',
      batchQrPreparing: 'Generating QR images ({done}/{total})…',
      downloadBatchQrs: 'Download all QRs ({count})',
      downloadingBatchQrs: 'Downloading ({count})…',
      batchOrderUnconfirmed: 'Order result for {count} subscriptions unconfirmed. Please check My Orders first.',
      showPrices: 'Show prices', hidePrices: 'Hide prices', retry: 'Retry',
      networkUnavailable: 'Network unavailable. Check your connection and retry.', serviceUnavailable: 'Service temporarily unavailable. Please try again later.',
      orderUnconfirmed: 'Order result unconfirmed. Please check My Orders first.', checkPurchaseOrders: 'Check My Orders',
      writeUnconfirmed: 'Operation result unconfirmed. Check your records before submitting again.',
      purchaseChecked: 'I have checked, place a new order', retryDelivery: 'Retrieve delivery again', resumePurchase: 'Recover this order',
      abandonPurchase: 'No order found, abandon this purchase', abandonPurchaseConfirmText: 'Confirm that My Orders does not contain this purchase. You can place a new order afterwards.', confirmAbandonPurchase: 'Confirm abandon', cancelAbandonPurchase: 'Keep it',
      abandonPurchaseTooEarly: 'This order was not found yet. The original request may still be processing; check My Orders again in {minutes} minutes.', abandonPurchaseStale: 'This purchase was updated in another tab. Please check again.', purchaseAbandoned: 'This purchase was abandoned',
      deliveryUnavailable: 'Your order was created, but delivery details could not be loaded. Check My Orders or retrieve delivery again.',
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
      remark: 'Remark', actions: 'Actions', customerName: 'Customer name', subscriptionName: 'Subscription name',
      boundDevices: 'Bound devices', unboundDevice: 'No device bound', hwidDisabled: 'Device binding disabled',
      viewSubscriptionQr: 'View subscription QR', subscriptionQrAction: 'QR', subscriptionPending: 'Subscription not generated',
      resetSubscription: 'Reset subscription', resetSubscriptionConfirm: 'Confirm reset for all devices',
      resetSubscriptionHint: 'The old subscription link and QR will expire, and all device bindings will be cleared. Every device must scan the new QR. Total and used traffic and the expiry date stay unchanged.',
      resetSubscriptionPending: 'Resetting. Do not use the old QR.', resetSubscriptionUncertain: 'This reset is not confirmed yet. The old QR is hidden. Recover this reset to safely retry the same operation.',
      resetSubscriptionRetry: 'Recover this reset', resetSubscriptionSuccess: 'Subscription reset. All devices must scan again. Old connection credentials expire after nodes synchronize.',
      resetSubscriptionConflict: 'The subscription has changed. Fetch its current QR before confirming another reset.', resetSubscriptionRefresh: 'Fetch current QR', resetSubscriptionDevices: '{count} device bindings will be cleared',
      resetInOrdersTag: 'Also available in My Orders',
      subscriptionBoundDevice: 'Subscription bound to device', subscriptionUnboundDevice: 'Subscription has no bound device',
      subscriptionHwidDisabled: 'Device binding is disabled for this subscription', copyImage: 'Copy image', downloadImage: 'Download image',
      copyImageUnsupported: 'This browser cannot copy images. Use Download image instead.',
      entitlement: 'Subscription entitlement', viewEntitlement: 'View subscription entitlement', hideEntitlement: 'Hide subscription entitlement', entitlementAction: 'Entitlement', totalTraffic: 'Total traffic', usedTraffic: 'Used traffic', remainingTraffic: 'Remaining traffic',
      expiresAt: 'Expires at', speedLimit: 'Speed limit', deviceLimit: 'Device limit', permanent: 'Never expires', unlimited: 'Unlimited',
      renew: 'Renew', renewTitle: 'Renew subscription', renewHint: 'The subscription URL, QR code, UUID, and bound devices will stay unchanged.',
      renewUnconfirmed: 'Renewal result unconfirmed. Retry to resolve this same renewal before starting another one.',
      renewPeriod: 'Renewal period', renewCurrentExpiry: 'Current expiry', renewConfirm: 'Confirm renewal', renewSuccess: 'Renewed',
      renewOrder: 'Renewal order', renewNewExpiry: 'New expiry', orderType: 'Order type', originalOrder: 'Original order', newPurchase: 'New purchase',
      inviteUsers: 'Invited users', validCommission: 'Valid commission', pendingCommission: 'Pending commission',
      rate: 'Commission rate', availableCommission: 'Available commission', generateCode: 'Generate code',
      transfer: 'Transfer commission', inviteCode: 'Invite code', copy: 'Copy invite link',
      commissionHistory: 'Commission history', noCode: 'No invite code', success: 'Success',
      language: 'Language', dark: 'Dark mode', light: 'Light mode', account: 'Account',
      settlementFilter: 'Settlement', allSettlements: 'All', exportExcel: 'Export Excel', exportSuccess: 'Excel exported',
      orderSearchPlaceholder: 'Search by subscription code, order or customer name', search: 'Search', clear: 'Clear',
      orderOverview: 'Order performance', orderOverviewHint: 'Analytics are independent from list filters. Income is the sum of order amounts in the selected dates.',
      todayIncome: "Today's income", todayOrders: "Today's orders", yesterdayIncome: "Yesterday's income", yesterdayOrders: "Yesterday's orders",
      income: 'Income', orderCount: 'orders', customRange: 'Custom', apply: 'Apply', advancedFilters: 'More filters', hideFilters: 'Hide filters',
      expandOrderColumns: 'Expand', collapseOrderColumns: 'Collapse',
      startDate: 'Start date', endDate: 'End date', minimumAmount: 'Minimum amount', maximumAmount: 'Maximum amount', allPeriods: 'All periods',
      trend: 'Growth trend', thisWeek: 'This week', thisMonth: 'This month', lastNinetyDays: 'Last 90 days', dailyOrders: 'Daily orders', dailyIncome: 'Daily income',
      firstPage: 'First', previousPage: 'Previous', nextPage: 'Next', lastPage: 'Last', jumpTo: 'Go to', jump: 'Go', loadMore: 'Load more', pageSize: 'Per page', totalOrders: '{count} orders',
      viewAllDevices: 'View all {count}', collapseDevices: 'Collapse devices', filterReset: 'Reset filters',
      knowledgeSubtitle: 'Browse product guides and frequently asked questions.', knowledgeSearchPlaceholder: 'Search documentation',
      lastUpdated: 'Last updated', noArticles: 'No documentation available', copyShare: 'Copy share link', copySuccess: 'Copied', copyFailed: 'Copy failed. Try again.',
    },
  };

  const state = {
    notices: [],
    noticeIndex: 0,
    noticeTimer: null,
    noticeError: false,
    active: false,
    user: null,
    locale: localStorage.getItem('xboard_distributor_locale') || 'zh-CN',
    dark: localStorage.getItem('xboard_distributor_dark') === '1',
    loading: false,
    modal: null,
    modalTrigger: null,
    resetModal: null,
    resetModalTrigger: null,
    resetModalTarget: null,
    poller: null,
    orderSettlementStatus: '',
    orderSearch: '',
    orderFiltersOpen: false,
    orderFixedColumnsExpanded: true,
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
    selectedQuantities: {},
    purchasingPlanId: null,
    purchaseRecovery: null,
    inFlightPurchaseKey: null,
    renewalOperations: new Map(),
    subscriptionResetOperations: new Map(),
    purchaseOperationId: 0,
    modalOperationId: 0,
    planPricesVisible: false,
    planPricesTimer: null,
    renderGeneration: 0,
    routeRenderHash: null,
    contentRouteHash: null,
    analyticsRequestToken: 0,
    orders: [],
    sessionEpoch: 1,
    sharedSessionEpoch: null,
    sessionToken: null,
    activeSessionEpoch: null,
    activeUserKey: null,
    detectInFlight: null,
    decidedToken: null,
    detectFailureCount: 0,
    nextDetectAt: 0,
    sessionControllers: new Set(),
    routeControllers: new Set(),
    modalControllers: new Set(),
    lastHiddenAt: 0,
    resumeTimer: null,
    pendingResume: null,
    needsRouteRecovery: false,
    sessionCheckInFlight: null,
    detectorTimer: null,
  };

  const t = (key) => (COPY[state.locale] || COPY['zh-CN'])[key] || key;
  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  function distributorAccountLabel(user) {
    const distributorName = String(user?.distributor_name ?? '').trim();
    return distributorName || String(user?.email ?? '').trim();
  }
  function subscriptionLabel(order) {
    return String(order?.subscription_name || order?.trade_no || '');
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
  async function composeSubscriptionQrPng(payload, options = {}) {
    const qrImage = await loadImage(payload.qr_code);
    const width = 760;
    const padding = 52;
    const qrSize = 540;
    const titleLineHeight = 43;
    const detailLineHeight = 31;
    const canvas = document.createElement('canvas');
    const measure = canvas.getContext('2d');
    const hasBatchHeader = Number.isInteger(options.batchIndex) && options.batchIndex > 0;
    const topPadding = hasBatchHeader ? 38 : padding;
    let badgeText = '';
    let badgeWidth = 0;
    const badgeHeight = 34;
    let identityX = padding;
    let batchPrimaryLines = [];
    let batchSecondaryLines = [];
    let batchHeaderBlockHeight = 0;
    if (hasBatchHeader) {
      badgeText = `#${options.batchIndex}`;
      measure.font = '800 20px "Microsoft YaHei", "PingFang SC", sans-serif';
      badgeWidth = Math.max(50, Math.ceil(measure.measureText(badgeText).width) + 26);
      identityX = padding + badgeWidth + 14;
      const identityMaxWidth = width - padding - identityX;
      measure.font = '700 22px "Microsoft YaHei", "PingFang SC", sans-serif';
      batchPrimaryLines = wrapCanvasText(measure, subscriptionLabel(payload), identityMaxWidth);
      if (payload.subscription_name && payload.trade_no) {
        measure.font = '500 18px "Microsoft YaHei", "PingFang SC", sans-serif';
        batchSecondaryLines = wrapCanvasText(measure, `${t('orderNo')}：${payload.trade_no}`, identityMaxWidth);
      }
      const identityHeight = batchPrimaryLines.length * 28 + (batchSecondaryLines.length ? 4 + batchSecondaryLines.length * 24 : 0);
      batchHeaderBlockHeight = Math.max(badgeHeight, identityHeight) + 34;
    }
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
    const detailLines = [payload.subscription_name || `${t('orderNo')} ${payload.trade_no}`, ...deviceTexts]
      .flatMap((line) => wrapCanvasText(measure, line, width - padding * 2));
    const headerHeight = titleLines.length * titleLineHeight + 10 + detailLines.length * detailLineHeight + 20;
    canvas.width = width;
    canvas.height = topPadding + batchHeaderBlockHeight + headerHeight + qrSize + padding;
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    if (hasBatchHeader) {
      context.fillStyle = '#e7f4f4';
      const radius = badgeHeight / 2;
      if (typeof context.beginPath === 'function' && typeof context.arc === 'function') {
        context.beginPath();
        context.arc(padding + radius, topPadding + radius, radius, Math.PI / 2, Math.PI * 1.5);
        context.arc(padding + badgeWidth - radius, topPadding + radius, radius, Math.PI * 1.5, Math.PI / 2);
        context.closePath?.();
        context.fill?.();
      } else {
        context.fillRect(padding, topPadding, badgeWidth, badgeHeight);
      }
      context.fillStyle = '#287c83';
      context.font = '800 20px "Microsoft YaHei", "PingFang SC", sans-serif';
      context.textAlign = 'center';
      context.textBaseline = 'middle';
      context.fillText(badgeText, padding + badgeWidth / 2, topPadding + badgeHeight / 2);
      context.textAlign = 'left';
      context.textBaseline = 'top';
      context.fillStyle = '#17212b';
      context.font = '700 22px "Microsoft YaHei", "PingFang SC", sans-serif';
      let iy = topPadding + 1;
      batchPrimaryLines.forEach((line) => {
        context.fillText(line, identityX, iy);
        iy += 28;
      });
      if (batchSecondaryLines.length) {
        iy += 4;
        context.fillStyle = '#667789';
        context.font = '500 18px "Microsoft YaHei", "PingFang SC", sans-serif';
        batchSecondaryLines.forEach((line) => {
          context.fillText(line, identityX, iy);
          iy += 24;
        });
      }
      context.fillStyle = '#e4e9ef';
      context.fillRect(padding, topPadding + batchHeaderBlockHeight - 18, width - padding * 2, 1);
    }
    context.textAlign = 'center';
    context.textBaseline = 'top';
    context.fillStyle = '#111827';
    context.font = '800 32px "Microsoft YaHei", "PingFang SC", sans-serif';
    let y = topPadding + batchHeaderBlockHeight;
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
    context.drawImage(qrImage, (width - qrSize) / 2, topPadding + batchHeaderBlockHeight + headerHeight, qrSize, qrSize);
    const blob = await canvasBlob(canvas);
    if (options.preferObjectUrl && typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function') {
      const objectUrl = URL.createObjectURL(blob);
      options.objectUrls?.add?.(objectUrl);
      return { imageUrl: objectUrl, blob, isObjectUrl: true };
    }
    return { imageUrl: canvas.toDataURL('image/png'), blob };
  }
  function revokeBatchModalUrls(modal) {
    if (!modal || modal.type !== 'batchDelivery' || !modal.objectUrls) return;
    modal.objectUrls.forEach((url) => {
      try { URL.revokeObjectURL(url); } catch (_) { /* ignore */ }
    });
    modal.objectUrls.clear();
  }
  function revokeTrackedObjectUrl(objectUrls, url) {
    if (!url || typeof URL === 'undefined' || typeof URL.revokeObjectURL !== 'function') return;
    if (objectUrls && typeof objectUrls.delete === 'function') {
      objectUrls.delete(url);
    }
    try { URL.revokeObjectURL(url); } catch (_) { /* ignore */ }
  }
  function sanitizeFilenamePart(value) {
    return String(value || '')
      .replace(/[\\/:*?"<>|\u0000-\u001f]+/g, '-')
      .replace(/\s+/g, ' ')
      .trim() || 'subscription';
  }
  function buildBatchDeliveryDownloads(items) {
    const usedNames = new Set();
    const padWidth = Math.max(2, String(items.length).length);
    const entries = [];
    items.forEach((item, index) => {
      if (!item?.blob) return;
      const prefix = String(index + 1).padStart(padWidth, '0');
      const baseLabel = sanitizeFilenamePart(subscriptionLabel(item.delivery));
      let filename = `订阅二维码-#${prefix}-${baseLabel}.png`;
      if (usedNames.has(filename)) {
        filename = `订阅二维码-#${prefix}-${baseLabel}-${sanitizeFilenamePart(String(item.delivery?.trade_no || '').slice(-6))}.png`;
      }
      usedNames.add(filename);
      entries.push({ name: filename, blob: item.blob, index });
    });
    return entries;
  }
  function syncBatchDownloadState(batchModal) {
    if (!batchModal || batchModal.type !== 'batchDelivery') return;
    batchModal.downloadableCount = batchModal.items.filter((entry) => Boolean(entry.blob)).length;
    batchModal.imagesReady = batchModal.renderedCount >= batchModal.items.length
      && batchModal.items.every((entry) => !entry.needsQrRefresh
        && (entry.delivery.delivery_status !== 0 || Boolean(entry.blob))
        && !state.subscriptionResetOperations?.has?.(entry.delivery.trade_no));
  }
  async function downloadBatchDeliveryImages(batchModal) {
    if (!batchModal || batchModal.type !== 'batchDelivery' || !batchModal.imagesReady || batchModal.downloadingAll) return;
    const entries = buildBatchDeliveryDownloads(batchModal.items);
    if (!entries.length) return;
    const startGeneration = batchModal.assetGeneration;
    const isStillValidBatchDownload = () => state.active
      && state.modal === batchModal
      && !state.resetModal
      && batchModal.imagesReady
      && batchModal.assetGeneration === startGeneration;
    batchModal.downloadingAll = true;
    if (state.modal === batchModal) renderModal();
    try {
      const delayMs = entries.length > 1
        ? Math.min(80, Math.max(8, Math.floor(3200 / (entries.length - 1))))
        : 0;
      triggerBlobDownload(entries[0].blob, entries[0].name);
      for (let i = 1; i < entries.length; i += 1) {
        await new Promise((resolve) => window.setTimeout(resolve, delayMs));
        if (!isStillValidBatchDownload()) break;
        triggerBlobDownload(entries[i].blob, entries[i].name);
      }
    } finally {
      batchModal.downloadingAll = false;
      if (state.modal === batchModal) renderModal();
    }
  }
  function triggerBlobDownload(blob, filename) {
    if (!blob) return;
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 60000);
  }
  function normalizeOrderQuantity(value) {
    const parsed = Number.parseInt(String(value ?? '').replace(/[^\d]/g, ''), 10);
    if (!Number.isInteger(parsed) || parsed < 1) return 1;
    return Math.min(MAX_ORDER_QUANTITY, parsed);
  }
  function selectedPlanQuantity(planId) {
    return normalizeOrderQuantity(state.selectedQuantities[planId] ?? 1);
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

  const READ_TIMEOUT_MS = 12000;
  const RESUME_REVALIDATE_MS = 60000;
  const RESUME_COALESCE_MS = 50;
  const READ_RETRY_DELAY_MS = 400;

  function classifyApiRequest(path, method = 'GET') {
    const cleanPath = String(path || '').split('?')[0];
    const upperMethod = String(method || 'GET').toUpperCase();
    if (cleanPath === '/user/distributor/delivery/batch') {
      return { readOnly: true, scope: 'modal', timeoutMs: BATCH_DELIVERY_TIMEOUT_MS };
    }
    if (upperMethod !== 'GET') {
      return { readOnly: false, scope: 'none', timeoutMs: 0 };
    }
    if (cleanPath === '/user/info') {
      return { readOnly: true, scope: 'session', timeoutMs: READ_TIMEOUT_MS };
    }
    if ([
      '/user/plan/fetch',
      '/user/notice/fetch',
      '/user/order/fetch',
      '/user/order/detail',
      '/user/order/statistics',
      '/user/invite/fetch',
      '/user/invite/details',
    ].includes(cleanPath)) {
      return { readOnly: true, scope: 'route', timeoutMs: READ_TIMEOUT_MS };
    }
    if (['/user/distributor/delivery', '/user/distributor/subscription-qr'].includes(cleanPath)) {
      return { readOnly: true, scope: 'modal', timeoutMs: READ_TIMEOUT_MS };
    }
    if (cleanPath === '/user/knowledge/fetch') {
      return {
        readOnly: true,
        scope: /[?&]id=/.test(String(path || '')) ? 'modal' : 'route',
        timeoutMs: READ_TIMEOUT_MS,
      };
    }
    return { readOnly: false, scope: 'none', timeoutMs: 0 };
  }

  function controllerSetForScope(scope) {
    if (scope === 'session') return state.sessionControllers;
    if (scope === 'route') return state.routeControllers;
    if (scope === 'modal') return state.modalControllers;
    return null;
  }

  function abortScopedRequests(scope, reason = 'cancelled') {
    const set = controllerSetForScope(scope);
    if (!set) return;
    Array.from(set).forEach((record) => {
      set.delete(record);
      if (typeof record.triggerAbort === 'function') {
        record.triggerAbort(reason);
      }
    });
  }

  function isConfirmedAuthFailure(status, message) {
    if (window.__xboardAuthSession?.isConfirmedAuthFailure) {
      return Boolean(window.__xboardAuthSession.isConfirmedAuthFailure(status, message));
    }
    const code = Number(status || 0);
    const normalized = typeof message === 'string' ? message.trim() : '';
    return code === 401 || (code === 403 && normalized === '未登录或登陆已过期');
  }

  function makeCancelledError() {
    const error = new Error('Request cancelled');
    error.name = 'AbortError';
    error.abortReason = 'cancelled';
    error.isCancelled = true;
    return error;
  }

  function makeTimeoutError() {
    const error = new Error(state.locale === 'zh-CN' ? '请求超时，请点击重试' : 'Request timed out. Please retry.');
    error.name = 'AbortError';
    error.abortReason = 'timeout';
    error.isTimeout = true;
    error.status = 0;
    return error;
  }

  function makeNetworkError() {
    const error = new Error(t('networkUnavailable'));
    error.status = 0;
    error.isNetworkError = true;
    error.isTransientRead = true;
    return error;
  }

  function displayError(error) {
    if (error?.isNetworkError || error?.message === 'Failed to fetch') return t('networkUnavailable');
    if (Number(error?.status) >= 500) return t('serviceUnavailable');
    return error?.message || t('serviceUnavailable');
  }

  function isValidUserIdentity(user) {
    if (!user || typeof user !== 'object' || Array.isArray(user)) return false;
    const hasIdentity = (typeof user.email === 'string' && user.email.trim() !== '')
      || (user.id !== undefined && user.id !== null)
      || (typeof user.uuid === 'string' && user.uuid.trim() !== '');
    const hasRole = typeof user.is_distributor === 'boolean'
      || user.is_distributor === 0
      || user.is_distributor === 1;
    return Boolean(hasIdentity && hasRole);
  }

  function resetAccountScopedState() {
    state.plans = [];
    state.planFilter = 'all';
    state.selectedPeriods = {};
    state.selectedQuantities = {};
    state.orders = [];
    state.orderSettlementStatus = '';
    state.orderSearch = '';
    state.orderFiltersOpen = false;
    state.orderFixedColumnsExpanded = true;
    state.orderFilters = { startDate: '', endDate: '', periods: [], minAmount: '', maxAmount: '' };
    state.orderPage = 1;
    state.orderPerPage = 20;
    state.orderTotal = 0;
    state.orderLastPage = 1;
    state.expandedDeviceOrders = {};
    state.orderSummaryRange = null;
    state.orderTrendPreset = 'week';
    state.orderTrendRange = null;
    state.orderSummary = null;
    state.orderTrend = null;
    state.knowledgeSearch = '';
    state.notices = [];
    state.noticeIndex = 0;
    state.noticeError = false;
    state.purchasingPlanId = null;
    state.purchaseRecovery = null;
    state.inFlightPurchaseKey = null;
    state.renewalOperations.clear();
    state.subscriptionResetOperations.clear();
    state.resetModal = null;
    state.resetModalTrigger = null;
    state.resetModalTarget = null;
    state.needsRouteRecovery = false;
    state.routeRenderHash = null;
    state.contentRouteHash = null;
    state.purchaseOperationId += 1;
    state.modalOperationId += 1;
  }

  function syncSession() {
    const shared = window.__xboardAuthSession?.captureSession ? window.__xboardAuthSession.captureSession() : null;
    const nextToken = shared ? shared.token : authToken();
    const nextSharedEpoch = shared ? shared.epoch : null;
    if (
      nextToken !== state.sessionToken ||
      (nextSharedEpoch !== null && nextSharedEpoch !== state.sharedSessionEpoch)
    ) {
      state.sessionToken = nextToken;
      state.sharedSessionEpoch = nextSharedEpoch;
      state.sessionEpoch += 1;
      state.decidedToken = null;
      state.detectFailureCount = 0;
      state.nextDetectAt = 0;
      state.detectInFlight = null;
      state.sessionCheckInFlight = null;
      resetAccountScopedState();
      abortScopedRequests('session', 'cancelled');
      abortScopedRequests('route', 'cancelled');
      abortScopedRequests('modal', 'cancelled');
      if (state.active) {
        deactivateDistributorView();
      }
    }
    return {
      epoch: shared ? shared.epoch : state.sessionEpoch,
      localEpoch: state.sessionEpoch,
      token: state.sessionToken,
    };
  }

  function isCurrentSession(snapshot) {
    const current = syncSession();
    if (!snapshot) return false;
    if (window.__xboardAuthSession?.isCurrentSession && !window.__xboardAuthSession.isCurrentSession(snapshot)) {
      return false;
    }
    const snapshotLocalEpoch = snapshot.localEpoch ?? snapshot.epoch;
    return (
      snapshotLocalEpoch === current.localEpoch &&
      snapshot.token === current.token
    );
  }

  async function api(path, options = {}) {
    const requestSession = syncSession();
    const token = requestSession.token;
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
    const policy = classifyApiRequest(path, method);
    if (policy.readOnly && document.hidden) {
      if (policy.scope === 'route' && String(path).split('?')[0] !== '/user/notice/fetch') state.needsRouteRecovery = true;
      throw makeCancelledError();
    }
    const canRetryRead = policy.readOnly && ['/user/info', '/user/plan/fetch'].includes(String(path).split('?')[0]);
    const deadline = Date.now() + policy.timeoutMs;
    const scopeSet = controllerSetForScope(policy.scope);
    let rejectAbort = null;
    const record = policy.readOnly ? {
      path: String(path).split('?')[0],
      controller: typeof AbortController === 'function' ? new AbortController() : null,
      timer: null,
      abortReason: null,
      triggerAbort(reason) {
        if (this.abortReason) return;
        this.abortReason = reason;
        if (this.timer) {
          clearTimeout(this.timer);
          this.timer = null;
        }
        try { this.controller?.abort(); } catch (_) { /* ignore */ }
        if (rejectAbort) {
          rejectAbort(reason === 'timeout' ? makeTimeoutError() : makeCancelledError());
        }
      },
    } : null;
    const abortPromise = record ? new Promise((_, reject) => {
      rejectAbort = reject;
    }) : null;
    if (record && scopeSet) scopeSet.add(record);
    if (record && policy.timeoutMs > 0) {
      record.timer = setTimeout(() => {
        record.triggerAbort('timeout');
      }, policy.timeoutMs);
    }

    const separator = path.includes('?') ? '&' : '?';
    const fetchOptions = {
      method, headers, body, cache: 'no-store', credentials: 'same-origin',
    };
    if (record?.controller) {
      fetchOptions.signal = record.controller.signal;
    }

    const fetchAndRead = async () => {
      const response = await fetch(`${API_BASE}${path}${method === 'GET' ? `${separator}t=${Date.now()}` : ''}`, fetchOptions);
      let payload = null;
      let parseFailed = false;
      if (typeof response.text === 'function') {
        const rawText = await response.text();
        try {
          payload = rawText ? JSON.parse(rawText) : null;
        } catch (_) {
          parseFailed = true;
        }
      } else if (typeof response.json === 'function') {
        try {
          payload = await response.json();
        } catch (readError) {
          if (readError?.name !== 'SyntaxError') throw readError;
          parseFailed = true;
        }
      }
      return { response, payload, parseFailed };
    };

    const runAttempts = async () => {
      for (let attempt = 0; ; attempt += 1) {
        if (!isCurrentSession(requestSession) || record?.abortReason === 'cancelled') throw makeCancelledError();
        if (record?.abortReason === 'timeout' || (policy.readOnly && Date.now() >= deadline)) throw makeTimeoutError();
        let result;
        let networkError = null;
        try {
          result = await fetchAndRead();
        } catch (error) {
          if (error?.name !== 'TypeError' && error?.message !== 'Failed to fetch') throw error;
          networkError = makeNetworkError();
        }
        if (!isCurrentSession(requestSession) || record?.abortReason === 'cancelled') throw makeCancelledError();
        if (record?.abortReason === 'timeout') throw makeTimeoutError();
        if (policy.readOnly && document.hidden) {
          if (policy.scope === 'route' && String(path).split('?')[0] !== '/user/notice/fetch') state.needsRouteRecovery = true;
          throw makeCancelledError();
        }
        const transientStatus = [502, 503, 504].includes(Number(result?.response?.status));
        if (canRetryRead && attempt === 0 && (networkError || transientStatus)) {
          await new Promise((resolve) => setTimeout(resolve, READ_RETRY_DELAY_MS));
          continue;
        }
        if (networkError) throw networkError;
        return result;
      }
    };

    try {
      const task = runAttempts();
      if (abortPromise) task.catch(() => { });
      const { response, payload, parseFailed } = abortPromise
        ? await Promise.race([task, abortPromise])
        : await task;
      if (record?.abortReason === 'cancelled' || !isCurrentSession(requestSession)) {
        throw makeCancelledError();
      }
      if (record?.abortReason === 'timeout') {
        throw makeTimeoutError();
      }
      if (!response.ok || payload?.status === 'fail' || parseFailed || !payload || typeof payload !== 'object') {
        const rawMessage = typeof payload?.message === 'string' ? payload.message : '';
        const error = new Error(rawMessage || `Request failed (${response.status})`);
        error.status = response.status;
        error.rawMessage = rawMessage;
        error.payload = payload;
        error.isTransientRead = [502, 503, 504].includes(Number(response.status));
        error.isUncertainWrite = !policy.readOnly && (response.status >= 500 || parseFailed || !payload || typeof payload !== 'object');
        if (isCurrentSession(requestSession) && isConfirmedAuthFailure(response.status, rawMessage)) {
          error.isConfirmedAuthFailure = true;
          state.decidedToken = requestSession.token;
          const wasActive = state.active;
          if (window.__xboardAuthSession?.invalidateSession) {
            window.__xboardAuthSession.invalidateSession(requestSession);
          } else {
            localStorage.removeItem(TOKEN_KEY);
          }
          syncSession();
          if (wasActive) {
            deactivateDistributorView();
            window.location.href = '/#/login';
          }
        }
        throw error;
      }
      return payload;
    } catch (error) {
      if (record?.abortReason === 'cancelled' || error?.isCancelled) {
        const cancelled = makeCancelledError();
        if (error?.isConfirmedAuthFailure) cancelled.isConfirmedAuthFailure = true;
        throw cancelled;
      }
      if (policy.readOnly && (record?.abortReason === 'timeout' || error?.isTimeout || error?.name === 'AbortError')) {
        throw makeTimeoutError();
      }
      if (!isCurrentSession(requestSession)) {
        const cancelled = makeCancelledError();
        // The server rejected this request before any write; callers may release write-ahead intents.
        if (error?.isConfirmedAuthFailure) cancelled.isConfirmedAuthFailure = true;
        throw cancelled;
      }
      if (!policy.readOnly && (!error?.status || error.status >= 500)) error.isUncertainWrite = true;
      if (error.isUncertainWrite) error.message = t('writeUnconfirmed');
      throw error;
    } finally {
      if (record?.timer) {
        clearTimeout(record.timer);
        record.timer = null;
      }
      if (record && scopeSet) {
        scopeSet.delete(record);
      }
    }
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

  function toastErrorIfCurrent(error, sessionSnapshot = null) {
    if (!error || error.isCancelled) return;
    if (sessionSnapshot && !isCurrentSession(sessionSnapshot)) return;
    if (!state.active || document.hidden) return;
    toast(displayError(error), 'error');
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
    abortScopedRequests('route', 'cancelled');
    state.routeRenderHash = window.location.hash;
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
          <main class="dist-content">${page === '/plan' ? `<div id="dist-notice-slot">${renderNoticeBar()}</div>` : ''}${renderPurchaseRecovery()}${content}</main>
        </section>
      </div>`;
  }

  function setContent(content) {
    const root = document.getElementById('distributor-app');
    if (!root) return;
    root.innerHTML = shell(content);
    state.contentRouteHash = window.location.hash;
    startNoticeRotation();
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

  async function renderPage(options = {}) {
    if (!state.active) return;
    if (document.hidden) { state.needsRouteRecovery = true; return; }
    const page = currentPage();
    if (!isCurrentRouteCanonical(page)) {
      navigate(page);
      return;
    }
    const renderContext = beginRouteRender(page);
    const root = document.getElementById('distributor-app');
    const preserveContent = options.preserveContent && state.contentRouteHash === window.location.hash && root?.children.length
      && !root.querySelector('.dist-loading, .dist-error');
    if (!preserveContent) loadingView();
    try {
      if (page === '/overview') await renderOverview(renderContext);
      else if (page === '/order') await renderOrders({ renderContext });
      else if (page === '/invite') await renderInvite(renderContext);
      else if (page === '/knowledge') await renderKnowledge(renderContext);
      else if (page === '/clients') await renderClients();
      else await renderPlans(renderContext);
    } catch (error) {
      if (error?.isCancelled) return;
      if (document.hidden) { state.needsRouteRecovery = true; return; }
      if (isCurrentRouteRender(renderContext)) {
        if (preserveContent) {
          state.needsRouteRecovery = true;
          toastErrorIfCurrent(error);
        } else renderRouteError(error);
      }
    }
  }

  function renderRouteError(error) {
    if (document.hidden) { state.needsRouteRecovery = true; return; }
    setContent(`<div class="dist-error" role="alert"><h2>${escapeHtml(displayError(error))}</h2><button data-action="retry">${t('retry')}</button></div>`);
  }

  function stableDistributorAccountId(user) {
    if (!user || typeof user !== 'object') return '';
    if (user.id !== undefined && user.id !== null && String(user.id).trim() !== '') {
      return `id:${String(user.id).trim()}`;
    }
    if (typeof user.uuid === 'string' && user.uuid.trim() !== '') {
      return `uuid:${user.uuid.trim()}`;
    }
    if (typeof user.email === 'string' && user.email.trim() !== '') {
      return `email:${user.email.trim().toLowerCase()}`;
    }
    return '';
  }

  function purchaseRecoveryStorageKey(user = state.user) {
    const accountId = stableDistributorAccountId(user);
    return accountId ? `${PURCHASE_RECOVERY_STORAGE_KEY}:${accountId}` : '';
  }

  function purchaseRecoveryRecordId(recovery) {
    if (!recovery) return '';
    if (typeof recovery.idempotencyKey === 'string' && recovery.idempotencyKey.trim() !== '') {
      return `key:${recovery.idempotencyKey.trim()}`;
    }
    if (typeof recovery.tradeNo === 'string' && recovery.tradeNo.trim() !== '') {
      return `trade:${recovery.tradeNo.trim()}`;
    }
    return '';
  }

  function normalizeStoredPurchaseRecovery(rec) {
    if (!rec || typeof rec !== 'object') return null;
    const hasConfirmedOrder = typeof rec.tradeNo === 'string' && rec.tradeNo.trim() !== '';
    const hasResumableWrite = typeof rec.idempotencyKey === 'string' && rec.idempotencyKey.trim() !== ''
      && rec.planId !== null && rec.planId !== undefined && typeof rec.period === 'string' && rec.period !== '';
    if (!hasConfirmedOrder && !hasResumableWrite) return null;
    const tradeNos = Array.isArray(rec.tradeNos)
      ? rec.tradeNos.map((item) => String(item || '').trim()).filter(Boolean)
      : [];
    const requestedAt = Number(rec.requestedAt);
    const absenceConfirmedAt = Number(rec.absenceConfirmedAt);
    const lastEmptyCheckAt = Number(rec.lastEmptyCheckAt);
    return {
      idempotencyKey: typeof rec.idempotencyKey === 'string' && rec.idempotencyKey.trim() !== '' ? rec.idempotencyKey.trim() : null,
      planId: rec.planId ?? null,
      planName: typeof rec.planName === 'string' ? rec.planName : '',
      period: typeof rec.period === 'string' ? rec.period : '',
      count: Math.max(1, Number(rec.count || tradeNos.length || 1) || 1),
      tradeNo: hasConfirmedOrder ? rec.tradeNo.trim() : null,
      tradeNos: tradeNos.length ? tradeNos : null,
      checked: hasConfirmedOrder && Boolean(rec.checked),
      // Legacy records without a request time are older than the safe window by definition.
      requestedAt: Number.isFinite(requestedAt) && requestedAt > 0 ? requestedAt : 0,
      absenceConfirmedAt: !hasConfirmedOrder && Number.isFinite(absenceConfirmedAt) && absenceConfirmedAt > 0 ? absenceConfirmedAt : null,
      lastEmptyCheckAt: !hasConfirmedOrder && Number.isFinite(lastEmptyCheckAt) && lastEmptyCheckAt > 0 ? lastEmptyCheckAt : null,
      checking: false,
      resuming: false,
      abandonArmed: false,
    };
  }

  function serializePurchaseRecovery(recovery) {
    return {
      idempotencyKey: recovery.idempotencyKey || null,
      planId: recovery.planId ?? null,
      planName: recovery.planName || '',
      period: recovery.period || '',
      count: Number(recovery.count || recovery.tradeNos?.length || 1),
      tradeNo: recovery.tradeNo || null,
      tradeNos: Array.isArray(recovery.tradeNos) ? recovery.tradeNos : null,
      checked: Boolean(recovery.checked),
      requestedAt: Number(recovery.requestedAt || 0),
      absenceConfirmedAt: recovery.absenceConfirmedAt ? Number(recovery.absenceConfirmedAt) : null,
      lastEmptyCheckAt: recovery.lastEmptyCheckAt ? Number(recovery.lastEmptyCheckAt) : null,
    };
  }

  // Uncertain purchases are stored per account in localStorage so that closing the tab, opening
  // another tab, logging out, or re-authenticating can never discard the original idempotency key.
  function readStoredPurchaseRecoveries(user = state.user) {
    const key = purchaseRecoveryStorageKey(user);
    if (!key) return [];
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      const records = parsed && typeof parsed === 'object' && Array.isArray(parsed.records) ? parsed.records : [];
      const seen = new Set();
      const normalized = [];
      records.forEach((record) => {
        const recovery = normalizeStoredPurchaseRecovery(record);
        const recordId = purchaseRecoveryRecordId(recovery);
        if (!recovery || !recordId || seen.has(recordId)) return;
        seen.add(recordId);
        normalized.push(recovery);
      });
      return normalized.sort((left, right) => left.requestedAt - right.requestedAt);
    } catch (_) {
      return [];
    }
  }

  function writeStoredPurchaseRecoveries(records, user = state.user) {
    const key = purchaseRecoveryStorageKey(user);
    if (!key) return;
    try {
      if (!records.length) {
        localStorage.removeItem(key);
        return;
      }
      localStorage.setItem(key, JSON.stringify({
        schema: PURCHASE_RECOVERY_SCHEMA,
        records: records.map(serializePurchaseRecovery),
      }));
    } catch (_) { /* ignore storage errors */ }
  }

  function upsertStoredPurchaseRecovery(recovery, user = state.user) {
    const recordId = purchaseRecoveryRecordId(recovery);
    if (!recordId) return;
    const stored = readStoredPurchaseRecoveries(user);
    const existing = stored.find((item) => purchaseRecoveryRecordId(item) === recordId);
    if (existing) {
      // Never let a stale tab erase facts learned by another tab of the same account.
      if (existing.requestedAt > Number(recovery.requestedAt || 0)) {
        recovery.requestedAt = existing.requestedAt;
        recovery.absenceConfirmedAt = null;
        recovery.lastEmptyCheckAt = null;
        recovery.abandonArmed = false;
      }
      if (existing.tradeNo && !recovery.tradeNo) {
        recovery.tradeNo = existing.tradeNo;
        recovery.tradeNos = existing.tradeNos;
        recovery.absenceConfirmedAt = null;
        recovery.lastEmptyCheckAt = null;
        recovery.abandonArmed = false;
      }
    }
    const records = stored.filter((item) => purchaseRecoveryRecordId(item) !== recordId);
    records.push(recovery);
    writeStoredPurchaseRecoveries(records, user);
  }

  function deleteStoredPurchaseRecovery(recordId, user = state.user) {
    if (!recordId) return;
    const records = readStoredPurchaseRecoveries(user);
    const remaining = records.filter((item) => purchaseRecoveryRecordId(item) !== recordId);
    if (remaining.length !== records.length) writeStoredPurchaseRecoveries(remaining, user);
  }

  function findStoredPurchaseRecovery(recordId, user = state.user) {
    if (!recordId) return null;
    return readStoredPurchaseRecoveries(user).find((item) => purchaseRecoveryRecordId(item) === recordId) || null;
  }

  function nextStoredPurchaseRecovery(user = state.user) {
    return readStoredPurchaseRecoveries(user).find((item) => !(
      state.inFlightPurchaseKey
      && !item.tradeNo
      && item.idempotencyKey === state.inFlightPurchaseKey
    )) || null;
  }

  function surfacePurchaseRecovery(recovery) {
    state.purchaseRecovery = recovery || null;
    if (state.purchaseRecovery) {
      state.purchasingPlanId = state.purchaseRecovery.planId ?? '__recovery__';
    } else if (!state.inFlightPurchaseKey) {
      state.purchasingPlanId = null;
    }
  }

  function persistPurchaseRecovery(recovery) {
    if (!recovery) {
      clearCurrentPurchaseRecovery();
      return;
    }
    state.purchaseRecovery = recovery;
    upsertStoredPurchaseRecovery(recovery);
  }

  // Resolve only the visible recovery; any other uncertain purchase of the same account surfaces next.
  function clearCurrentPurchaseRecovery() {
    const current = state.purchaseRecovery;
    if (current) deleteStoredPurchaseRecovery(purchaseRecoveryRecordId(current));
    surfacePurchaseRecovery(nextStoredPurchaseRecovery());
  }

  function recordPurchaseIntent(snapshot) {
    upsertStoredPurchaseRecovery({
      ...snapshot,
      tradeNo: null,
      tradeNos: null,
      checked: false,
      absenceConfirmedAt: null,
      lastEmptyCheckAt: null,
    });
  }

  function releasePurchaseIntent(idempotencyKey, user = state.user) {
    if (!idempotencyKey) return;
    const recordId = `key:${idempotencyKey}`;
    const stored = findStoredPurchaseRecovery(recordId, user);
    if (stored && !stored.tradeNo) deleteStoredPurchaseRecovery(recordId, user);
    const sameVisibleAccount = Boolean(state.user)
      && stableDistributorAccountId(user) === stableDistributorAccountId(state.user);
    if (sameVisibleAccount && state.purchaseRecovery && !state.purchaseRecovery.tradeNo && state.purchaseRecovery.idempotencyKey === idempotencyKey) {
      surfacePurchaseRecovery(nextStoredPurchaseRecovery());
      refreshPurchaseRecoveryView();
    }
  }

  function restorePurchaseRecovery(userKey, user = state.user) {
    if (!userKey || state.purchaseRecovery) return;
    const recovery = nextStoredPurchaseRecovery(user);
    if (recovery) surfacePurchaseRecovery(recovery);
  }

  function refreshPurchaseRecoveryView() {
    if (!state.active) return;
    if (currentPage() === '/plan') {
      renderPlanCatalog();
      return;
    }
    const banner = document.querySelector('#distributor-app .dist-purchase-recovery');
    if (state.purchaseRecovery && banner) banner.outerHTML = renderPurchaseRecovery();
    else if (!state.purchaseRecovery && banner) banner.remove();
    else if (state.purchaseRecovery && !banner) renderPage();
  }

  // Another tab of the same account recorded, updated, or resolved an uncertain purchase.
  function syncPurchaseRecoveryFromStorage() {
    if (!state.active || !state.user) return;
    const current = state.purchaseRecovery;
    if (current?.resuming || current?.checking) return;
    const currentRecordId = purchaseRecoveryRecordId(current);
    const stored = currentRecordId ? findStoredPurchaseRecovery(currentRecordId) : null;
    if (current && stored) {
      Object.assign(current, {
        tradeNo: stored.tradeNo,
        tradeNos: stored.tradeNos,
        count: stored.count,
        checked: stored.checked,
        requestedAt: stored.requestedAt,
        absenceConfirmedAt: stored.absenceConfirmedAt,
        lastEmptyCheckAt: stored.lastEmptyCheckAt,
      });
      if (!current.absenceConfirmedAt) current.abandonArmed = false;
      surfacePurchaseRecovery(current);
    } else {
      surfacePurchaseRecovery(nextStoredPurchaseRecovery());
    }
    refreshPurchaseRecoveryView();
  }

  function renderPurchaseRecovery() {
    const recovery = state.purchaseRecovery;
    if (!recovery) return '';
    const batchCount = Number(recovery.count || recovery.tradeNos?.length || 1);
    const recoveryMessage = recovery.tradeNo
      ? t('deliveryUnavailable')
      : batchCount > 1
        ? t('batchOrderUnconfirmed').replace('{count}', batchCount)
        : t('orderUnconfirmed');
    const orderDisplay = Array.isArray(recovery.tradeNos) && recovery.tradeNos.length > 1
      ? `${escapeHtml(recovery.tradeNos[0])} (+${recovery.tradeNos.length - 1})`
      : recovery.tradeNo
        ? escapeHtml(recovery.tradeNo)
        : '';
    const canResume = !recovery.tradeNo && Boolean(recovery.idempotencyKey && recovery.planId && recovery.period);
    const canAcknowledge = Boolean(recovery.tradeNo && recovery.checked);
    const canAbandon = !recovery.tradeNo && !recovery.resuming && Boolean(recovery.absenceConfirmedAt);
    const waitMinutes = Math.max(1, Math.ceil((Number(recovery.requestedAt || 0) + PURCHASE_ABANDON_SAFE_DELAY_MS - Date.now()) / 60000));
    const abandonHint = !recovery.tradeNo && !recovery.absenceConfirmedAt && recovery.lastEmptyCheckAt
      ? `<p class="dist-purchase-recovery-hint">${escapeHtml(t('abandonPurchaseTooEarly').replace('{minutes}', waitMinutes))}</p>`
      : '';
    const abandonControls = canAbandon
      ? recovery.abandonArmed
        ? `<p class="dist-purchase-recovery-confirm">${escapeHtml(t('abandonPurchaseConfirmText'))}</p><button class="danger" data-action="confirm-abandon-purchase">${t('confirmAbandonPurchase')}</button><button data-action="cancel-abandon-purchase">${t('cancelAbandonPurchase')}</button>`
        : `<button data-action="abandon-purchase">${t('abandonPurchase')}</button>`
      : '';
    return `<section class="dist-panel dist-purchase-recovery" role="alert"><p>${escapeHtml(recoveryMessage)}</p>
      ${orderDisplay ? `<p>${t('orderNo')}：${orderDisplay}</p>` : ''}${abandonHint}<div class="dist-modal-actions">
      ${recovery.tradeNo ? `<button data-action="retry-purchase-delivery">${t('retryDelivery')}</button>` : ''}
      ${canResume ? `<button class="primary" data-action="resume-purchase" ${recovery.resuming ? 'disabled' : ''}>${t(recovery.resuming ? 'loading' : 'resumePurchase')}</button>` : ''}
      <button data-action="check-purchase-orders" ${recovery.resuming ? 'disabled' : ''}>${t('checkPurchaseOrders')}</button>
      ${canAcknowledge ? `<button data-action="acknowledge-order-check">${t('purchaseChecked')}</button>` : ''}${abandonControls}</div></section>`;
  }

  async function checkPurchaseOrders() {
    const recovery = state.purchaseRecovery;
    if (!recovery || recovery.resuming) return;
    const isBatchRecovery = Array.isArray(recovery.tradeNos) && recovery.tradeNos.length > 1;
    state.orderSearch = isBatchRecovery ? '' : (recovery.tradeNo || '');
    state.orderSettlementStatus = '';
    state.orderFilters = { startDate: '', endDate: '', periods: [], minAmount: '', maxAmount: '' };
    state.orderPage = 1;
    recovery.checked = false;
    recovery.checking = true;
    persistPurchaseRecovery(recovery);
    if (currentPage() === '/order') await renderPage();
    else navigate('/order');
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
      if (!state.selectedQuantities[plan.id]) {
        state.selectedQuantities[plan.id] = 1;
      }
    });
    if (!state.plans.some((plan) => planMatchesFilter(plan, state.planFilter))) state.planFilter = 'all';
    renderPlanCatalog();
    loadNotices(renderContext);
  }

  async function loadNotices(renderContext) {
    try {
      const notices = [];
      for (let page = 1; page <= 100; page++) {
        const response = await api(`/user/notice/fetch?current=${page}`);
        if (!isCurrentRouteRender(renderContext)) return;
        if (!Array.isArray(response.data)) throw new Error('Invalid notice response');
        notices.push(...response.data);
        if (notices.length >= Number(response.total) || response.data.length === 0) break;
        if (page === 100) throw new Error('Notice pagination limit exceeded');
      }
      state.notices = notices;
      state.noticeIndex = 0;
      state.noticeError = false;
    } catch (error) {
      if (error?.isCancelled || !isCurrentRouteRender(renderContext)) return;
      state.notices = [];
      state.noticeError = true;
    }
    const slot = document.getElementById('dist-notice-slot');
    if (slot) slot.innerHTML = renderNoticeBar();
    startNoticeRotation();
  }

  function safeNoticeContent(html) {
    const document = new DOMParser().parseFromString(String(html || ''), 'text/html');
    const allowed = new Set(['P', 'BR', 'DIV', 'SPAN', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'UL', 'OL', 'LI', 'H1', 'H2', 'H3', 'H4', 'BLOCKQUOTE', 'PRE', 'CODE', 'A', 'IMG', 'HR', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD']);
    for (const element of Array.from(document.body.querySelectorAll('*'))) {
      if (!allowed.has(element.tagName)) { element.remove(); continue; }
      const link = element.getAttribute('href');
      const image = element.getAttribute('src');
      const alt = element.getAttribute('alt');
      for (const attribute of Array.from(element.attributes)) element.removeAttribute(attribute.name);
      if (element.tagName === 'A' && /^https?:\/\//i.test(link || '')) {
        element.setAttribute('href', link);
        element.setAttribute('target', '_blank');
        element.setAttribute('rel', 'noopener noreferrer');
      }
      if (element.tagName === 'IMG' && /^https?:\/\//i.test(image || '')) {
        element.setAttribute('src', image);
        element.setAttribute('alt', alt || '');
        element.setAttribute('referrerpolicy', 'no-referrer');
      }
    }
    return document.body.innerHTML;
  }

  function renderNoticeBar() {
    if (state.noticeError) return `<div class="dist-notices" role="status">${state.locale === 'zh-CN' ? '公告加载失败' : 'Announcements unavailable'}<button data-action="retry-notices">${t('retry')}</button></div>`;
    if (!state.notices.length) return '';
    const notice = state.notices[state.noticeIndex];
    const label = state.locale === 'zh-CN' ? '公告' : 'Announcements';
    return `<section class="dist-notices" aria-label="${label}"><span aria-hidden="true">◉</span><strong>${label}</strong><button class="dist-notice-title" data-action="open-notice">${escapeHtml(notice.title)}</button>${state.notices.length > 1 ? `<button data-action="next-notice" aria-label="${state.locale === 'zh-CN' ? '下一条公告' : 'Next announcement'}">${state.noticeIndex + 1}/${state.notices.length} ›</button>` : ''}</section>`;
  }

  function advanceNotice() {
    if (state.notices.length < 2) return;
    state.noticeIndex = (state.noticeIndex + 1) % state.notices.length;
    const bar = document.querySelector('.dist-notices');
    if (!bar) return;
    // Keep buttons mounted so keyboard focus survives a manual advance.
    const title = bar.querySelector('.dist-notice-title');
    title.textContent = state.notices[state.noticeIndex].title;
    bar.querySelector('[data-action="next-notice"]').textContent = `${state.noticeIndex + 1}/${state.notices.length} ›`;
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      title.animate([{ transform: 'translateY(8px)', opacity: 0 }, { transform: 'translateY(0)', opacity: 1 }], { duration: 250 });
    }
  }

  function startNoticeRotation() {
    clearInterval(state.noticeTimer);
    state.noticeTimer = null;
    if (currentPage() !== '/plan' || state.notices.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const currentBar = document.querySelector('.dist-notices');
    let hovered = currentBar?.matches(':hover') || false;
    if (currentBar) {
      currentBar.onpointerenter = () => { hovered = true; };
      currentBar.onpointerleave = () => { hovered = false; };
    }
    state.noticeTimer = setInterval(() => {
      const bar = document.querySelector('.dist-notices');
      if (!state.active || !bar) { clearInterval(state.noticeTimer); return; }
      if (!document.hidden && !state.modal && !hovered && !bar.contains(document.activeElement)) advanceNotice();
    }, 5000);
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
      const selectedQuantity = selectedPlanQuantity(plan.id);
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
      const batchPriceDetails = state.planPricesVisible
        ? `<span>${t('totalPrice')} ${money(selectedPrice * selectedQuantity)}</span><span>${t('totalSaved')} ${money(selectedSaving * selectedQuantity)}</span>`
        : '';
      const buyButtonLabel = soldOut
        ? t('soldOut')
        : selectedQuantity > 1
          ? `${batchPriceDetails}<strong>${t('orderActionBatch').replace('{count}', selectedQuantity)}</strong>`
          : `${actionPriceDetails}<strong>${t('orderAction')}</strong>`;
      const quickQuantityButtons = QUICK_ORDER_QUANTITIES.map((preset) => {
        const isPresetActive = selectedQuantity === preset;
        return `<button type="button" class="dist-qty-preset ${isPresetActive ? 'active' : ''}" data-plan-qty-preset="${preset}" data-plan-id="${plan.id}" aria-pressed="${isPresetActive}" ${soldOut || purchaseInFlight ? 'disabled' : ''}>${t('quickQuantity').replace('{count}', preset)}</button>`;
      }).join('');
      return `<article class="dist-plan-card ${isFeatured ? 'is-featured' : ''}" data-plan-card="${plan.id}">
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
          <div class="dist-plan-quantity-row">
            <span class="dist-plan-quantity-label">${t('quantity')}</span>
            <div class="dist-plan-quantity-controls">
              <div class="dist-qty-presets" role="group" aria-label="${t('quantity')}">${quickQuantityButtons}</div>
              <div class="dist-qty-stepper">
                <button type="button" class="dist-qty-step" data-plan-qty-step="-1" data-plan-id="${plan.id}" aria-label="${t('decreaseQuantity')}" ${soldOut || purchaseInFlight || selectedQuantity <= 1 ? 'disabled' : ''}>−</button>
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" class="dist-qty-input" data-plan-qty-input="${plan.id}" value="${selectedQuantity}" aria-label="${t('orderQuantityInput')}" ${soldOut || purchaseInFlight ? 'disabled' : ''}>
                <button type="button" class="dist-qty-step" data-plan-qty-step="1" data-plan-id="${plan.id}" aria-label="${t('increaseQuantity')}" ${soldOut || purchaseInFlight || selectedQuantity >= MAX_ORDER_QUANTITY ? 'disabled' : ''}>+</button>
              </div>
            </div>
          </div>
        </div>
        <div class="dist-plan-actions">
          <button type="button" data-buy="${plan.id}" data-name="${escapeHtml(plan.name)}" ${soldOut || purchaseInFlight ? 'disabled' : ''} ${isPurchasing ? 'aria-busy="true"' : ''}>${buyButtonLabel}</button>
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

  function extendPlanPriceTimerIfVisible() {
    if (!state.planPricesVisible) return;
    clearPlanPriceTimer();
    state.planPricesTimer = window.setTimeout(() => closePlanPrices(), 10000);
  }

  function syncPlanCardQuantityDom(planId, effectiveQuantity) {
    const plan = state.plans.find((item) => String(item.id) === String(planId));
    if (!plan) return;
    const prices = availablePeriods(plan);
    if (!prices.length) return;
    const selectedPeriod = state.selectedPeriods[plan.id] || prices[0][0];
    const selectedPrice = Number(plan[selectedPeriod]) || 0;
    const selectedSaving = periodSavings(plan, selectedPeriod);
    const soldOut = typeof plan.capacity_limit === 'string';
    const purchaseInFlight = state.purchasingPlanId !== null;
    document.querySelectorAll('[data-plan-qty-preset]').forEach((presetButton) => {
      if (presetButton.dataset.planId !== String(planId)) return;
      const active = Number(presetButton.dataset.planQtyPreset) === effectiveQuantity;
      presetButton.classList.toggle('active', active);
      presetButton.setAttribute('aria-pressed', String(active));
    });
    document.querySelectorAll('[data-plan-qty-step]').forEach((stepButton) => {
      if (stepButton.dataset.planId !== String(planId)) return;
      const step = Number(stepButton.dataset.planQtyStep);
      stepButton.disabled = Boolean(
        soldOut
        || purchaseInFlight
        || (step < 0 && effectiveQuantity <= 1)
        || (step > 0 && effectiveQuantity >= MAX_ORDER_QUANTITY)
      );
    });
    const buyButton = Array.from(document.querySelectorAll('[data-buy]'))
      .find((btn) => btn.dataset.buy === String(planId));
    if (!buyButton) return;
    if (soldOut) {
      buyButton.textContent = t('soldOut');
      return;
    }
    if (effectiveQuantity > 1) {
      const batchPriceDetails = state.planPricesVisible
        ? `<span>${t('totalPrice')} ${money(selectedPrice * effectiveQuantity)}</span><span>${t('totalSaved')} ${money(selectedSaving * effectiveQuantity)}</span>`
        : '';
      const nextBatchHtml = `${batchPriceDetails}<strong>${t('orderActionBatch').replace('{count}', effectiveQuantity)}</strong>`;
      if (buyButton.innerHTML !== nextBatchHtml) buyButton.innerHTML = nextBatchHtml;
    } else {
      const singlePriceDetails = state.planPricesVisible
        ? `<span>${t('original')} ${money(selectedPrice)}</span><span>${t('saved')} ${money(selectedSaving)}</span>`
        : '';
      const nextSingleHtml = `${singlePriceDetails}<strong>${t('orderAction')}</strong>`;
      if (buyButton.innerHTML !== nextSingleHtml) buyButton.innerHTML = nextSingleHtml;
    }
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
    if (state.purchaseRecovery) return;
    // Another tab of this account may already hold an uncertain purchase that this tab has not rendered.
    const pendingRecovery = nextStoredPurchaseRecovery();
    if (pendingRecovery) {
      surfacePurchaseRecovery(pendingRecovery);
      refreshPurchaseRecoveryView();
      return;
    }
    const quantity = selectedPlanQuantity(plan.id);
    const idempotencyKey = makeIdempotencyKey();
    const requestSnapshot = {
      idempotencyKey,
      planId: plan.id,
      planName: String(plan.name || ''),
      period,
      count: quantity,
      requestedAt: Date.now(),
    };
    const purchaseSession = syncSession();
    const operationId = ++state.purchaseOperationId;
    const isCurrentPurchase = () => state.active
      && state.purchaseOperationId === operationId
      && isCurrentSession(purchaseSession);
    state.selectedPeriods[plan.id] = period;
    state.selectedQuantities[plan.id] = quantity;
    state.purchasingPlanId = plan.id;
    // Write-ahead intent: a closed tab, crash, logout or lost response still leaves the original key recoverable.
    const purchaseUser = state.user;
    state.inFlightPurchaseKey = idempotencyKey;
    recordPurchaseIntent(requestSnapshot);
    if (button) button.disabled = true;
    let createdTradeNo = null;
    let createdTradeNos = null;
    try {
      if (quantity > 1) {
        const batchResult = dataOf(await api('/user/order/save', {
          method: 'POST',
          data: { plan_id: plan.id, period, quantity, idempotency_key: idempotencyKey },
        }));
        if (!isCurrentPurchase()) return;
        const tradeNos = Array.isArray(batchResult?.trade_nos)
          ? batchResult.trade_nos.map((item) => String(item || '').trim()).filter(Boolean)
          : [];
        if (tradeNos.length !== quantity) {
          const error = new Error(t('batchOrderUnconfirmed').replace('{count}', quantity));
          error.isUncertainWrite = true;
          throw error;
        }
        createdTradeNos = tradeNos;
        createdTradeNo = tradeNos[0];
        state.selectedQuantities[plan.id] = 1;
        invalidateOrderAnalytics();
        const opened = await openBatchDelivery(tradeNos, plan, period);
        if (opened) {
          releasePurchaseIntent(idempotencyKey, purchaseUser);
        } else if (isCurrentPurchase()) {
          persistPurchaseRecovery({ ...requestSnapshot, tradeNo: tradeNos[0], tradeNos, checked: false, checking: false, resuming: false });
        }
      } else {
        const tradeNo = dataOf(await api('/user/order/save', {
          method: 'POST', data: { plan_id: plan.id, idempotency_key: idempotencyKey, period },
        }));
        if (!isCurrentPurchase()) return;
        if (typeof tradeNo !== 'string' || !tradeNo.trim()) {
          const error = new Error(t('orderUnconfirmed'));
          error.isUncertainWrite = true;
          throw error;
        }
        createdTradeNo = tradeNo;
        state.selectedQuantities[plan.id] = 1;
        invalidateOrderAnalytics();
        const opened = await openDelivery(tradeNo);
        if (opened) {
          releasePurchaseIntent(idempotencyKey, purchaseUser);
        } else if (isCurrentPurchase()) {
          persistPurchaseRecovery({ ...requestSnapshot, tradeNo, tradeNos: null, checked: false, checking: false, resuming: false });
        }
      }
    } catch (error) {
      const definiteRejection = !createdTradeNo && !error?.isUncertainWrite
        && (!error?.isCancelled || Boolean(error?.isConfirmedAuthFailure));
      // A definite rejection (including a 401 that just ended this session) proves nothing was written.
      if (definiteRejection) releasePurchaseIntent(idempotencyKey, purchaseUser);
      if (!isCurrentPurchase()) return;
      if (createdTradeNo || error?.isUncertainWrite) {
        persistPurchaseRecovery(createdTradeNos
          ? { ...requestSnapshot, tradeNo: createdTradeNo, tradeNos: createdTradeNos, checked: false, checking: false, resuming: false }
          : { ...requestSnapshot, tradeNo: createdTradeNo, tradeNos: null, checked: false, checking: false, resuming: false });
        if (!document.hidden && currentPage() !== '/plan') renderPage();
      } else if (error?.isCancelled) {
        // A cancelled write may still have reached the server; keep the stored intent visible.
        const storedIntent = findStoredPurchaseRecovery(`key:${idempotencyKey}`);
        if (storedIntent) surfacePurchaseRecovery(storedIntent);
      } else {
        const capacityMatch = String(error?.rawMessage || error?.message || '').match(/剩余可售容量仅剩\s*(\d+)\s*个/);
        if (capacityMatch) {
          state.selectedQuantities[plan.id] = normalizeOrderQuantity(capacityMatch[1]);
        }
        toast(displayError(error), 'error');
      }
    } finally {
      if (state.inFlightPurchaseKey === idempotencyKey) state.inFlightPurchaseKey = null;
      if (isCurrentPurchase()) {
        if (!state.purchaseRecovery) state.purchasingPlanId = null;
        if (currentPage() === '/plan') renderPlanCatalog();
        if (button && button.isConnected && !state.purchaseRecovery) button.disabled = false;
      }
    }
  }

  async function resumeUncertainPurchase() {
    const recovery = state.purchaseRecovery;
    if (!recovery || recovery.tradeNo || recovery.resuming || !recovery.idempotencyKey || !recovery.planId || !recovery.period) return;
    const resumeSession = syncSession();
    const operationId = ++state.purchaseOperationId;
    const isCurrentResume = () => state.active
      && state.purchaseOperationId === operationId
      && isCurrentSession(resumeSession)
      && state.purchaseRecovery === recovery;
    const quantity = normalizeOrderQuantity(recovery.count || 1);
    recovery.resuming = true;
    // The replayed request can itself be lost; restart the safe window before it is sent.
    recovery.requestedAt = Date.now();
    recovery.absenceConfirmedAt = null;
    recovery.lastEmptyCheckAt = null;
    recovery.abandonArmed = false;
    persistPurchaseRecovery(recovery);
    const existingBanner = document.querySelector('#distributor-app .dist-purchase-recovery');
    if (existingBanner) existingBanner.outerHTML = renderPurchaseRecovery();
    let createdTradeNo = null;
    let createdTradeNos = null;
    try {
      if (quantity > 1) {
        const batchResult = dataOf(await api('/user/order/save', {
          method: 'POST',
          data: {
            plan_id: recovery.planId,
            period: recovery.period,
            quantity,
            idempotency_key: recovery.idempotencyKey,
          },
        }));
        if (!isCurrentResume()) return;
        const tradeNos = Array.isArray(batchResult?.trade_nos)
          ? batchResult.trade_nos.map((item) => String(item || '').trim()).filter(Boolean)
          : [];
        if (tradeNos.length !== quantity) {
          const error = new Error(t('batchOrderUnconfirmed').replace('{count}', quantity));
          error.isUncertainWrite = true;
          throw error;
        }
        createdTradeNos = tradeNos;
        createdTradeNo = tradeNos[0];
        state.selectedQuantities[recovery.planId] = 1;
        invalidateOrderAnalytics();
        const planObj = state.plans.find((item) => String(item.id) === String(recovery.planId))
          || { id: recovery.planId, name: recovery.planName };
        const opened = await openBatchDelivery(tradeNos, planObj, recovery.period);
        if (!isCurrentResume()) return;
        if (opened) {
          persistPurchaseRecovery(null);
        } else {
          recovery.tradeNo = createdTradeNo;
          recovery.tradeNos = createdTradeNos;
          recovery.checked = false;
          recovery.resuming = false;
          persistPurchaseRecovery(recovery);
        }
      } else {
        const tradeNo = dataOf(await api('/user/order/save', {
          method: 'POST',
          data: {
            plan_id: recovery.planId,
            idempotency_key: recovery.idempotencyKey,
            period: recovery.period,
          },
        }));
        if (!isCurrentResume()) return;
        if (typeof tradeNo !== 'string' || !tradeNo.trim()) {
          const error = new Error(t('orderUnconfirmed'));
          error.isUncertainWrite = true;
          throw error;
        }
        createdTradeNo = tradeNo;
        state.selectedQuantities[recovery.planId] = 1;
        invalidateOrderAnalytics();
        const opened = await openDelivery(tradeNo);
        if (!isCurrentResume()) return;
        if (opened) {
          persistPurchaseRecovery(null);
        } else {
          recovery.tradeNo = createdTradeNo;
          recovery.tradeNos = null;
          recovery.checked = false;
          recovery.resuming = false;
          persistPurchaseRecovery(recovery);
        }
      }
    } catch (error) {
      if (!isCurrentResume()) return;
      if (createdTradeNo) {
        recovery.tradeNo = createdTradeNo;
        recovery.tradeNos = createdTradeNos;
        recovery.checked = false;
        recovery.resuming = false;
        persistPurchaseRecovery(recovery);
      } else {
        const status = Number(error?.status || 0);
        const hasStructuredPayload = Boolean(
          error?.payload && typeof error.payload === 'object' && !Array.isArray(error.payload)
        );
        const deterministicRejection = !error?.isUncertainWrite
          && !error?.isNetworkError
          && !error?.isTimeout
          && !error?.isCancelled
          && hasStructuredPayload
          && !isConfirmedAuthFailure(status, error?.rawMessage || error?.message)
          && [400, 404, 422].includes(status);
        if (deterministicRejection) {
          const capacityMatch = String(error?.rawMessage || error?.message || '').match(/剩余可售容量仅剩\s*(\d+)\s*个/);
          if (capacityMatch && recovery.planId !== null) {
            state.selectedQuantities[recovery.planId] = normalizeOrderQuantity(capacityMatch[1]);
          }
          persistPurchaseRecovery(null);
          toast(displayError(error), 'error');
        } else if (!error?.isCancelled) {
          recovery.resuming = false;
          persistPurchaseRecovery(recovery);
          toastErrorIfCurrent(error, resumeSession);
        }
      }
    } finally {
      if (isCurrentResume()) {
        recovery.resuming = false;
      }
      if (state.active && isCurrentSession(resumeSession)) {
        if (currentPage() === '/plan') renderPlanCatalog();
        else if (currentPage() === '/order' && !state.purchaseRecovery) {
          try { await renderOrders(); } catch (_) { /* ignore */ }
        } else {
          const banner = document.querySelector('#distributor-app .dist-purchase-recovery');
          if (state.purchaseRecovery && banner) banner.outerHTML = renderPurchaseRecovery();
          else if (!state.purchaseRecovery && banner) banner.remove();
        }
      }
    }
  }

  async function repurchaseDelivery() {
    const repurchaseSession = syncSession();
    const originModal = state.modal;
    const isBatchOrigin = originModal?.type === 'batchDelivery';
    const delivery = originModal?.type === 'delivery'
      ? originModal.delivery
      : isBatchOrigin
        ? originModal.items?.[0]?.delivery
        : null;
    if (!delivery) return;
    if (!state.plans.length) {
      const plans = await fetchAvailablePlans();
      if (!state.active || !isCurrentSession(repurchaseSession)) return;
      state.plans = plans;
    }
    const plan = state.plans.find((item) => String(item.id) === String(delivery.plan_id));
    if (!plan || !availablePeriods(plan).some(([key]) => key === delivery.period) || Number(plan[delivery.period]) <= 0) {
      toast(t('planUnavailable'), 'error');
      return;
    }
    const previousQuantity = state.selectedQuantities[plan.id] ?? 1;
    if (isBatchOrigin) {
      state.selectedQuantities[plan.id] = normalizeOrderQuantity(originModal.totalCount);
    }
    await purchasePlan(plan, delivery.period, document.querySelector('[data-modal-action="buy-again"]'));
    if (state.purchaseRecovery && state.modal === originModal) {
      closeModal();
      const recoveryCount = Number(state.purchaseRecovery.count || state.purchaseRecovery.tradeNos?.length || 1);
      const recoveryMessage = state.purchaseRecovery.tradeNo
        ? t('deliveryUnavailable')
        : recoveryCount > 1
          ? t('batchOrderUnconfirmed').replace('{count}', recoveryCount)
          : t('orderUnconfirmed');
      toast(recoveryMessage, 'error');
      return;
    }
    if (isBatchOrigin && state.modal === originModal) {
      state.selectedQuantities[plan.id] = previousQuantity;
      if (currentPage() === '/plan') renderPlanCatalog();
    }
  }

  function toggleEntitlement(entitlementToggle, entitlementRow) {
    const willExpand = entitlementToggle.getAttribute('aria-expanded') !== 'true';
    entitlementToggle.setAttribute('aria-expanded', String(willExpand));
    entitlementToggle.setAttribute('aria-label', t('viewEntitlement'));
    entitlementToggle.textContent = t('entitlementAction');
    entitlementRow.hidden = true;
    entitlementToggle.closest?.('tr')?.classList.remove('is-entitlement-open');
    if (willExpand && typeof state !== 'undefined') {
      state.modal = { type: 'entitlement', trigger: entitlementToggle, content: entitlementRow.querySelector('td > div')?.innerHTML || '' };
      renderModal();
      document.querySelector('#dist-modal-root .dist-modal-x')?.focus({ preventScroll: true });
    }
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
      if (error?.isCancelled) return;
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
    const recovery = state.purchaseRecovery;
    const isBatchRecovery = Array.isArray(recovery?.tradeNos) && recovery.tradeNos.length > 1;
    const expectedRecoverySearch = isBatchRecovery ? '' : (recovery?.tradeNo || '');
    const checkingRecovery = recovery?.checking && !append && requestedPage === 1
      && (params.get('search') || '') === expectedRecoverySearch
      && Array.from(params.keys()).every((key) => ['page', 'per_page', 'search'].includes(key));
    const oldScrollY = window.scrollY;
    let payload;
    if (checkingRecovery && recovery.tradeNo && !isBatchRecovery) {
      const order = dataOf(await api(`/user/order/detail?${new URLSearchParams({ trade_no: recovery.tradeNo })}`));
      if (order?.trade_no !== recovery.tradeNo) throw new Error(t('deliveryUnavailable'));
      payload = { data: [order], current_page: 1, per_page: state.orderPerPage, total: 1, last_page: 1 };
    } else payload = await api(`/user/order/fetch?${params}`);
    if (!isCurrentRouteRender(renderContext)) return;
    if (!Array.isArray(payload?.data)) throw new Error(t('serviceUnavailable'));
    const fetchedOrders = Array.isArray(payload?.data) ? payload.data : [];
    if (checkingRecovery && state.purchaseRecovery === recovery) {
      recovery.checking = false;
      if (recovery.tradeNo) {
        recovery.checked = true;
      } else if (recovery.idempotencyKey) {
        const expectedCount = Math.min(MAX_ORDER_QUANTITY, Math.max(1, Number(recovery.count || 1)));
        let reconcilePayload = null;
        let reconcileSucceeded = false;
        if (fetchedOrders.length === 0) {
          // The unfiltered first page is empty, so this account has no orders at all.
          reconcilePayload = { data: [] };
          reconcileSucceeded = true;
        } else {
          const reconcileParams = new URLSearchParams({
            page: '1',
            per_page: String(expectedCount),
            idempotency_key: recovery.idempotencyKey,
          });
          try {
            reconcilePayload = await api(`/user/order/fetch?${reconcileParams}`);
            reconcileSucceeded = Array.isArray(reconcilePayload?.data);
          } catch (reconcileError) {
            if (reconcileError?.isCancelled || !isCurrentRouteRender(renderContext)) return;
            toastErrorIfCurrent(reconcileError);
          }
        }
        if (!isCurrentRouteRender(renderContext)) return;
        const matchedOrders = Array.isArray(reconcilePayload?.data)
          ? reconcilePayload.data
            .filter((item) => typeof item?.trade_no === 'string' && item.trade_no.trim() !== '')
            .sort((a, b) => Number(a.id || 0) - Number(b.id || 0))
          : [];
        if (matchedOrders.length === expectedCount && state.purchaseRecovery === recovery) {
          const matchedTradeNos = matchedOrders.map((item) => item.trade_no.trim());
          recovery.tradeNo = matchedTradeNos[0];
          recovery.tradeNos = matchedTradeNos.length > 1 ? matchedTradeNos : null;
          recovery.checked = true;
          recovery.absenceConfirmedAt = null;
          recovery.lastEmptyCheckAt = null;
          state.selectedQuantities[recovery.planId] = 1;
          invalidateOrderAnalytics();
        } else if (reconcileSucceeded && matchedOrders.length === 0 && state.purchaseRecovery === recovery && !recovery.tradeNo) {
          // Another tab may have replayed this key after this check started; its newer request time wins.
          const storedRecovery = findStoredPurchaseRecovery(purchaseRecoveryRecordId(recovery));
          if (storedRecovery && storedRecovery.requestedAt > Number(recovery.requestedAt || 0)) {
            recovery.requestedAt = storedRecovery.requestedAt;
          }
          const checkedAt = Date.now();
          recovery.lastEmptyCheckAt = checkedAt;
          recovery.absenceConfirmedAt = checkedAt - Number(recovery.requestedAt || 0) >= PURCHASE_ABANDON_SAFE_DELAY_MS
            ? checkedAt
            : null;
        }
      }
      if (state.purchaseRecovery === recovery) persistPurchaseRecovery(recovery);
    }
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
        <td class="dist-order-identity"><strong>${escapeHtml(subscriptionLabel(order))}</strong>${order.subscription_name ? `<small>${t('orderNo')}：${escapeHtml(order.trade_no)}</small>` : ''}</td>
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
    setContent(`<div class="dist-order-toolbar"><div class="dist-order-search"><button type="button" class="dist-order-columns-toggle" data-action="toggle-order-columns" aria-label="${t(state.orderFixedColumnsExpanded ? 'collapseOrderColumns' : 'expandOrderColumns')}">${t(state.orderFixedColumnsExpanded ? 'collapseOrderColumns' : 'expandOrderColumns')}</button><input id="dist-order-search" type="search" maxlength="512" value="${escapeHtml(state.orderSearch)}" placeholder="${t('orderSearchPlaceholder')}"><button data-action="search-orders">${t('search')}</button><button class="secondary" data-action="clear-order-search" ${state.orderSearch ? '' : 'disabled'}>${t('clear')}</button></div><button type="button" class="secondary dist-filter-toggle" data-action="toggle-order-filters" aria-expanded="${state.orderFiltersOpen}" aria-controls="dist-order-filters">${t(state.orderFiltersOpen ? 'hideFilters' : 'advancedFilters')}</button><button data-action="export-orders">${t('exportExcel')}</button></div>
      ${renderOrderFilters()}
      <!-- class="dist-orders-table" -->
       <div class="dist-mobile-order-controls"><button type="button" class="dist-order-columns-toggle dist-order-columns-toggle-mobile" data-action="toggle-order-columns" aria-label="${t(state.orderFixedColumnsExpanded ? 'collapseOrderColumns' : 'expandOrderColumns')}">${t(state.orderFixedColumnsExpanded ? 'collapseOrderColumns' : 'expandOrderColumns')}</button><button type="button" class="secondary dist-filter-toggle dist-filter-toggle-mobile" data-action="toggle-order-filters" aria-expanded="${state.orderFiltersOpen}" aria-controls="dist-order-filters">${t(state.orderFiltersOpen ? 'hideFilters' : 'advancedFilters')}</button><button type="button" class="dist-export-orders-mobile" data-action="export-orders">${t('exportExcel')}</button></div><div class="dist-table-wrap dist-order-list" tabindex="0" aria-label="${t('orders')}"><table class="dist-orders-table ${state.orderFixedColumnsExpanded ? '' : 'is-order-columns-collapsed'}"><colgroup><col class="dist-col-sequence"><col class="dist-col-actions"><col class="dist-col-order-no"><col class="dist-col-order-time"><col class="dist-col-order-type"><col class="dist-col-original"><col class="dist-col-customer"><col class="dist-col-plan"><col class="dist-col-period"><col class="dist-col-amount"><col class="dist-col-devices"><col class="dist-col-traffic"><col class="dist-col-settlement"><col class="dist-col-remark"></colgroup><thead><tr><th class="dist-sequence-header">${t('sequence')}</th><th>${t('actions')}</th><th>${t('subscriptionName')} / ${t('orderNo')}</th><th>${t('orderTime')}</th><th>${t('orderType')}</th><th>${t('originalOrder')}</th><th>${t('customerName')}</th><th>${t('plan')}</th><th>${t('period')}</th><th>${t('amount')}</th><th>${t('boundDevices')}</th><th>${t('usedTraffic')}</th><th>${t('settlement')}</th><th>${t('remark')}</th></tr></thead>
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
    const modalSession = syncSession();
    const modalOpId = ++state.modalOperationId;
    const params = new URLSearchParams({ id, language: state.locale, render: 'html' });
    const article = dataOf(await api(`/user/knowledge/fetch?${params}`));
    if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId) return;
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
    if (state.subscriptionResetOperations.has(tradeNo)) {
      await openSubscriptionQr(tradeNo);
      return true;
    }
    const modalSession = syncSession();
    const modalOpId = ++state.modalOperationId;
    const delivery = dataOf(await api(`/user/distributor/delivery?trade_no=${encodeURIComponent(tradeNo)}`));
    if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId) return false;
    const local_state = { modal: null };
    local_state.modal = await makeDeliveryModal(delivery);
    if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId) return false;
    state.modal = local_state.modal;
    renderModal();
    startPolling();
    return true;
  }

  function renderBatchDeliveryCardInner(item, index) {
    const delivery = item.delivery;
    const claimed = delivery.delivery_status === 1;
    const pending = delivery.delivery_status === 0;
    const issued = Boolean(delivery.config_issued_at);
    const connected = Boolean(delivery.connected_at);
    const connectionText = connected
      ? t('connectedThrough').replace('{node}', delivery.connected_node_name || '-')
      : issued ? t('waitingConnection') : '';
    const resetOp = state.subscriptionResetOperations?.get?.(delivery.trade_no);
    const canReset = Boolean(delivery.can_reset_subscription || resetOp);
    const goldTag = canReset ? `<span class="dist-reset-gold-tag">${escapeHtml(t('resetInOrdersTag'))}</span>` : '';
    let cardBody;
    if (resetOp) {
      cardBody = `<p ${state.resetModal === resetOp ? '' : 'role="status" '}class="dist-reset-hint dist-batch-reset-status">${t(resetOp.submitting ? 'resetSubscriptionPending' : resetOp.conflict ? 'resetSubscriptionConflict' : 'resetSubscriptionUncertain')}</p>
        <div class="dist-qr-actions-bar has-gold-tag is-reset-only"><div class="dist-modal-actions dist-image-actions dist-qr-action-row cols-1"><button type="button" class="dist-reset-trigger-btn" data-modal-action="reset-subscription" data-batch-index="${index}">${t(resetOp.conflict ? 'resetSubscriptionRefresh' : 'resetSubscriptionRetry')}</button></div>${goldTag}</div>`;
    } else if ((pending || item.resetComplete || item.qrExpanded) && item.imageUrl) {
      cardBody = `${item.resetComplete ? `<p role="status" class="dist-reset-hint dist-batch-reset-status">${t('resetSubscriptionSuccess')}</p>` : ''}
        <img class="dist-subscription-qr-preview" src="${item.imageUrl}" alt="${escapeHtml(subscriptionLabel(delivery))}">
        <div class="dist-qr-actions-bar ${canReset ? 'has-gold-tag' : ''}"><div class="dist-modal-actions dist-image-actions dist-qr-action-row ${canReset ? 'cols-3' : 'cols-2'}"><button type="button" data-modal-action="copy-batch-item-qr" data-batch-index="${index}">${item.copied ? t('copySuccess') : t('copyImage')}</button><button type="button" class="primary" data-modal-action="download-batch-item-qr" data-batch-index="${index}">${t('downloadImage')}</button>${canReset ? `<button type="button" class="dist-reset-trigger-btn" data-modal-action="reset-subscription" data-batch-index="${index}">${t('resetSubscription')}</button>` : ''}</div>${goldTag}</div>`;
    } else if (pending) {
      cardBody = `<div class="dist-loading"><span></span>${t('loading')}</div>`;
    } else {
      cardBody = `<p class="dist-batch-item-hint">${claimed && issued ? t('claimedOk') : claimed ? t('issuing') : t('closed')}</p><div class="dist-delivery-result">${claimed && issued ? '✓' : claimed ? '…' : '×'}<strong>${claimed && issued ? t('claimed') : claimed ? t('issuing') : t('closed')}</strong>${claimed && issued ? `<small class="dist-network-status">${escapeHtml(connectionText)}</small>` : ''}</div>
        ${canReset ? `<div class="dist-qr-actions-bar has-gold-tag"><div class="dist-modal-actions dist-image-actions dist-qr-action-row cols-2"><button type="button" data-modal-action="open-batch-subscription" data-batch-index="${index}">${t('viewSubscriptionQr')}</button><button type="button" class="dist-reset-trigger-btn" data-modal-action="reset-subscription" data-batch-index="${index}">${t('resetSubscription')}</button></div>${goldTag}</div>` : ''}`;
    }
    return `<div class="dist-batch-qr-card-head">
        <span class="dist-batch-qr-index">#${index + 1}</span>
        <div class="dist-delivery-identity"><strong>${escapeHtml(subscriptionLabel(delivery))}</strong>${delivery.subscription_name ? `<small>${t('orderNo')}：${escapeHtml(delivery.trade_no)}</small>` : ''}</div>
      </div>
      ${cardBody}`;
  }

  function syncBatchDeliveryProgressDom(batchModal, updatedIndices = []) {
    const root = document.getElementById('dist-modal-root');
    if (!root || state.modal !== batchModal) return false;
    const grid = root.querySelector('.dist-batch-qr-grid');
    if (!grid) return false;
    const cards = grid.children;
    if (!cards || !cards.length) return false;
    updatedIndices.forEach((idx) => {
      const item = batchModal.items[idx];
      const cardEl = cards[idx];
      if (!item || !cardEl) return;
      cardEl.classList.toggle('is-claimed', item.delivery.delivery_status === 1);
      cardEl.innerHTML = renderBatchDeliveryCardInner(item, idx);
    });
    const subtitleEl = root.querySelector('.dist-batch-qr-header p');
    if (subtitleEl) {
      const total = batchModal.items.length;
      subtitleEl.textContent = batchModal.imagesReady
        ? t('batchQrHint')
        : t('batchQrPreparing').replace('{done}', batchModal.renderedCount).replace('{total}', total);
    }
    const downloadBtn = root.querySelector('[data-modal-action="download-batch-qr-images"]');
    if (downloadBtn) {
      const downloadCount = batchModal.downloadableCount ?? 0;
      const displayCount = batchModal.imagesReady ? downloadCount : batchModal.items.length;
      const canDownloadAll = Boolean(batchModal.imagesReady && downloadCount > 0 && !batchModal.downloadingAll);
      downloadBtn.hidden = Boolean(batchModal.imagesReady && !downloadCount);
      downloadBtn.disabled = !canDownloadAll;
      downloadBtn.textContent = batchModal.downloadingAll
        ? t('downloadingBatchQrs').replace('{count}', displayCount)
        : t('downloadBatchQrs').replace('{count}', displayCount);
    }
    return true;
  }

  async function openBatchDelivery(tradeNos, plan = null, period = null) {
    const modalSession = syncSession();
    const modalOpId = ++state.modalOperationId;
    const initialCount = Math.min(tradeNos.length, 10);
    let deliveries;
    if (tradeNos.length > BATCH_QR_INLINE_THRESHOLD) {
      const [metaDeliveries, initialQrDeliveries] = await Promise.all([
        api('/user/distributor/delivery/batch', {
          method: 'POST',
          data: { trade_nos: tradeNos.join(','), include_qr: 0 },
        }).then(dataOf),
        api('/user/distributor/delivery/batch', {
          method: 'POST',
          data: { trade_nos: tradeNos.slice(0, initialCount).join(','), include_qr: 1 },
        }).then(dataOf),
      ]);
      if (Array.isArray(metaDeliveries)) {
        const qrByTradeNo = new Map((Array.isArray(initialQrDeliveries) ? initialQrDeliveries : []).map((d) => [d.trade_no, d.qr_code]));
        deliveries = metaDeliveries.map((d) => (qrByTradeNo.has(d.trade_no) ? { ...d, qr_code: qrByTradeNo.get(d.trade_no) } : d));
      }
    } else {
      deliveries = dataOf(await api('/user/distributor/delivery/batch', {
        method: 'POST',
        data: { trade_nos: tradeNos.join(','), include_qr: 1 },
      }));
    }
    if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId) return false;
    if (!Array.isArray(deliveries) || !deliveries.length) {
      throw new Error(t('deliveryUnavailable'));
    }
    const items = deliveries.map((delivery) => ({
      delivery,
      imageUrl: null,
      blob: null,
      copied: false,
    }));
    const resolvedPlanName = plan?.name || deliveries[0]?.plan_name || '批量订阅';
    const objectUrls = new Set();
    const batchModal = {
      type: 'batchDelivery',
      items,
      objectUrls,
      planId: plan?.id ?? deliveries[0]?.plan_id ?? null,
      planName: resolvedPlanName,
      period: period || deliveries[0]?.period || '',
      imagesReady: false,
      downloadableCount: 0,
      downloadingAll: false,
      renderedCount: 0,
      totalCount: items.length,
      assetGeneration: 0,
    };
    try {
      await Promise.all(items.slice(0, initialCount).map(async (item, index) => {
        if (state.subscriptionResetOperations.has(item.delivery.trade_no)) {
          item.delivery.qr_code = null;
        } else if (item.delivery.qr_code) {
          const png = await composeSubscriptionQrPng(item.delivery, { batchIndex: index + 1, preferObjectUrl: true, objectUrls });
          if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId) {
            if (png?.isObjectUrl) revokeTrackedObjectUrl(objectUrls, png.imageUrl);
            return;
          }
          item.imageUrl = png.imageUrl;
          item.blob = png.blob;
          item.delivery.qr_code = null;
        }
        batchModal.renderedCount += 1;
      }));
    } catch (error) {
      revokeBatchModalUrls(batchModal);
      throw error;
    }
    if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId) {
      revokeBatchModalUrls(batchModal);
      return false;
    }
    if (items.length <= initialCount) {
      syncBatchDownloadState(batchModal);
      revokeBatchModalUrls(state.modal);
      state.modal = batchModal;
      renderModal();
      startPolling();
      return true;
    }
    syncBatchDownloadState(batchModal);
    revokeBatchModalUrls(state.modal);
    state.modal = batchModal;
    renderModal();
    startPolling();
    const isStillActiveBatch = () => state.active
      && isCurrentSession(modalSession)
      && state.modalOperationId === modalOpId
      && state.modal === batchModal;
    (async () => {
      try {
        for (let fetchStart = initialCount; fetchStart < items.length; fetchStart += BATCH_QR_INLINE_THRESHOLD) {
          if (!isStillActiveBatch()) return;
          const fetchEnd = Math.min(items.length, fetchStart + BATCH_QR_INLINE_THRESHOLD);
          const missingQrTradeNos = [];
          for (let idx = fetchStart; idx < fetchEnd; idx += 1) {
            const entry = items[idx];
            if (
              entry.delivery.delivery_status === 0
              && !entry.delivery.qr_code
              && !entry.blob
              && !state.subscriptionResetOperations.has(entry.delivery.trade_no)
            ) {
              missingQrTradeNos.push(entry.delivery.trade_no);
            }
          }
          if (missingQrTradeNos.length) {
            const fetchedChunk = dataOf(await api('/user/distributor/delivery/batch', {
              method: 'POST',
              data: { trade_nos: missingQrTradeNos.join(','), include_qr: 1 },
            }));
            if (!isStillActiveBatch()) return;
            if (Array.isArray(fetchedChunk)) {
              const fetchedByTradeNo = new Map(fetchedChunk.map((d) => [d.trade_no, d]));
              for (let idx = fetchStart; idx < fetchEnd; idx += 1) {
                const target = items[idx];
                if (state.subscriptionResetOperations.has(target.delivery.trade_no)) continue;
                const fresh = fetchedByTradeNo.get(target.delivery.trade_no);
                if (!fresh) continue;
                if (fresh.credential_version && target.delivery.credential_version && fresh.credential_version !== target.delivery.credential_version) {
                  target.delivery = { ...target.delivery, ...fresh };
                  if (fresh.qr_code) target.needsQrRefresh = false;
                } else if (fresh.qr_code && !target.blob) {
                  target.delivery.qr_code = fresh.qr_code;
                }
              }
            }
          }
          for (let start = fetchStart; start < fetchEnd; start += 6) {
            await new Promise((resolve) => window.setTimeout(resolve, 0));
            if (!isStillActiveBatch()) return;
            const end = Math.min(fetchEnd, start + 6);
            const updatedIndices = [];
            await Promise.all(items.slice(start, end).map(async (item, offset) => {
              const itemIndex = start + offset;
              if (
                item.delivery.qr_code
                && !item.blob
                && !state.subscriptionResetOperations.has(item.delivery.trade_no)
              ) {
                const sourceDelivery = item.delivery;
                const startVersion = sourceDelivery.credential_version;
                const png = await composeSubscriptionQrPng(sourceDelivery, { batchIndex: itemIndex + 1, preferObjectUrl: true, objectUrls });
                if (!isStillActiveBatch()) {
                  if (png?.isObjectUrl) revokeTrackedObjectUrl(objectUrls, png.imageUrl);
                  if (state.modal !== batchModal) revokeBatchModalUrls(batchModal);
                  return;
                }
                if (
                  item.delivery !== sourceDelivery
                  || item.delivery.credential_version !== startVersion
                  || state.subscriptionResetOperations.has(item.delivery.trade_no)
                  || item.blob
                ) {
                  if (png?.isObjectUrl) revokeTrackedObjectUrl(objectUrls, png.imageUrl);
                } else {
                  item.imageUrl = png.imageUrl;
                  item.blob = png.blob;
                  item.delivery.qr_code = null;
                }
              }
              batchModal.renderedCount += 1;
              updatedIndices.push(itemIndex);
            }));
            if (!isStillActiveBatch()) {
              if (state.modal !== batchModal) revokeBatchModalUrls(batchModal);
              return;
            }
            syncBatchDownloadState(batchModal);
            if (!syncBatchDeliveryProgressDom(batchModal, updatedIndices)) renderModal();
          }
        }
        if (!isStillActiveBatch()) return;
        syncBatchDownloadState(batchModal);
        renderModal();
      } catch (error) {
        if (!isStillActiveBatch()) return;
        syncBatchDownloadState(batchModal);
        if (state.modal === batchModal) renderModal();
        toastErrorIfCurrent(error, modalSession);
      }
    })();
    return true;
  }

  async function openSubscriptionQr(tradeNo) {
    const modalSession = syncSession();
    const modalOpId = ++state.modalOperationId;
    stopPolling();
    const resetOperation = state.subscriptionResetOperations.get(tradeNo);
    if (resetOperation) {
      state.modal = {
        type: 'subscriptionQr',
        payload: { ...resetOperation.payload, qr_code: null },
        imageUrl: null,
        blob: null,
        copied: false,
      };
      state.resetModal = resetOperation;
      renderModal();
      focusActiveResetModalControl();
      return;
    }
    const payload = dataOf(await api(`/user/distributor/subscription-qr?trade_no=${encodeURIComponent(tradeNo)}`));
    if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId) return;
    const png = await composeSubscriptionQrPng(payload);
    if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId) return;
    state.modal = { type: 'subscriptionQr', payload, ...png, copied: false };
    renderModal();
    startPolling();
    focusOrderActionModal();
  }

  function resolveResetTargetPayload(triggerEl = null) {
    const source = state.modal;
    if (!source) return null;
    if (source.type === 'batchDelivery') {
      const batchIndexAttr = triggerEl?.closest?.('[data-batch-index]')?.dataset?.batchIndex;
      const index = Number(batchIndexAttr);
      return Number.isInteger(index) ? (source.items?.[index]?.delivery || null) : null;
    }
    if (['subscriptionQr', 'delivery'].includes(source.type)) {
      return source.payload || source.delivery || null;
    }
    return null;
  }

  function beginSubscriptionReset(triggerEl = null) {
    const payload = resolveResetTargetPayload(triggerEl);
    if (!payload?.trade_no) return;
    const existingOperation = state.subscriptionResetOperations.get(payload.trade_no);
    if (!existingOperation && (!payload.can_reset_subscription || !/^[a-f0-9]{64}$/.test(payload.credential_version || ''))) return;
    const batchIndexAttr = triggerEl?.closest?.('[data-batch-index]')?.dataset?.batchIndex;
    state.resetModalTrigger = triggerEl || document.activeElement;
    state.resetModalTarget = {
      tradeNo: payload.trade_no,
      batchIndex: batchIndexAttr !== undefined ? Number(batchIndexAttr) : null,
    };
    const operation = existingOperation || {
      type: 'subscriptionReset', payload: { ...payload }, resetConfirm: true,
      credentialVersion: payload.credential_version, idempotencyKey: makeIdempotencyKey(),
      submitting: false, uncertain: false, conflict: false,
    };
    state.resetModal = operation;
    renderResetModal();
    focusActiveResetModalControl();
  }

  function focusFirstMatching(selectors) {
    for (const selector of selectors) {
      const candidate = document.querySelector(selector);
      if (candidate && !candidate.disabled) {
        candidate.focus({ preventScroll: true });
        return true;
      }
    }
    return false;
  }

  function focusActiveResetModalControl() {
    focusFirstMatching([
      '#dist-reset-modal-root [data-modal-action="confirm-subscription-reset"]:not(:disabled)',
      '#dist-reset-modal-root [data-modal-action="refresh-subscription-reset"]:not(:disabled)',
      '#dist-reset-modal-root .dist-modal-x:not(:disabled)',
    ]);
  }

  function closeResetModal({ restoreFocus = true } = {}) {
    const trigger = state.resetModalTrigger;
    const targetInfo = state.resetModalTarget;
    state.resetModal = null;
    state.resetModalTrigger = null;
    state.resetModalTarget = null;
    renderResetModal();
    if (state.modal && state.subscriptionResetOperations?.size) renderModal();
    if (!state.modal) {
      document.documentElement.classList.remove('dist-modal-open');
      document.body.classList.remove('dist-modal-open');
    }
    if (restoreFocus) {
      window.requestAnimationFrame(() => {
        if (trigger?.isConnected && !trigger.disabled) {
          trigger.focus({ preventScroll: true });
          return;
        }
        const selectors = Number.isInteger(targetInfo?.batchIndex)
          ? [
            `#dist-modal-root [data-modal-action="reset-subscription"][data-batch-index="${targetInfo.batchIndex}"]:not(:disabled)`,
            '#dist-modal-root .dist-modal-x:not(:disabled)',
          ]
          : [
            '#dist-modal-root [data-modal-action="reset-subscription"]:not(:disabled)',
            '#dist-modal-root .dist-modal-x:not(:disabled)',
          ];
        focusFirstMatching(selectors);
      });
    }
  }

  function invalidateUnderlyingQrForTradeNo(tradeNo) {
    if (!state.modal || !tradeNo) return;
    if (['subscriptionQr', 'delivery'].includes(state.modal.type)) {
      const target = state.modal.payload || state.modal.delivery;
      if (target?.trade_no !== tradeNo) return;
      target.qr_code = null;
      state.modal.imageUrl = null;
      state.modal.blob = null;
      state.modal.copied = false;
      state.modal.resetComplete = false;
      renderModal();
      return;
    }
    if (state.modal.type === 'batchDelivery') {
      const batchModal = state.modal;
      let matched = false;
      batchModal.items.forEach((item) => {
        if (item.delivery.trade_no !== tradeNo) return;
        if (item.imageUrl && batchModal.objectUrls?.has(item.imageUrl)) {
          revokeTrackedObjectUrl(batchModal.objectUrls, item.imageUrl);
        }
        item.delivery.qr_code = null;
        item.imageUrl = null;
        item.blob = null;
        item.copied = false;
        item.resetComplete = false;
        matched = true;
      });
      if (matched) {
        batchModal.assetGeneration += 1;
        syncBatchDownloadState(batchModal);
        renderModal();
      }
    }
  }

  async function applyFreshQrToUnderlyingModal(tradeNo, payload, { resetComplete = false, isCurrent = () => true } = {}) {
    if (!state.modal) return false;
    if (state.modal.type === 'subscriptionQr' && state.modal.payload?.trade_no === tradeNo) {
      const png = await composeSubscriptionQrPng(payload);
      if (!isCurrent() || state.modal?.type !== 'subscriptionQr' || state.modal.payload?.trade_no !== tradeNo) return false;
      state.modal = { ...state.modal, payload, ...png, copied: false, resetComplete };
      return true;
    }
    if (state.modal.type === 'delivery' && state.modal.delivery?.trade_no === tradeNo) {
      const png = await composeSubscriptionQrPng(payload);
      if (!isCurrent() || state.modal?.type !== 'delivery' || state.modal.delivery?.trade_no !== tradeNo) return false;
      state.modal = {
        ...state.modal,
        delivery: { ...state.modal.delivery, ...payload },
        ...png,
        copied: false,
        resetComplete,
      };
      return true;
    }
    if (state.modal.type === 'batchDelivery') {
      const batchModal = state.modal;
      const itemIndex = batchModal.items.findIndex((entry) => entry.delivery.trade_no === tradeNo);
      if (itemIndex === -1) return false;
      const png = await composeSubscriptionQrPng(payload, { batchIndex: itemIndex + 1, preferObjectUrl: true, objectUrls: batchModal.objectUrls });
      if (!isCurrent() || state.modal !== batchModal) {
        if (png?.isObjectUrl) revokeTrackedObjectUrl(batchModal.objectUrls, png.imageUrl);
        if (state.modal !== batchModal) revokeBatchModalUrls(batchModal);
        return false;
      }
      const item = batchModal.items[itemIndex];
      if (item.imageUrl && batchModal.objectUrls?.has(item.imageUrl)) {
        revokeTrackedObjectUrl(batchModal.objectUrls, item.imageUrl);
      }
      item.delivery = { ...item.delivery, ...payload, qr_code: null };
      item.imageUrl = png.imageUrl;
      item.blob = png.blob;
      item.copied = false;
      item.needsQrRefresh = false;
      item.resetComplete = resetComplete;
      item.qrExpanded = true;
      batchModal.assetGeneration += 1;
      syncBatchDownloadState(batchModal);
      return true;
    }
    return false;
  }

  async function submitSubscriptionReset() {
    const operation = state.resetModal;
    if (!operation?.resetConfirm || operation.submitting || operation.conflict) return;
    const resetSession = syncSession();
    const tradeNo = operation.payload.trade_no;
    state.subscriptionResetOperations.set(tradeNo, operation);
    const isCurrentReset = () => state.active && isCurrentSession(resetSession)
      && state.subscriptionResetOperations.get(tradeNo) === operation;
    operation.submitting = true;
    operation.payload.qr_code = null;
    invalidateUnderlyingQrForTradeNo(tradeNo);
    renderResetModal();
    let responseTimer;
    try {
      const payload = dataOf(await Promise.race([
        api('/user/distributor/subscription-reset', {
          method: 'POST', data: {
            trade_no: tradeNo, credential_version: operation.credentialVersion,
            idempotency_key: operation.idempotencyKey, confirm: true,
          },
        }),
        new Promise((_, reject) => {
          responseTimer = window.setTimeout(() => reject(makeTimeoutError()), 20000);
        }),
      ]));
      window.clearTimeout(responseTimer);
      if (!isCurrentReset()) return;
      if (!payload?.qr_code || !/^[a-f0-9]{64}$/.test(payload.credential_version || '')) {
        const error = new Error(t('writeUnconfirmed'));
        error.isUncertainWrite = true;
        throw error;
      }
      const updatedUnderlying = await applyFreshQrToUnderlyingModal(tradeNo, payload, { resetComplete: true, isCurrent: isCurrentReset });
      if (!isCurrentReset()) return;
      state.subscriptionResetOperations.delete(tradeNo);
      const shouldRestoreFocus = state.resetModal === operation;
      if (shouldRestoreFocus) {
        closeResetModal({ restoreFocus: true });
      }
      if (updatedUnderlying) {
        renderModal();
        startPolling();
      }
      if (currentPage() === '/order') {
        try { await renderOrders(); } catch (error) { toastErrorIfCurrent(error, resetSession); }
      }
    } catch (error) {
      if (!isCurrentReset() || error?.isCancelled) return;
      const status = Number(error?.status);
      // A later rejection cannot disprove an earlier unconfirmed commit.
      operation.conflict = status === 409 || (!operation.uncertain && status >= 400 && status < 500);
      operation.uncertain = !operation.conflict;
      if (state.modal) renderModal();
      toastErrorIfCurrent(error, resetSession);
    } finally {
      window.clearTimeout(responseTimer);
      if (isCurrentReset()) {
        operation.submitting = false;
        if (state.modal) renderModal();
        if (state.resetModal === operation) {
          renderResetModal();
          focusActiveResetModalControl();
        }
      }
    }
  }

  async function refreshSubscriptionReset() {
    const operation = state.resetModal;
    if (!operation?.resetConfirm || operation.submitting) return;
    const resetSession = syncSession();
    const tradeNo = operation.payload.trade_no;
    operation.submitting = true;
    renderResetModal();
    try {
      const payload = dataOf(await api(`/user/distributor/subscription-qr?trade_no=${encodeURIComponent(tradeNo)}`));
      const isCurrentRefresh = () => state.active && isCurrentSession(resetSession) && state.resetModal === operation;
      if (!isCurrentRefresh()) return;
      const updatedUnderlying = await applyFreshQrToUnderlyingModal(tradeNo, payload, { resetComplete: false, isCurrent: isCurrentRefresh });
      if (!isCurrentRefresh()) return;
      state.subscriptionResetOperations.delete(tradeNo);
      closeResetModal({ restoreFocus: true });
      if (updatedUnderlying) {
        renderModal();
        startPolling();
      }
    } catch (error) { toastErrorIfCurrent(error, resetSession); }
    finally {
      operation.submitting = false;
      if (state.resetModal === operation) {
        renderResetModal();
        focusActiveResetModalControl();
      }
    }
  }

  async function openBatchCardQr(index) {
    const batchModal = state.modal;
    if (batchModal?.type !== 'batchDelivery') return;
    const item = batchModal.items?.[index];
    if (!item?.delivery?.trade_no) return;
    const modalSession = syncSession();
    const modalOpId = state.modalOperationId;
    const payload = dataOf(await api(`/user/distributor/subscription-qr?trade_no=${encodeURIComponent(item.delivery.trade_no)}`));
    if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId || state.modal !== batchModal) return;
    const png = await composeSubscriptionQrPng(payload, { batchIndex: index + 1, preferObjectUrl: true, objectUrls: batchModal.objectUrls });
    if (!state.active || !isCurrentSession(modalSession) || state.modalOperationId !== modalOpId || state.modal !== batchModal) {
      if (png?.isObjectUrl) revokeTrackedObjectUrl(batchModal.objectUrls, png.imageUrl);
      return;
    }
    if (item.imageUrl && batchModal.objectUrls?.has(item.imageUrl)) {
      revokeTrackedObjectUrl(batchModal.objectUrls, item.imageUrl);
    }
    item.delivery = { ...item.delivery, ...payload, qr_code: null };
    item.imageUrl = png.imageUrl;
    item.blob = png.blob;
    item.copied = false;
    item.qrExpanded = true;
    syncBatchDownloadState(batchModal);
    renderModal();
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
    state.modalOperationId += 1;
    let operation = state.renewalOperations.get(tradeNo);
    if (!operation) {
      operation = {
        type: 'renewal',
        order,
        periods,
        period: periods.some(([key]) => key === order.period) ? order.period : periods[0][0],
        idempotencyKey: makeIdempotencyKey(),
        submitting: false,
        uncertain: false,
        result: null,
      };
      state.renewalOperations.set(tradeNo, operation);
    }
    state.modal = operation;
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

  function renderResetModal() {
    let resetRoot = document.getElementById('dist-reset-modal-root');
    if (!resetRoot) {
      resetRoot = document.createElement('div');
      resetRoot.id = 'dist-reset-modal-root';
      document.body.appendChild(resetRoot);
    }
    if (state.dark) resetRoot.classList.add?.('is-dark');
    else resetRoot.classList.remove?.('is-dark');
    if (!state.resetModal) {
      resetRoot.innerHTML = '';
      resetRoot.classList.remove('open');
      if (!state.modal) {
        document.documentElement.classList.remove('dist-modal-open');
        document.body.classList.remove('dist-modal-open');
      }
      return;
    }
    const modal = state.resetModal;
    resetRoot.classList.add('open');
    document.documentElement.classList.add('dist-modal-open');
    document.body.classList.add('dist-modal-open');
    resetRoot.innerHTML = `<div class="dist-reset-modal-backdrop"><section class="dist-modal dist-subscription-reset-modal" role="dialog" aria-modal="true" aria-labelledby="dist-subscription-reset-title"><button type="button" class="dist-modal-x" data-modal-action="close-reset-confirm" aria-label="${t('closePopup')}" ${modal.submitting ? 'disabled' : ''}>×</button><h2 id="dist-subscription-reset-title">${t('resetSubscription')}</h2>
      <p class="dist-delivery-identity"><strong>${escapeHtml(subscriptionLabel(modal.payload))}</strong><small>${t('customerName')}：${escapeHtml(modal.payload.customer_name || '-')}</small></p>
      <p>${escapeHtml(t('resetSubscriptionDevices').replace('{count}', (modal.payload.hwid_devices || []).length))}</p>
      <p class="dist-reset-hint">${t('resetSubscriptionHint')}</p>
      ${modal.submitting || modal.uncertain || modal.conflict ? `<p role="status">${t(modal.submitting ? 'resetSubscriptionPending' : modal.conflict ? 'resetSubscriptionConflict' : 'resetSubscriptionUncertain')}</p>` : ''}
      <div class="dist-modal-actions"><button type="button" data-modal-action="close-reset-confirm" ${modal.submitting ? 'disabled' : ''}>${t('closePopup')}</button><button type="button" class="primary" data-modal-action="${modal.conflict ? 'refresh-subscription-reset' : 'confirm-subscription-reset'}" ${modal.submitting ? 'disabled' : ''}>${t(modal.submitting ? 'loading' : modal.conflict ? 'resetSubscriptionRefresh' : modal.uncertain ? 'resetSubscriptionRetry' : 'resetSubscriptionConfirm')}</button></div>
    </section></div>`;
  }

  function renderModal() {
    let root = document.getElementById('dist-modal-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'dist-modal-root';
      document.body.appendChild(root);
    }
    if (state.dark) root.classList.add?.('is-dark');
    else root.classList.remove?.('is-dark');
    if (typeof renderResetModal === 'function' && (state.resetModal || document.getElementById?.('dist-reset-modal-root')?.id === 'dist-reset-modal-root')) {
      renderResetModal();
    }
    if (!state.modal) {
      root.innerHTML = '';
      root.classList.remove('open');
      if (!state.resetModal) {
        document.documentElement.classList.remove('dist-modal-open');
        document.body.classList.remove('dist-modal-open');
      }
      stopPolling();
      return;
    }
    root.classList.add('open');
    document.documentElement.classList.add('dist-modal-open');
    document.body.classList.add('dist-modal-open');
    if (state.modal.type === 'notice') {
      const notice = state.modal.notice;
      root.innerHTML = `<div class="dist-modal-backdrop"><section class="dist-modal" role="dialog" aria-modal="true" aria-labelledby="dist-notice-heading"><button class="dist-modal-x" data-modal-action="cancel" aria-label="${t('cancel')}">×</button><h2 id="dist-notice-heading">${escapeHtml(notice.title)}</h2><div class="dist-notice-body" tabindex="0">${safeNoticeContent(notice.content)}</div></section></div>`;
      root.querySelector('[data-modal-action="cancel"]').focus();
      return;
    }
    if (state.modal.type === 'knowledge') {
      const article = state.modal.article;
      root.innerHTML = `<div class="dist-modal-backdrop"><article class="dist-modal dist-knowledge-modal"><button class="dist-modal-x" data-modal-action="cancel">×</button><h2>${escapeHtml(article.title)}</h2><button type="button" class="dist-knowledge-share" data-copy="${escapeHtml(article.share_url || `${window.location.origin}/guide/${article.id}`)}">复制分享链接</button><div class="dist-knowledge-updated">${t('lastUpdated')}：${formatTime(article.updated_at)}</div><div class="dist-knowledge-body">${article.body || ''}</div></article></div>`;
      return;
    }
    if (state.modal.type === 'subscriptionQr') {
      const modal = state.modal;
      const resetOp = state.subscriptionResetOperations?.get?.(modal.payload?.trade_no);
      const canReset = Boolean(modal.payload?.can_reset_subscription || resetOp);
      let qrBodyHtml;
      if (resetOp) {
        qrBodyHtml = `<p ${state.resetModal === resetOp ? '' : 'role="status" '}class="dist-reset-hint">${t(resetOp.submitting ? 'resetSubscriptionPending' : resetOp.conflict ? 'resetSubscriptionConflict' : 'resetSubscriptionUncertain')}</p>
          <div class="dist-qr-actions-bar is-reset-only"><div class="dist-modal-actions dist-image-actions dist-qr-action-row cols-1"><button type="button" class="dist-reset-trigger-btn" data-modal-action="reset-subscription">${t(resetOp.conflict ? 'resetSubscriptionRefresh' : 'resetSubscriptionRetry')}</button></div></div>`;
      } else if (modal.imageUrl) {
        qrBodyHtml = `${modal.resetComplete ? `<p role="status" class="dist-reset-hint">${t('resetSubscriptionSuccess')}</p>` : ''}
          <img class="dist-subscription-qr-preview" src="${modal.imageUrl}" alt="${escapeHtml(t('viewSubscriptionQr'))}">
          <div class="dist-qr-actions-bar"><div class="dist-modal-actions dist-image-actions dist-qr-action-row ${canReset ? 'cols-3' : 'cols-2'}"><button type="button" data-modal-action="copy-subscription-qr">${modal.copied ? t('copySuccess') : t('copyImage')}</button><button type="button" class="primary" data-modal-action="download-subscription-qr">${t('downloadImage')}</button>${canReset ? `<button type="button" class="dist-reset-trigger-btn" data-modal-action="reset-subscription">${t('resetSubscription')}</button>` : ''}</div></div>`;
      } else {
        qrBodyHtml = `<p role="status">${t('loading')}</p>`;
      }
      root.innerHTML = `<div class="dist-modal-backdrop dist-order-action-backdrop"><section class="dist-modal dist-subscription-qr-modal" role="dialog" aria-modal="true" aria-labelledby="dist-subscription-qr-title"><button class="dist-modal-x" data-modal-action="cancel" aria-label="${t('cancel')}">×</button><h2 id="dist-subscription-qr-title">${t('viewSubscriptionQr')}</h2>
        ${qrBodyHtml}
      </section></div>`;
      return;
    }
    if (state.modal.type === 'renewal') {
      const modal = state.modal;
      if (modal.result) {
        root.innerHTML = `<div class="dist-modal-backdrop dist-order-action-backdrop"><section class="dist-modal dist-renewal-modal" role="dialog" aria-modal="true" aria-labelledby="dist-renewal-result-title"><button class="dist-modal-x" data-modal-action="renew-done" aria-label="${t('closePopup')}">×</button><h2 id="dist-renewal-result-title">${t('renewSuccess')}</h2>
          <p class="dist-renewal-hint">${t('renewHint')}</p><dl>
          ${modal.order.subscription_name ? `<div><dt>${t('subscriptionName')}</dt><dd>${escapeHtml(modal.order.subscription_name)}</dd></div>` : ''}
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
        ${modal.order.subscription_name ? `<div><dt>${t('subscriptionName')}</dt><dd>${escapeHtml(modal.order.subscription_name)}</dd></div>` : ''}
        <div><dt>${t('customerName')}</dt><dd>${escapeHtml(modal.order.customer_name || '-')}</dd></div>
        <div><dt>${t('plan')}</dt><dd>${escapeHtml(modal.order.plan?.name || '-')}</dd></div>
        <div><dt>${t('renewCurrentExpiry')}</dt><dd>${formatTime(modal.order.subscription_entitlement?.expired_at)}</dd></div>
        <div><dt>${t('amount')}</dt><dd>${money(selectedPrice)}</dd></div></dl>
        ${modal.uncertain ? `<p role="alert">${t('renewUnconfirmed')}</p>` : ''}
        <label class="dist-renewal-period">${t('renewPeriod')}<select id="dist-renewal-period" ${modal.submitting || modal.uncertain ? 'disabled' : ''}>${options}</select></label>
        <div class="dist-modal-actions"><button data-modal-action="cancel" ${modal.submitting ? 'disabled' : ''}>${t('cancel')}</button><button class="primary" data-modal-action="confirm-renewal" ${modal.submitting ? 'disabled' : ''}>${modal.submitting ? t('loading') : modal.uncertain ? t('retry') : t('renewConfirm')}</button></div></section></div>`;
      return;
    }
    if (state.modal.type === 'entitlement') {
      root.innerHTML = `<div class="dist-modal-backdrop dist-order-action-backdrop"><section class="dist-modal dist-entitlement-modal" role="dialog" aria-modal="true" aria-label="${t('entitlement')}"><button class="dist-modal-x" data-modal-action="cancel" aria-label="${t('closePopup')}">×</button>${state.modal.content}</section></div>`;
      return;
    }
    if (state.modal.type === 'batchDelivery') {
      const batchModal = state.modal;
      const previousScrollTop = root.querySelector('.dist-batch-qr-body')?.scrollTop || 0;
      const total = batchModal.items.length;
      const pendingCount = batchModal.items.filter(({ delivery }) => delivery.delivery_status === 0).length;
      const claimedCount = batchModal.items.filter(({ delivery }) => delivery.delivery_status === 1).length;
      const cardsHtml = batchModal.items.map((item, index) => {
        const delivery = item.delivery;
        const claimed = delivery.delivery_status === 1;
        return `<article class="dist-batch-qr-card ${claimed ? 'is-claimed' : ''}" data-batch-trade-no="${escapeHtml(delivery.trade_no)}">${renderBatchDeliveryCardInner(item, index)}</article>`;
      }).join('');
      if (typeof syncBatchDownloadState === 'function') syncBatchDownloadState(batchModal);
      const subtitle = batchModal.imagesReady
        ? t('batchQrHint')
        : t('batchQrPreparing').replace('{done}', batchModal.renderedCount).replace('{total}', total);
      const displayCount = batchModal.imagesReady ? batchModal.downloadableCount : total;
      const batchDownloadButton = batchModal.imagesReady && !batchModal.downloadableCount
        ? ''
        : `<button type="button" class="primary dist-batch-download-btn" data-modal-action="download-batch-qr-images" ${batchModal.imagesReady && batchModal.downloadableCount > 0 && !batchModal.downloadingAll ? '' : 'disabled'}>${escapeHtml(t(batchModal.downloadingAll ? 'downloadingBatchQrs' : 'downloadBatchQrs').replace('{count}', displayCount))}</button>`;
      root.innerHTML = `<div class="dist-modal-backdrop"><section class="dist-modal dist-batch-qr-modal" role="dialog" aria-modal="true" aria-labelledby="dist-batch-qr-heading">
        <button class="dist-modal-x" data-modal-action="close-delivery" aria-label="${t('closePopup')}">×</button>
        <header class="dist-batch-qr-header">
          <div>
            <h2 id="dist-batch-qr-heading">${escapeHtml(t('batchQrTitle').replace('{total}', total).replace('{pending}', pendingCount).replace('{claimed}', claimedCount))}</h2>
            <p>${escapeHtml(subtitle)}</p>
          </div>
          ${batchDownloadButton}
        </header>
        <div class="dist-batch-qr-body"><div class="dist-batch-qr-grid">${cardsHtml}</div></div>
        <footer class="dist-modal-actions dist-batch-qr-footer"><button type="button" data-modal-action="buy-again">${t('buyAgain')}</button><button type="button" class="primary" data-modal-action="close-delivery">${t('closePopup')}</button></footer>
      </section></div>`;
      const nextBody = root.querySelector('.dist-batch-qr-body');
      if (nextBody && previousScrollTop > 0) nextBody.scrollTop = previousScrollTop;
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
    const resetOp = state.subscriptionResetOperations?.get?.(delivery.trade_no);
    const canReset = Boolean(delivery.can_reset_subscription || resetOp);
    const goldTag = canReset ? `<span class="dist-reset-gold-tag">${escapeHtml(t('resetInOrdersTag'))}</span>` : '';
    const showQrImage = !resetOp && ((pending && modal.imageUrl) || (modal.resetComplete && modal.imageUrl));
    let deliveryBodyHtml;
    if (resetOp) {
      deliveryBodyHtml = `<p ${state.resetModal === resetOp ? '' : 'role="status" '}class="dist-reset-hint">${t(resetOp.submitting ? 'resetSubscriptionPending' : resetOp.conflict ? 'resetSubscriptionConflict' : 'resetSubscriptionUncertain')}</p>
        <div class="dist-qr-actions-bar has-gold-tag is-reset-only"><div class="dist-modal-actions dist-image-actions dist-qr-action-row cols-1"><button type="button" class="dist-reset-trigger-btn" data-modal-action="reset-subscription">${t(resetOp.conflict ? 'resetSubscriptionRefresh' : 'resetSubscriptionRetry')}</button></div>${goldTag}</div>`;
    } else if (showQrImage) {
      deliveryBodyHtml = `${modal.resetComplete ? `<p role="status" class="dist-reset-hint">${t('resetSubscriptionSuccess')}</p>` : ''}
        <img class="dist-subscription-qr-preview" src="${modal.imageUrl}" alt="${escapeHtml(t('viewSubscriptionQr'))}">
        <div class="dist-qr-actions-bar ${canReset ? 'has-gold-tag' : ''}"><div class="dist-modal-actions dist-image-actions dist-qr-action-row ${canReset ? 'cols-3' : 'cols-2'}"><button type="button" data-modal-action="copy-subscription-qr">${modal.copied ? t('copySuccess') : t('copyImage')}</button><button type="button" class="primary" data-modal-action="download-subscription-qr">${t('downloadImage')}</button>${canReset ? `<button type="button" class="dist-reset-trigger-btn" data-modal-action="reset-subscription">${t('resetSubscription')}</button>` : ''}</div>${goldTag}</div>`;
    } else {
      deliveryBodyHtml = `<div class="dist-delivery-result">${claimed && issued ? '✓' : claimed ? '…' : '×'}<strong>${claimed && issued ? t('claimed') : claimed ? t('issuing') : t('closed')}</strong>${claimed && issued ? `<small class="dist-network-status">${escapeHtml(connectionText)}</small>` : ''}</div>
        ${canReset ? `<div class="dist-qr-actions-bar has-gold-tag is-reset-only"><div class="dist-modal-actions dist-image-actions dist-qr-action-row cols-1"><button type="button" class="dist-reset-trigger-btn" data-modal-action="reset-subscription">${t('resetSubscription')}</button></div>${goldTag}</div>` : ''}`;
    }
    root.innerHTML = `<div class="dist-modal-backdrop"><section class="dist-modal dist-qr-modal"><button class="dist-modal-x" data-modal-action="close-delivery">×</button><h2>${t('qrTitle')}</h2>
      ${delivery.subscription_name ? `<p class="dist-delivery-identity"><strong>${escapeHtml(delivery.subscription_name)}</strong><small>${t('orderNo')}：${escapeHtml(delivery.trade_no)}</small></p>` : ''}
      <p>${pending || modal.resetComplete ? t('qrHint') : claimed && issued ? t('claimedOk') : claimed ? t('issuing') : t('closed')}</p>
      ${deliveryBodyHtml}
      <div class="dist-modal-actions"><button data-modal-action="buy-again">${t('buyAgain')}</button><button class="primary" data-modal-action="close-delivery">${t('closePopup')}</button></div>
      </section></div>`;
  }

  function closeModal() {
    state.modalOperationId += 1;
    abortScopedRequests('modal', 'cancelled');
    revokeBatchModalUrls(state.modal);
    const entitlementTrigger = state.modal?.type === 'entitlement' ? state.modal.trigger : null;
    const modalTrigger = state.modalTrigger;
    state.resetModal = null;
    state.resetModalTrigger = null;
    state.resetModalTarget = null;
    state.modal = null;
    state.modalTrigger = null;
    renderModal();
    if (entitlementTrigger?.isConnected) {
      entitlementTrigger.setAttribute('aria-expanded', 'false');
      entitlementTrigger.setAttribute('aria-label', t('viewEntitlement'));
      window.requestAnimationFrame(() => entitlementTrigger.focus({ preventScroll: true }));
    }
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
    let pollInFlight = false;
    state.poller = setInterval(async () => {
      if (pollInFlight || document.hidden || !state.active || !state.modal || state.resetModal) return;
      pollInFlight = true;
      try {
        if (state.modal.type === 'batchDelivery') {
          const batchModal = state.modal;
          const activeTradeNos = batchModal.items
            .filter((entry) => entry.delivery.delivery_status !== 2 || entry.resetComplete || entry.qrExpanded)
            .map(({ delivery }) => delivery.trade_no);
          if (!activeTradeNos.length) return;
          const pollSession = syncSession();
          const pollModalOpId = state.modalOperationId;
          try {
            const updates = dataOf(await api('/user/distributor/delivery/batch', {
              method: 'POST',
              data: { trade_nos: activeTradeNos.join(','), include_qr: 0 },
            }));
            if (!state.active || !isCurrentSession(pollSession) || state.modalOperationId !== pollModalOpId || state.modal !== batchModal || !Array.isArray(updates)) return;
            const updatesByTradeNo = new Map(updates.map((item) => [item.trade_no, item]));
            let anyChanged = false;
            const isCurrentBatch = () => state.active && isCurrentSession(pollSession)
              && state.modalOperationId === pollModalOpId && state.modal === batchModal;
            // Invalidate every affected asset before awaiting image or network work.
            for (const item of batchModal.items) {
              if (state.subscriptionResetOperations.has(item.delivery.trade_no)) continue;
              const updated = updatesByTradeNo.get(item.delivery.trade_no);
              if (updated?.credential_version && updated.credential_version !== item.delivery.credential_version) {
                const newlyClaimed = item.delivery.delivery_status === 0 && updated.delivery_status === 1;
                if (item.imageUrl && batchModal.objectUrls?.has(item.imageUrl)) {
                  try { URL.revokeObjectURL(item.imageUrl); } catch (_) { /* ignore */ }
                  batchModal.objectUrls.delete(item.imageUrl);
                }
                item.delivery = { ...item.delivery, ...updated, qr_code: null };
                item.imageUrl = null;
                item.blob = null;
                item.copied = false;
                if (newlyClaimed) {
                  item.resetComplete = false;
                  item.qrExpanded = false;
                }
                item.needsQrRefresh = true;
                batchModal.assetGeneration += 1;
                anyChanged = true;
              }
            }
            if (anyChanged) {
              syncBatchDownloadState(batchModal);
              renderModal();
            }
            await Promise.allSettled(batchModal.items.map(async (item, itemIndex) => {
              if (state.subscriptionResetOperations.has(item.delivery.trade_no)) return;
              const updated = updatesByTradeNo.get(item.delivery.trade_no);
              if (!updated) return;
              const devicesChanged = JSON.stringify(updated.hwid_devices || []) !== JSON.stringify(item.delivery.hwid_devices || []);
              if (
                updated.delivery_status !== item.delivery.delivery_status
                || updated.config_issued_at !== item.delivery.config_issued_at
                || updated.connected_at !== item.delivery.connected_at
                || devicesChanged
                || item.needsQrRefresh
                || (item.delivery.delivery_status === 0 && !item.blob)
              ) {
                const newlyClaimed = item.delivery.delivery_status === 0 && updated.delivery_status === 1;
                item.delivery = {
                  ...item.delivery,
                  ...updated,
                  qr_code: updated.qr_code || item.delivery.qr_code,
                };
                if (newlyClaimed) {
                  item.resetComplete = false;
                  item.qrExpanded = false;
                }
                if (item.needsQrRefresh || (devicesChanged && (item.qrExpanded || item.resetComplete) && !item.delivery.qr_code)) {
                  const refreshed = dataOf(await api(`/user/distributor/subscription-qr?trade_no=${encodeURIComponent(item.delivery.trade_no)}`));
                  if (!isCurrentBatch()) return;
                  item.delivery = { ...item.delivery, ...refreshed };
                }
                if ((devicesChanged || item.needsQrRefresh || !item.blob) && item.delivery.qr_code) {
                  const sourceDelivery = item.delivery;
                  const png = await composeSubscriptionQrPng(sourceDelivery, { batchIndex: itemIndex + 1, preferObjectUrl: true, objectUrls: batchModal.objectUrls });
                  if (!isCurrentBatch() || item.delivery !== sourceDelivery) {
                    if (png?.isObjectUrl) revokeTrackedObjectUrl(batchModal.objectUrls, png.imageUrl);
                    if (state.modal !== batchModal) revokeBatchModalUrls(batchModal);
                    return;
                  }
                  if (item.imageUrl && batchModal.objectUrls?.has(item.imageUrl)) {
                    revokeTrackedObjectUrl(batchModal.objectUrls, item.imageUrl);
                  }
                  item.imageUrl = png.imageUrl;
                  item.blob = png.blob;
                  item.delivery.qr_code = null;
                  item.copied = false;
                  item.needsQrRefresh = false;
                }
                anyChanged = true;
              }
            }));
            if (!isCurrentBatch()) return;
            if (anyChanged) {
              syncBatchDownloadState(batchModal);
              renderModal();
            }
          } catch (_) { /* keep the current batch QR grid visible during transient failures */ }
          return;
        }
        if (!['delivery', 'subscriptionQr'].includes(state.modal.type)) return;
        const singleModal = state.modal;
        const currentDelivery = singleModal.delivery || singleModal.payload;
        if (!currentDelivery?.trade_no || state.subscriptionResetOperations.has(currentDelivery.trade_no)) return;
        if (currentDelivery.delivery_status === 2 && singleModal.type === 'delivery' && !singleModal.resetComplete) return;
        const pollSession = syncSession();
        const pollModalOpId = state.modalOperationId;
        try {
          const currentTradeNo = currentDelivery.trade_no;
          const updated = dataOf(await api(`/user/distributor/delivery?trade_no=${encodeURIComponent(currentTradeNo)}`));
          const isCurrentSingle = () => state.active && isCurrentSession(pollSession) && state.modalOperationId === pollModalOpId && state.modal === singleModal;
          if (!isCurrentSingle()) return;
          const devicesChanged = JSON.stringify(updated.hwid_devices || []) !== JSON.stringify(currentDelivery.hwid_devices || []);
          const versionChanged = updated.credential_version && updated.credential_version !== currentDelivery.credential_version;
          if (versionChanged) {
            currentDelivery.qr_code = null;
            singleModal.imageUrl = null;
            singleModal.blob = null;
            singleModal.copied = false;
            renderModal();
          }
          if (versionChanged || updated.delivery_status !== currentDelivery.delivery_status || updated.config_issued_at !== currentDelivery.config_issued_at || updated.connected_at !== currentDelivery.connected_at || devicesChanged || (!singleModal.imageUrl && (singleModal.type === 'subscriptionQr' || updated.delivery_status === 0))) {
            const newlyClaimed = currentDelivery.delivery_status === 0 && updated.delivery_status === 1;
            const nextResetComplete = Boolean(singleModal.resetComplete && !newlyClaimed);
            let nextModal;
            if (singleModal.type === 'subscriptionQr') {
              const payload = updated.qr_code ? updated : dataOf(await api(`/user/distributor/subscription-qr?trade_no=${encodeURIComponent(currentTradeNo)}`));
              if (!isCurrentSingle()) return;
              const png = await composeSubscriptionQrPng(payload);
              nextModal = { type: 'subscriptionQr', payload, ...png, copied: false, resetComplete: singleModal.resetComplete };
            } else if (nextResetComplete && updated.delivery_status !== 0) {
              const payload = dataOf(await api(`/user/distributor/subscription-qr?trade_no=${encodeURIComponent(currentTradeNo)}`));
              if (!isCurrentSingle()) return;
              const png = await composeSubscriptionQrPng(payload);
              nextModal = { type: 'delivery', delivery: { ...updated, ...payload }, ...png, copied: false, resetComplete: true };
            } else {
              nextModal = { ...(await makeDeliveryModal(updated)), resetComplete: nextResetComplete };
            }
            if (!isCurrentSingle()) return;
            state.modal = nextModal;
            renderModal();
          }
        } catch (_) { /* keep the current QR visible during transient failures */ }
      } finally {
        pollInFlight = false;
      }
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
    if (nav) {
      if (nav.dataset.nav === '/order' && state.purchaseRecovery) await checkPurchaseOrders();
      else navigate(nav.dataset.nav);
      return;
    }
    const filter = target.closest('[data-plan-filter]');
    if (filter) {
      state.planFilter = filter.dataset.planFilter;
      renderPlanCatalog();
      return;
    }
    const period = target.closest('[data-plan-period]');
    if (period) {
      state.selectedPeriods[period.dataset.planId] = period.dataset.planPeriod;
      extendPlanPriceTimerIfVisible();
      renderPlanCatalog();
      return;
    }
    const qtyPreset = target.closest('[data-plan-qty-preset]');
    if (qtyPreset && !qtyPreset.disabled) {
      const planId = qtyPreset.dataset.planId;
      const preset = normalizeOrderQuantity(qtyPreset.dataset.planQtyPreset);
      const current = selectedPlanQuantity(planId);
      state.selectedQuantities[planId] = current === preset ? 1 : preset;
      extendPlanPriceTimerIfVisible();
      renderPlanCatalog();
      return;
    }
    const qtyStep = target.closest('[data-plan-qty-step]');
    if (qtyStep && !qtyStep.disabled) {
      const planId = qtyStep.dataset.planId;
      const delta = Number(qtyStep.dataset.planQtyStep) || 0;
      state.selectedQuantities[planId] = normalizeOrderQuantity(selectedPlanQuantity(planId) + delta);
      extendPlanPriceTimerIfVisible();
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
      try { await renderOrders(); window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { toastErrorIfCurrent(e); }
      return;
    }
    const jumpPageButton = target.closest('[data-action="jump-order-page"]');
    if (jumpPageButton) {
      const pageInput = document.getElementById('dist-order-page-input');
      const requestedPage = Number.parseInt(pageInput?.value || '', 10);
      if (!Number.isInteger(requestedPage)) { pageInput?.focus(); return; }
      state.orderPage = Math.min(Math.max(requestedPage, 1), state.orderLastPage);
      try { await renderOrders(); window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { toastErrorIfCurrent(e); }
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
        try { await refreshOrderAnalytics(false, true); } catch (e) { toastErrorIfCurrent(e); }
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
        toastErrorIfCurrent(e);
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
    const noticeAction = target.closest('[data-action="open-notice"], [data-action="next-notice"], [data-action="retry-notices"]');
    if (noticeAction) {
      if (noticeAction.dataset.action === 'next-notice') advanceNotice();
      else if (noticeAction.dataset.action === 'retry-notices') await loadNotices(beginRouteRender('/plan'));
      else if (state.notices[state.noticeIndex]) {
        state.modalOperationId += 1;
        state.modalTrigger = noticeAction;
        state.modal = { type: 'notice', notice: state.notices[state.noticeIndex] };
        renderModal();
      }
      return;
    }
    const knowledge = target.closest('[data-knowledge-id]');
    if (knowledge) { try { await openKnowledge(knowledge.dataset.knowledgeId); } catch (e) { toastErrorIfCurrent(e); } return; }
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
      const tokenToLogout = authToken();
      // Logging out never discards an uncertain purchase; the same account restores it after login.
      deactivateDistributorView();
      if (window.__xboardAuthSession?.logoutSession) {
        window.__xboardAuthSession.logoutSession(tokenToLogout);
      } else {
        localStorage.removeItem(TOKEN_KEY);
      }
      syncSession();
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
      if (state.resetModal) renderResetModal();
    } else if (action === 'theme') {
      state.dark = !state.dark;
      localStorage.setItem('xboard_distributor_dark', state.dark ? '1' : '0');
      await renderPage();
      if (state.modal) renderModal();
      if (state.resetModal) renderResetModal();
    } else if (action === 'retry') {
      await renderPage();
    } else if (action === 'check-purchase-orders') {
      await checkPurchaseOrders();
    } else if (action === 'resume-purchase') {
      await resumeUncertainPurchase();
    } else if (action === 'acknowledge-order-check') {
      if (!state.purchaseRecovery?.tradeNo || !state.purchaseRecovery?.checked) return;
      persistPurchaseRecovery(null);
      state.purchaseOperationId += 1;
      if (currentPage() === '/plan') renderPlanCatalog();
      else navigate('/plan');
    } else if (action === 'abandon-purchase') {
      const recovery = state.purchaseRecovery;
      if (!recovery || recovery.tradeNo || recovery.resuming || !recovery.absenceConfirmedAt) return;
      recovery.abandonArmed = true;
      refreshPurchaseRecoveryView();
    } else if (action === 'cancel-abandon-purchase') {
      if (!state.purchaseRecovery) return;
      state.purchaseRecovery.abandonArmed = false;
      refreshPurchaseRecoveryView();
    } else if (action === 'confirm-abandon-purchase') {
      const recovery = state.purchaseRecovery;
      if (!recovery || recovery.tradeNo || recovery.resuming || !recovery.abandonArmed) return;
      // Re-validate against shared storage: another tab may have replayed or resolved this purchase.
      const storedRecovery = findStoredPurchaseRecovery(purchaseRecoveryRecordId(recovery));
      const safelyAbsent = Boolean(
        storedRecovery
        && !storedRecovery.tradeNo
        && storedRecovery.absenceConfirmedAt
        && storedRecovery.requestedAt === Number(recovery.requestedAt || 0)
        && storedRecovery.absenceConfirmedAt - storedRecovery.requestedAt >= PURCHASE_ABANDON_SAFE_DELAY_MS
      );
      if (!safelyAbsent) {
        recovery.abandonArmed = false;
        toast(t('abandonPurchaseStale'), 'error');
        syncPurchaseRecoveryFromStorage();
        return;
      }
      persistPurchaseRecovery(null);
      state.purchaseOperationId += 1;
      toast(t('purchaseAbandoned'));
      if (currentPage() === '/plan') renderPlanCatalog();
      else navigate('/plan');
    } else if (action === 'retry-purchase-delivery') {
      const recovery = state.purchaseRecovery;
      const session = syncSession();
      if (!recovery?.tradeNo) return;
      try {
        const opened = Array.isArray(recovery.tradeNos) && recovery.tradeNos.length > 1
          ? await openBatchDelivery(recovery.tradeNos)
          : await openDelivery(recovery.tradeNo);
        if (!opened || !isCurrentSession(session) || state.purchaseRecovery !== recovery) return;
        persistPurchaseRecovery(null);
        if (currentPage() === '/plan') renderPlanCatalog();
        else if (state.purchaseRecovery) refreshPurchaseRecoveryView();
        else document.querySelector('#distributor-app .dist-purchase-recovery')?.remove();
      } catch (error) { toastErrorIfCurrent(error, session); }
    } else if (action === 'export-orders') {
      try { await exportOrders(target.closest('[data-action]')); } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'search-orders') {
      state.orderSearch = document.getElementById('dist-order-search')?.value.trim() || '';
      state.orderPage = 1;
      try { await renderOrders(); } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'clear-order-search') {
      state.orderSearch = '';
      state.orderPage = 1;
      try { await renderOrders(); } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'toggle-order-columns') {
      state.orderFixedColumnsExpanded = !state.orderFixedColumnsExpanded;
      document.querySelector('.dist-orders-table')?.classList.toggle('is-order-columns-collapsed', !state.orderFixedColumnsExpanded);
      const label = t(state.orderFixedColumnsExpanded ? 'collapseOrderColumns' : 'expandOrderColumns');
      document.querySelectorAll('[data-action="toggle-order-columns"]').forEach((toggle) => { toggle.textContent = label; toggle.setAttribute('aria-label', label); });
    } else if (action === 'toggle-order-filters') {
      state.orderFiltersOpen = !state.orderFiltersOpen;
      const panel = document.getElementById('dist-order-filters');
      if (panel) panel.hidden = !state.orderFiltersOpen;
      const label = t(state.orderFiltersOpen ? 'hideFilters' : 'advancedFilters');
      document.querySelectorAll('[data-action="toggle-order-filters"]').forEach((button) => {
        button.setAttribute('aria-expanded', String(state.orderFiltersOpen));
        button.textContent = label;
      });
    } else if (action === 'apply-order-filters') {
      const { settlementStatus, ...filters } = collectOrderFilters();
      state.orderSettlementStatus = settlementStatus;
      state.orderFilters = filters;
      state.orderPage = 1;
      try { await renderOrders(); } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'reset-order-filters') {
      state.orderSettlementStatus = '';
      state.orderFilters = { startDate: '', endDate: '', periods: [], minAmount: '', maxAmount: '' };
      state.orderPage = 1;
      try { await renderOrders(); } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'apply-summary-range') {
      try {
        state.orderSummaryRange = checkedDateRange('dist-summary-start', 'dist-summary-end');
        await refreshOrderAnalytics(true, false);
      } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'apply-trend-range') {
      try {
        state.orderTrendPreset = 'custom';
        state.orderTrendRange = checkedDateRange('dist-trend-start', 'dist-trend-end');
        await refreshOrderAnalytics(false, true);
      } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'load-more-orders') {
      try { await renderOrders({ append: true }); } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'search-knowledge') {
      state.knowledgeSearch = document.getElementById('dist-knowledge-search')?.value.trim() || '';
      try { await renderKnowledge(); } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'clear-knowledge-search') {
      state.knowledgeSearch = '';
      try { await renderKnowledge(); } catch (e) { toastErrorIfCurrent(e); }
    } else if (action === 'generate-code') {
      const actionSession = syncSession();
      try {
        await api('/user/invite/save');
        if (!state.active || !isCurrentSession(actionSession)) return;
        toast(t('success'));
        await renderInvite();
      } catch (e) { toastErrorIfCurrent(e, actionSession); }
    } else if (action === 'transfer') {
      const yuan = Number(document.getElementById('transfer-amount')?.value || 0);
      if (yuan <= 0) return;
      const actionSession = syncSession();
      try {
        await api('/user/transfer', { method: 'POST', data: { transfer_amount: Math.round(yuan * 100) } });
        if (!state.active || !isCurrentSession(actionSession)) return;
        toast(t('success'));
        await renderInvite();
      } catch (e) { toastErrorIfCurrent(e, actionSession); }
    }
  }

  async function handleModalAction(target) {
    const actionEl = target.closest('[data-modal-action]');
    const action = actionEl?.dataset.modalAction;
    if (!action) return;
    if (action === 'reset-subscription') beginSubscriptionReset(actionEl);
    else if (action === 'close-reset-confirm') closeResetModal();
    else if (action === 'confirm-subscription-reset') await submitSubscriptionReset();
    else if (action === 'refresh-subscription-reset') await refreshSubscriptionReset();
    else if (action === 'open-batch-subscription') {
      const index = Number(actionEl.dataset.batchIndex);
      try { await openBatchCardQr(index); } catch (error) { toastErrorIfCurrent(error); }
    }
    else if (action === 'close-delivery') closeModal();
    else if (action === 'renew-done') {
      if (state.modal?.type === 'renewal' && state.modal.result) state.renewalOperations.delete(state.modal.order.trade_no);
      closeModal();
    }
    else if (action === 'confirm-renewal') {
      if (!state.modal || state.modal.type !== 'renewal' || state.modal.submitting || state.modal.result) return;
      const renewalModal = state.modal;
      const renewalSession = syncSession();
      const isCurrentRenewal = () => state.active
        && isCurrentSession(renewalSession)
        && state.renewalOperations.get(renewalModal.order.trade_no) === renewalModal;
      renewalModal.submitting = true;
      renderModal();
      try {
        const result = dataOf(await api('/user/order/renew', {
          method: 'POST',
          data: {
            trade_no: renewalModal.order.trade_no,
            period: renewalModal.period,
            idempotency_key: renewalModal.idempotencyKey,
          },
        }));
        if (!isCurrentRenewal()) return;
        if (typeof result?.trade_no !== 'string' || !result.trade_no.trim()) {
          const error = new Error(t('writeUnconfirmed'));
          error.isUncertainWrite = true;
          throw error;
        }
        renewalModal.result = result;
        renewalModal.uncertain = false;
        invalidateOrderAnalytics();
      } catch (error) {
        if (!isCurrentRenewal() || error?.isCancelled) return;
        // A later rejection cannot rule out an earlier unconfirmed commit.
        renewalModal.uncertain = renewalModal.uncertain || Boolean(error?.isUncertainWrite || error?.isNetworkError
          || !error?.status || Number(error.status) >= 500 || Number(error.status) === 409);
        if (!renewalModal.uncertain) renewalModal.idempotencyKey = makeIdempotencyKey();
        toastErrorIfCurrent(error, renewalSession);
      } finally {
        if (isCurrentRenewal()) {
          renewalModal.submitting = false;
          if (state.modal === renewalModal) renderModal();
        }
      }
      if (isCurrentRenewal() && renewalModal.result && currentPage() === '/order') {
        try { await renderOrders(); } catch (error) { toastErrorIfCurrent(error, renewalSession); }
      }
    }
    else if (action === 'buy-again') {
      const actionSession = syncSession();
      try { await repurchaseDelivery(); } catch (error) { toastErrorIfCurrent(error, actionSession); }
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
      link.download = `订阅二维码-${subscriptionLabel(state.modal.payload || state.modal.delivery)}.png`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 0);
    }
    else if (action === 'download-batch-qr-images') {
      if (state.modal?.type !== 'batchDelivery') return;
      await downloadBatchDeliveryImages(state.modal);
    }
    else if (action === 'copy-batch-item-qr') {
      const index = Number(target.closest('[data-modal-action]')?.dataset.batchIndex);
      const activeBatchModal = state.modal?.type === 'batchDelivery' ? state.modal : null;
      const item = activeBatchModal?.items?.[index] || null;
      if (!item?.blob) return;
      if (!navigator.clipboard?.write || typeof ClipboardItem === 'undefined' || !window.isSecureContext) {
        toast(t('copyImageUnsupported'), 'error');
        return;
      }
      try {
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': item.blob })]);
        if (state.modal !== activeBatchModal || activeBatchModal.items?.[index] !== item) return;
        item.copied = true;
        if (!syncBatchDeliveryProgressDom(activeBatchModal, [index])) renderModal();
        window.setTimeout(() => {
          if (state.modal !== activeBatchModal || activeBatchModal.items?.[index] !== item) return;
          item.copied = false;
          if (!syncBatchDeliveryProgressDom(activeBatchModal, [index])) renderModal();
        }, 1800);
      } catch (_) { toast(t('copyImageUnsupported'), 'error'); }
    }
    else if (action === 'download-batch-item-qr') {
      const index = Number(target.closest('[data-modal-action]')?.dataset.batchIndex);
      const batchModal = state.modal?.type === 'batchDelivery' ? state.modal : null;
      const item = batchModal?.items?.[index] || null;
      if (!item?.blob) return;
      const padWidth = Math.max(2, String(batchModal.items.length).length);
      const prefix = String(index + 1).padStart(padWidth, '0');
      triggerBlobDownload(item.blob, `订阅二维码-#${prefix}-${sanitizeFilenamePart(subscriptionLabel(item.delivery))}.png`);
    }
    else if (action === 'cancel') closeModal();
  }

  function userIdentityKey(user, sessionSnapshot) {
    const idPart = user?.id ?? user?.email ?? user?.uuid ?? '';
    return `${sessionSnapshot?.token || ''}:${String(idPart)}`;
  }

  function deactivateDistributorView() {
    state.active = false;
    state.user = null;
    state.activeSessionEpoch = null;
    state.activeUserKey = null;
    state.renderGeneration += 1;
    state.analyticsRequestToken += 1;
    resetAccountScopedState();
    closePlanPrices(false);
    clearInterval(state.noticeTimer);
    state.noticeTimer = null;
    stopPolling();
    if (state.resetModal) closeResetModal({ restoreFocus: false });
    if (state.modal) closeModal();
    document.getElementById('dist-reset-modal-root')?.remove();
    abortScopedRequests('route', 'cancelled');
    abortScopedRequests('modal', 'cancelled');
    document.getElementById('distributor-app')?.remove();
    document.documentElement.classList.remove('distributor-mode');
    const normalApp = document.getElementById('app');
    if (normalApp) normalApp.style.display = '';
    startDetectorLoop();
  }

  function activate(user) {
    const currentSession = syncSession();
    if (!user?.is_distributor) {
      if (state.active) deactivateDistributorView();
      return;
    }
    const nextUserKey = userIdentityKey(user, currentSession);
    const sameSessionAndUser = state.active
      && state.activeSessionEpoch === currentSession.localEpoch
      && state.activeUserKey === nextUserKey;
    if (state.active && !sameSessionAndUser) {
      deactivateDistributorView();
    }
    state.active = true;
    state.user = user;
    state.activeSessionEpoch = currentSession.localEpoch;
    state.activeUserKey = nextUserKey;
    state.decidedToken = currentSession.token;
    restorePurchaseRecovery(nextUserKey, user);
    stopDetectorLoop();
    window.__xboardAuthSession?.hideRecoveryPrompt?.();
    const normalApp = document.getElementById('app');
    if (normalApp) normalApp.style.display = 'none';
    let root = document.getElementById('distributor-app');
    if (!root) {
      root = document.createElement('div');
      root.id = 'distributor-app';
      document.body.appendChild(root);
    }
    document.documentElement.classList.add('distributor-mode');
    if (sameSessionAndUser && root.children.length > 0 && !root.querySelector('.dist-loading, .dist-error')) {
      const accountEl = root.querySelector('.dist-account');
      if (accountEl) accountEl.textContent = `● ${distributorAccountLabel(state.user)}`;
      return;
    }
    if (!['/plan', '/overview', '/order', '/invite', '/knowledge', '/clients'].includes(currentPage())) navigate('/plan');
    renderPage();
  }

  function detectDistributor(options = {}) {
    const sessionSnapshot = syncSession();
    if (!sessionSnapshot.token) {
      if (state.active) deactivateDistributorView();
      return Promise.resolve();
    }
    if (state.active && state.activeSessionEpoch === sessionSnapshot.localEpoch && !options.force) {
      return Promise.resolve();
    }
    if (!options.force && state.decidedToken === sessionSnapshot.token) {
      return Promise.resolve();
    }
    if (!options.force && state.nextDetectAt > Date.now()) {
      return Promise.resolve();
    }
    if (
      state.detectInFlight &&
      state.detectInFlight.localEpoch === sessionSnapshot.localEpoch &&
      state.detectInFlight.token === sessionSnapshot.token
    ) {
      return state.detectInFlight.promise;
    }
    const confirmedUser = !options.force && window.__xboardAuthSession?.getConfirmedUser?.(sessionSnapshot);
    if (isValidUserIdentity(confirmedUser)) {
      state.decidedToken = sessionSnapshot.token;
      if (confirmedUser.is_distributor) activate(confirmedUser);
      return Promise.resolve();
    }
    const task = (async () => {
      try {
        const user = dataOf(await api('/user/info'));
        if (!isCurrentSession(sessionSnapshot)) return;
        if (!isValidUserIdentity(user)) {
          throw new Error('Invalid user identity response');
        }
        state.detectFailureCount = 0;
        state.nextDetectAt = 0;
        state.decidedToken = sessionSnapshot.token;
        window.__xboardAuthSession?.confirmUserIdentity?.(sessionSnapshot, user);
        if (user?.is_distributor) activate(user);
        else if (state.active) deactivateDistributorView();
      } catch (error) {
        if (!isCurrentSession(sessionSnapshot) || error?.isCancelled) return;
        if (isConfirmedAuthFailure(error?.status, error?.rawMessage || error?.message)) {
          state.decidedToken = sessionSnapshot.token;
          state.detectFailureCount = 0;
          state.nextDetectAt = 0;
          return;
        }
        if (error?.isTransientRead || error?.isTimeout) {
          state.nextDetectAt = Infinity;
          if (!document.hidden) {
            window.__xboardAuthSession?.showRecoveryPrompt?.({
              owner: 'distributor-detection',
              targetPath: window.location.hash.replace(/^#/, '') || '/plan',
              message: displayError(error),
              onRetry: () => detectDistributor({ force: true }),
            });
          }
          return;
        }
        state.detectFailureCount += 1;
        const delay = Math.min(5000, 600 * (2 ** Math.min(state.detectFailureCount - 1, 4)));
        state.nextDetectAt = Date.now() + delay;
      } finally {
        if (state.detectInFlight?.promise === task) {
          state.detectInFlight = null;
        }
      }
    })();
    state.detectInFlight = {
      localEpoch: sessionSnapshot.localEpoch,
      token: sessionSnapshot.token,
      startedAt: Date.now(),
      promise: task,
    };
    return task;
  }

  document.addEventListener('click', (event) => {
    if (!state.active) return;
    if (event.target.closest('[data-modal-action]')) handleModalAction(event.target);
    else if (event.target.classList.contains('dist-reset-modal-backdrop')) {
      if (!state.resetModal?.submitting) closeResetModal();
    }
    else if (event.target.classList.contains('dist-modal-backdrop') && (isMobileOrderActionModal() || state.modal?.type === 'entitlement')) closeModal();
    else handleAction(event.target);
  });
  document.addEventListener('input', (event) => {
    if (!state.active) return;
    const qtyInput = event.target?.closest?.('[data-plan-qty-input]');
    if (!qtyInput) return;
    const planId = qtyInput.dataset.planQtyInput;
    const digits = String(qtyInput.value || '').replace(/[^\d]/g, '');
    extendPlanPriceTimerIfVisible();
    if (digits === '' || Number(digits) < 1) {
      if (qtyInput.value !== digits) qtyInput.value = digits;
      state.selectedQuantities[planId] = 1;
      syncPlanCardQuantityDom(planId, 1);
      return;
    }
    let parsed = Number.parseInt(digits, 10);
    if (parsed > MAX_ORDER_QUANTITY) {
      parsed = MAX_ORDER_QUANTITY;
      qtyInput.value = String(MAX_ORDER_QUANTITY);
      toast(t('quantityMaxHint'));
    } else if (qtyInput.value !== String(parsed)) {
      qtyInput.value = String(parsed);
    }
    state.selectedQuantities[planId] = parsed;
    syncPlanCardQuantityDom(planId, parsed);
  });
  document.addEventListener('focusout', (event) => {
    if (!state.active) return;
    const qtyInput = event.target?.closest?.('[data-plan-qty-input]');
    if (!qtyInput) return;
    const planId = qtyInput.dataset.planQtyInput;
    const normalized = normalizeOrderQuantity(qtyInput.value);
    qtyInput.value = String(normalized);
    state.selectedQuantities[planId] = normalized;
    syncPlanCardQuantityDom(planId, normalized);
  });
  document.addEventListener('change', (event) => {
    if (!state.active) return;
    const qtyInput = event.target?.closest?.('[data-plan-qty-input]');
    if (qtyInput) {
      const planId = qtyInput.dataset.planQtyInput;
      const normalized = normalizeOrderQuantity(qtyInput.value);
      qtyInput.value = String(normalized);
      state.selectedQuantities[planId] = normalized;
      syncPlanCardQuantityDom(planId, normalized);
      return;
    }
    if (event.target.id === 'dist-order-page-size') {
      state.orderPerPage = Number(event.target.value) || 20;
      state.orderPage = 1;
      renderOrders().catch((error) => toastErrorIfCurrent(error));
    } else if (event.target.id === 'dist-renewal-period' && state.modal?.type === 'renewal') {
      if (state.modal.submitting || state.modal.uncertain || state.modal.result) return;
      state.modal.period = event.target.value;
      renderModal();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (!state.active) return;
    if (event.key === 'Tab' && state.resetModal) {
      const elements = Array.from(document.querySelectorAll('#dist-reset-modal-root button:not(:disabled)'));
      if (!elements.length) {
        event.preventDefault();
        return;
      }
      const first = elements[0];
      const last = elements[elements.length - 1];
      if (!elements.includes(document.activeElement)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus({ preventScroll: true });
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus({ preventScroll: true });
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus({ preventScroll: true });
      }
      return;
    }
    if (event.key === 'Escape' && state.resetModal) {
      event.preventDefault();
      if (!state.resetModal.submitting) closeResetModal();
      return;
    }
    if (event.key === 'Tab' && state.modal?.type === 'notice') {
      const elements = Array.from(document.querySelectorAll('.dist-modal [data-modal-action], .dist-notice-body, .dist-notice-body a[href]'));
      const first = elements[0];
      const last = elements[elements.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
    if (event.key === 'Escape' && (isMobileOrderActionModal() || state.modal?.type === 'entitlement' || state.modal?.type === 'notice')) {
      event.preventDefault();
      closeModal();
      return;
    }
    const qtyKeyInput = event.target?.closest?.('[data-plan-qty-input]');
    if (qtyKeyInput && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
      event.preventDefault();
      const planId = qtyKeyInput.dataset.planQtyInput;
      const delta = event.key === 'ArrowUp' ? 1 : -1;
      const next = normalizeOrderQuantity(selectedPlanQuantity(planId) + delta);
      qtyKeyInput.value = String(next);
      state.selectedQuantities[planId] = next;
      extendPlanPriceTimerIfVisible();
      syncPlanCardQuantityDom(planId, next);
      return;
    }
    if (qtyKeyInput && event.key === 'Enter') {
      event.preventDefault();
      qtyKeyInput.blur();
      return;
    }
    if (event.key !== 'Enter') return;
    if (event.target.id === 'dist-order-search') {
      event.preventDefault();
      state.orderSearch = event.target.value.trim();
      state.orderPage = 1;
      renderOrders().catch((error) => toastErrorIfCurrent(error));
    } else if (event.target.id === 'dist-knowledge-search') {
      event.preventDefault();
      state.knowledgeSearch = event.target.value.trim();
      renderKnowledge().catch((error) => toastErrorIfCurrent(error));
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
  function verifyActiveSession(sessionSnapshot = syncSession()) {
    if (!state.active || !sessionSnapshot.token) return Promise.resolve();
    if (
      state.sessionCheckInFlight &&
      state.sessionCheckInFlight.localEpoch === sessionSnapshot.localEpoch &&
      state.sessionCheckInFlight.token === sessionSnapshot.token
    ) {
      return state.sessionCheckInFlight.promise;
    }
    const task = (async () => {
      try {
        const user = dataOf(await api('/user/info'));
        if (!isCurrentSession(sessionSnapshot) || !isValidUserIdentity(user)) return;
        window.__xboardAuthSession?.confirmUserIdentity?.(sessionSnapshot, user);
        if (!user?.is_distributor) {
          deactivateDistributorView();
          return;
        }
        state.user = user;
        const accountEl = document.querySelector('#distributor-app .dist-account');
        if (accountEl) accountEl.textContent = `● ${distributorAccountLabel(state.user)}`;
      } catch (_) {
        // Confirmed auth failures are already handled by api(); ignore transient errors during silent check.
      } finally {
        if (state.sessionCheckInFlight?.promise === task) {
          state.sessionCheckInFlight = null;
        }
      }
    })();
    state.sessionCheckInFlight = {
      localEpoch: sessionSnapshot.localEpoch,
      token: sessionSnapshot.token,
      promise: task,
    };
    return task;
  }

  function recoverDistributorView(options = {}) {
    if (document.hidden || navigator.onLine === false) return;
    const currentSession = syncSession();
    if (!currentSession.token) return;
    const persisted = Boolean(options.persisted);
    const hiddenDuration = Number(options.hiddenDuration || 0);
    if (!state.active) {
      if (
        state.detectInFlight &&
        Date.now() - Number(state.detectInFlight.startedAt || 0) >= READ_TIMEOUT_MS
      ) {
        abortScopedRequests('session', 'cancelled');
        state.detectInFlight = null;
      }
      if (persisted || state.decidedToken !== currentSession.token) {
        state.nextDetectAt = 0;
        detectDistributor({ force: true });
      }
      return;
    }
    const root = document.getElementById('distributor-app');
    const isStuckLoadingOrError = state.needsRouteRecovery || !root
      || !root.children.length
      || Boolean(root.querySelector('.dist-loading, .dist-error'));
    if (isStuckLoadingOrError) {
      if (!state.needsRouteRecovery && state.routeControllers.size) return;
      state.needsRouteRecovery = false;
      abortScopedRequests('route', 'cancelled');
      renderPage({ preserveContent: true });
      if (persisted || hiddenDuration >= RESUME_REVALIDATE_MS) {
        verifyActiveSession(currentSession);
      }
      return;
    }
    if (persisted || hiddenDuration >= RESUME_REVALIDATE_MS) {
      verifyActiveSession(currentSession);
    }
  }

  function scheduleResumeCheck(options = {}) {
    state.pendingResume = {
      persisted: Boolean(state.pendingResume?.persisted || options.persisted),
      hiddenDuration: Math.max(
        Number(state.pendingResume?.hiddenDuration || 0),
        Number(options.hiddenDuration || 0)
      ),
    };
    if (state.resumeTimer) clearTimeout(state.resumeTimer);
    state.resumeTimer = setTimeout(() => {
      state.resumeTimer = null;
      const merged = state.pendingResume || { persisted: false, hiddenDuration: 0 };
      state.pendingResume = null;
      recoverDistributorView(merged);
    }, RESUME_COALESCE_MS);
  }

  function startDetectorLoop() {
    if (state.detectorTimer) return;
    state.detectorTimer = setInterval(() => {
      if (document.hidden) return;
      const current = syncSession();
      if (state.active || !current.token || state.decidedToken === current.token) return;
      detectDistributor();
    }, 300);
  }

  function stopDetectorLoop() {
    if (!state.detectorTimer) return;
    clearInterval(state.detectorTimer);
    state.detectorTimer = null;
  }

  function handleSessionChange() {
    const current = syncSession();
    if (!current.token) {
      if (state.active) deactivateDistributorView();
      return;
    }
    startDetectorLoop();
    detectDistributor({ force: true });
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) closePlanPrices();
    if (document.hidden) {
      state.lastHiddenAt = Date.now();
      const root = document.getElementById('distributor-app');
      if (root?.querySelector('.dist-loading, .dist-error')
        || Array.from(state.routeControllers).some((record) => record.path !== '/user/notice/fetch')) state.needsRouteRecovery = true;
      abortScopedRequests('route', 'cancelled');
      abortScopedRequests('session', 'cancelled');
      state.detectInFlight = null;
      state.sessionCheckInFlight = null;
      return;
    }
    const hiddenDuration = state.lastHiddenAt > 0 ? Math.max(0, Date.now() - state.lastHiddenAt) : 0;
    state.lastHiddenAt = 0;
    scheduleResumeCheck({ persisted: false, hiddenDuration });
  });
  window.addEventListener('pageshow', (event) => {
    if (document.hidden) return;
    const hiddenDuration = state.lastHiddenAt > 0 ? Math.max(0, Date.now() - state.lastHiddenAt) : 0;
    state.lastHiddenAt = 0;
    scheduleResumeCheck({ persisted: Boolean(event?.persisted), hiddenDuration });
  });
  window.addEventListener('online', () => {
    if (!document.hidden) scheduleResumeCheck();
  });
  window.addEventListener('xboard:auth-user-confirmed', () => {
    const snapshot = syncSession();
    const user = window.__xboardAuthSession?.getConfirmedUser?.(snapshot);
    if (!isValidUserIdentity(user) || state.detectInFlight) return;
    state.decidedToken = snapshot.token;
    if (!user.is_distributor) {
      if (state.active) deactivateDistributorView();
    } else if (!state.active) activate(user);
  });
  window.addEventListener('storage', (event) => {
    if (!event || !event.key || String(event.key).toUpperCase() === TOKEN_KEY) {
      handleSessionChange();
    }
    if (!event || !event.key || String(event.key).startsWith(`${PURCHASE_RECOVERY_STORAGE_KEY}:`)) {
      const ownKey = purchaseRecoveryStorageKey();
      if (ownKey && (!event?.key || event.key === ownKey)) syncPurchaseRecoveryFromStorage();
    }
  });
  window.addEventListener('xboard:auth-token-change', handleSessionChange);
  let routeRenderQueued = false;
  function queueRouteRender() {
    if (!state.active || state.routeRenderHash === window.location.hash) return;
    if (routeRenderQueued) return;
    routeRenderQueued = true;
    window.queueMicrotask(() => {
      routeRenderQueued = false;
      if (!state.active || state.routeRenderHash === window.location.hash) return;
      if (currentPage() !== '/plan') closePlanPrices(false);
      renderPage();
    });
  }
  window.addEventListener('hashchange', queueRouteRender);
  window.addEventListener('popstate', queueRouteRender);
  startDetectorLoop();
  detectDistributor();
})();
