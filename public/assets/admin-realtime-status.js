(function (root, factory) {
  'use strict';

  if (typeof module === 'object' && module.exports) {
    module.exports = factory;
    return;
  }

  if (root.__xboardAdminRealtimeStatus) return;
  const app = factory(root);
  root.__xboardAdminRealtimeStatus = app;
  app.start();
})(typeof window !== 'undefined' ? window : globalThis, function createRealtimeStatus(window) {
  'use strict';

  const REFRESH_INTERVAL = 60000;
  const TOKEN_KEY = 'XBOARD_ACCESS_TOKEN';
  const SECTION_SELECTOR = '[data-xboard-realtime-status]';
  const COPY = {
    'zh-CN': {
      title: '实时状态',
      description: '基于节点最近上报的全局在线情况',
      devices: '在线设备',
      users: '在线用户',
      nodes: '在线节点',
      devicesHint: '按用户和 IP 去重，最近 {minutes} 分钟',
      usersHint: '至少有 1 台在线设备，最近 {minutes} 分钟',
      nodesHint: '最近 {minutes} 分钟收到节点心跳',
      loading: '更新中',
      ready: '实时',
      stale: '数据可能已过期',
      unavailable: '实时数据暂不可用，请稍后重试',
      updatedAt: '更新于 {time}',
      refresh: '立即刷新',
    },
    'en-US': {
      title: 'Live status',
      description: 'Global online status based on recent node reports',
      devices: 'Online devices',
      users: 'Online users',
      nodes: 'Online nodes',
      devicesHint: 'Unique by user and IP in the last {minutes} min',
      usersHint: 'At least one online device in the last {minutes} min',
      nodesHint: 'Node heartbeat received in the last {minutes} min',
      loading: 'Updating',
      ready: 'Live',
      stale: 'Data may be stale',
      unavailable: 'Live data is unavailable. Please try again later.',
      updatedAt: 'Updated at {time}',
      refresh: 'Refresh now',
    },
    'ru-RU': {
      title: 'Состояние в реальном времени',
      description: 'Общий онлайн по последним отчётам узлов',
      devices: 'Устройства онлайн',
      users: 'Пользователи онлайн',
      nodes: 'Узлы онлайн',
      devicesHint: 'Уникальные пользователь и IP за последние {minutes} мин.',
      usersHint: 'Хотя бы одно устройство за последние {minutes} мин.',
      nodesHint: 'Heartbeat узла за последние {minutes} мин.',
      loading: 'Обновление',
      ready: 'Онлайн',
      stale: 'Данные могут быть устаревшими',
      unavailable: 'Данные недоступны. Повторите попытку позже.',
      updatedAt: 'Обновлено в {time}',
      refresh: 'Обновить',
    },
  };

  const document = window.document || null;
  let section = null;
  let observer = null;
  let poller = null;
  let requestController = null;
  let mountScheduled = false;
  let renderedLanguage = null;
  let viewState = { phase: 'loading', snapshot: null };

  function normalizeLanguage(value) {
    const language = String(value || '').toLowerCase();
    if (language.startsWith('en')) return 'en-US';
    if (language.startsWith('ru')) return 'ru-RU';
    return 'zh-CN';
  }

  function currentLanguage() {
    let queryLanguage = null;
    try {
      queryLanguage = new URLSearchParams(window.location?.search || '').get('lang');
    } catch (_) {
      queryLanguage = null;
    }

    return normalizeLanguage(
      queryLanguage
      || window.localStorage?.getItem('i18nextLng')
      || window.navigator?.language
    );
  }

  function isDashboardRoute(hash) {
    const route = String(hash || '').replace(/^#/, '').split('?')[0].replace(/\/+$/, '') || '/';
    return route === '/';
  }

  function normalizeSnapshot(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      throw new Error('Invalid realtime snapshot');
    }

    const result = {};
    for (const field of ['onlineDevices', 'onlineUsers', 'onlineNodes']) {
      if (!Number.isSafeInteger(value[field]) || value[field] < 0) {
        throw new Error(`Invalid ${field}`);
      }
      result[field] = value[field];
    }

    if (!Number.isSafeInteger(value.windowSeconds) || value.windowSeconds < 1 || value.windowSeconds > 3600) {
      throw new Error('Invalid windowSeconds');
    }
    if (!Number.isSafeInteger(value.generatedAt) || value.generatedAt < 1) {
      throw new Error('Invalid generatedAt');
    }

    result.windowSeconds = value.windowSeconds;
    result.generatedAt = value.generatedAt;
    return result;
  }

  function failedViewState(previousSnapshot) {
    return { phase: 'stale', snapshot: previousSnapshot || null };
  }

  function authToken() {
    try {
      const stored = JSON.parse(window.localStorage?.getItem(TOKEN_KEY) || 'null');
      if (!stored?.value || (stored.expire && stored.expire <= Date.now())) return null;
      return stored.value;
    } catch (_) {
      return null;
    }
  }

  function securePath() {
    return String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, '');
  }

  async function requestSnapshot(options = {}) {
    const token = authToken();
    if (!token) throw new Error('Admin session expired');
    if (typeof window.fetch !== 'function') throw new Error('Fetch is unavailable');

    const response = await window.fetch(`/api/v2/${securePath()}/stat/getRealtimeStats`, {
      method: 'GET',
      headers: {
        Authorization: token,
        'Content-Language': currentLanguage(),
      },
      credentials: 'same-origin',
      cache: 'no-store',
      signal: options.signal,
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || payload?.status === 'fail') {
      throw new Error(payload?.message || `Realtime request failed (${response.status || 'unknown'})`);
    }

    return normalizeSnapshot(payload?.data ?? payload);
  }

  function createPoller(task, options = {}) {
    const delay = options.delay ?? REFRESH_INTERVAL;
    const setTimer = options.setTimer || ((callback, timeout) => window.setTimeout(callback, timeout));
    const clearTimer = options.clearTimer || ((id) => window.clearTimeout(id));
    let timer = null;
    let running = false;
    let stopped = true;
    let paused = false;

    function clearScheduled() {
      if (timer === null) return;
      clearTimer(timer);
      timer = null;
    }

    function schedule() {
      clearScheduled();
      if (stopped || paused) return;
      timer = setTimer(() => {
        timer = null;
        void run();
      }, delay);
    }

    async function run() {
      if (stopped || paused || running) return false;
      running = true;
      clearScheduled();
      try {
        await task();
        return true;
      } finally {
        running = false;
        schedule();
      }
    }

    return {
      start() {
        if (!stopped) return Promise.resolve(false);
        stopped = false;
        paused = false;
        return run();
      },
      trigger() {
        return run();
      },
      pause() {
        paused = true;
        clearScheduled();
      },
      resume() {
        if (stopped) return Promise.resolve(false);
        paused = false;
        return run();
      },
      stop() {
        stopped = true;
        paused = false;
        clearScheduled();
      },
    };
  }

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function card(metric) {
    const item = element('article', 'xboard-realtime-card');
    item.dataset.metric = metric;
    item.setAttribute('role', 'listitem');
    const heading = element('div', 'xboard-realtime-card-heading');
    heading.append(element('span', 'xboard-realtime-card-label'));
    const indicator = element('span', 'xboard-realtime-card-indicator');
    indicator.setAttribute('aria-hidden', 'true');
    heading.append(indicator);
    item.append(heading);
    const value = element('strong', 'xboard-realtime-value', '—');
    value.dataset.field = 'value';
    value.setAttribute('aria-live', 'polite');
    item.append(value);
    const hint = element('p', 'xboard-realtime-hint');
    hint.dataset.field = 'hint';
    item.append(hint);
    return item;
  }

  function buildSection() {
    const container = element('section', 'xboard-realtime-section');
    container.dataset.xboardRealtimeStatus = '1';
    container.setAttribute('aria-labelledby', 'xboard-realtime-title');

    const header = element('div', 'xboard-realtime-header');
    const heading = element('div', 'xboard-realtime-heading');
    const title = element('h2', 'xboard-realtime-title');
    title.id = 'xboard-realtime-title';
    title.dataset.field = 'title';
    heading.append(title);
    const description = element('p', 'xboard-realtime-description');
    description.dataset.field = 'description';
    heading.append(description);
    header.append(heading);

    const controls = element('div', 'xboard-realtime-controls');
    const status = element('span', 'xboard-realtime-state');
    status.dataset.field = 'state';
    status.setAttribute('role', 'status');
    controls.append(status);
    const refresh = element('button', 'xboard-realtime-refresh');
    refresh.type = 'button';
    refresh.dataset.field = 'refresh';
    refresh.addEventListener('click', () => { void poller?.trigger(); });
    controls.append(refresh);
    header.append(controls);
    container.append(header);

    const grid = element('div', 'xboard-realtime-grid');
    grid.setAttribute('role', 'list');
    for (const metric of ['devices', 'users', 'nodes']) grid.append(card(metric));
    container.append(grid);

    const footer = element('p', 'xboard-realtime-footer');
    footer.dataset.field = 'footer';
    container.append(footer);
    return container;
  }

  function interpolate(template, values) {
    return String(template).replace(/\{(\w+)\}/g, (_, key) => String(values[key] ?? ''));
  }

  function render() {
    if (!section) return;
    const language = currentLanguage();
    const copy = COPY[language];
    const snapshot = viewState.snapshot;
    const minutes = Math.max(1, Math.round((snapshot?.windowSeconds || 300) / 60));
    const stateLabel = viewState.phase === 'loading'
      ? copy.loading
      : viewState.phase === 'stale' ? copy.stale : copy.ready;

    section.dataset.state = viewState.phase;
    section.setAttribute('aria-busy', viewState.phase === 'loading' ? 'true' : 'false');
    section.querySelector('[data-field="title"]').textContent = copy.title;
    section.querySelector('[data-field="description"]').textContent = copy.description;
    const state = section.querySelector('[data-field="state"]');
    state.textContent = stateLabel;
    state.dataset.state = viewState.phase;
    const refresh = section.querySelector('[data-field="refresh"]');
    refresh.textContent = copy.refresh;
    refresh.setAttribute('aria-label', copy.refresh);
    refresh.disabled = viewState.phase === 'loading';

    const metrics = {
      devices: { label: copy.devices, hint: copy.devicesHint, value: snapshot?.onlineDevices },
      users: { label: copy.users, hint: copy.usersHint, value: snapshot?.onlineUsers },
      nodes: { label: copy.nodes, hint: copy.nodesHint, value: snapshot?.onlineNodes },
    };
    for (const [metric, content] of Object.entries(metrics)) {
      const item = section.querySelector(`[data-metric="${metric}"]`);
      item.querySelector('.xboard-realtime-card-label').textContent = content.label;
      item.querySelector('[data-field="value"]').textContent = content.value ?? '—';
      item.querySelector('[data-field="hint"]').textContent = interpolate(content.hint, { minutes });
    }

    const footer = section.querySelector('[data-field="footer"]');
    if (snapshot) {
      const time = new Date(snapshot.generatedAt * 1000).toLocaleTimeString(language, {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
      });
      footer.textContent = interpolate(copy.updatedAt, { time });
    } else if (viewState.phase === 'stale') {
      footer.textContent = copy.unavailable;
    } else {
      footer.textContent = '';
    }
    renderedLanguage = language;
  }

  function dashboardAnchor() {
    const root = document?.getElementById('root');
    if (!root || !isDashboardRoute(window.location?.hash)) return null;
    const candidates = [...root.querySelectorAll('div.grid.gap-4')]
      .filter((node) => node.classList.contains('md:grid-cols-2') && node.classList.contains('lg:grid-cols-4'));
    if (!candidates.length) return null;

    const language = currentLanguage();
    const stats = window.XBOARD_TRANSLATIONS?.[language]?.dashboard?.stats;
    const labels = [stats?.todayIncome, stats?.monthlyDownload].filter(Boolean);
    return candidates.find((node) => labels.length === 2 && labels.every((label) => node.textContent.includes(label)))
      || candidates[0];
  }

  async function refreshSnapshot() {
    viewState = { phase: 'loading', snapshot: viewState.snapshot };
    render();

    const AbortControllerClass = window.AbortController || globalThis.AbortController;
    requestController = AbortControllerClass ? new AbortControllerClass() : null;
    try {
      const snapshot = await requestSnapshot({ signal: requestController?.signal });
      viewState = { phase: 'ready', snapshot };
    } catch (error) {
      if (error?.name === 'AbortError') return;
      viewState = failedViewState(viewState.snapshot);
    } finally {
      requestController = null;
      render();
    }
  }

  function stopDashboard() {
    requestController?.abort();
    requestController = null;
    poller?.stop();
    poller = null;
    section?.remove();
    section = null;
    renderedLanguage = null;
    viewState = { phase: 'loading', snapshot: null };
  }

  function mount() {
    if (!document || !isDashboardRoute(window.location?.hash)) {
      stopDashboard();
      return false;
    }

    const anchor = dashboardAnchor();
    if (!anchor?.parentElement) return false;
    section = document.querySelector(SECTION_SELECTOR) || section;
    if (!section?.isConnected) {
      section = buildSection();
      anchor.insertAdjacentElement('afterend', section);
      render();
    } else if (renderedLanguage !== currentLanguage()) {
      render();
    }

    if (!poller) {
      poller = createPoller(refreshSnapshot, { delay: REFRESH_INTERVAL });
      void poller.start();
    }
    return true;
  }

  function scheduleMount() {
    if (mountScheduled) return;
    mountScheduled = true;
    const schedule = window.requestAnimationFrame || ((callback) => window.setTimeout(callback, 0));
    schedule(() => {
      mountScheduled = false;
      mount();
    });
  }

  function handleVisibility() {
    if (document.hidden) {
      requestController?.abort();
      poller?.pause();
      return;
    }
    if (isDashboardRoute(window.location?.hash)) {
      mount();
      void poller?.resume();
    }
  }

  function start() {
    if (!document) return false;
    const root = document.getElementById('root');
    if (!root) return false;
    observer = new window.MutationObserver(scheduleMount);
    observer.observe(root, { childList: true, subtree: true });
    window.addEventListener('hashchange', scheduleMount);
    document.addEventListener('visibilitychange', handleVisibility);
    scheduleMount();
    return true;
  }

  function stop() {
    observer?.disconnect();
    observer = null;
    window.removeEventListener?.('hashchange', scheduleMount);
    document?.removeEventListener?.('visibilitychange', handleVisibility);
    stopDashboard();
  }

  return {
    createPoller,
    failedViewState,
    isDashboardRoute,
    normalizeLanguage,
    normalizeSnapshot,
    refreshInterval: REFRESH_INTERVAL,
    requestSnapshot,
    start,
    stop,
  };
});
