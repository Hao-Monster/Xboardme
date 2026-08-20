(function (root, factory) {
  'use strict';

  if (typeof module === 'object' && module.exports) {
    module.exports = factory;
    return;
  }

  if (root.__xboardAdminNodeActivationSchedule) return;
  const app = factory(root);
  root.__xboardAdminNodeActivationSchedule = app;
  app.start();
})(typeof window !== 'undefined' ? window : globalThis, function createNodeActivationSchedule(window) {
  'use strict';

  const TOKEN_KEY = 'XBOARD_ACCESS_TOKEN';
  const SWITCH_SELECTOR = 'button[role="switch"][aria-label="Enabled"], button[role="switch"][aria-label="Disabled"]';
  const TRIGGER_SELECTOR = '[data-xboard-node-schedule-trigger]';
  const COPY = {
    'zh-CN': {
      action: '设置节点激活时间',
      title: '节点激活计划',
      description: '到达开启时间后打开“已激活”开关，到达关闭时间后关闭。时间采用当前浏览器时区。',
      enableAt: '开启时间',
      disableAt: '关闭时间',
      save: '保存计划',
      saving: '保存中…',
      cancel: '关闭',
      drop: '删除计划',
      dropping: '删除中…',
      loading: '正在读取计划…',
      saved: '激活计划已保存',
      dropped: '激活计划已删除',
      none: '当前没有激活计划',
      pending: '计划尚未开始',
      active: '计划执行中',
      completed: '计划已结束',
      invalidRange: '关闭时间必须晚于开启时间。',
      requestFailed: '操作失败，请稍后重试。',
      closeLabel: '关闭节点激活计划',
    },
    'en-US': {
      action: 'Schedule node activation',
      title: 'Node activation schedule',
      description: 'The Enabled switch turns on at the start and off at the end. Times use the current browser time zone.',
      enableAt: 'Enable at',
      disableAt: 'Disable at',
      save: 'Save schedule',
      saving: 'Saving…',
      cancel: 'Close',
      drop: 'Delete schedule',
      dropping: 'Deleting…',
      loading: 'Loading schedule…',
      saved: 'Activation schedule saved',
      dropped: 'Activation schedule deleted',
      none: 'No activation schedule',
      pending: 'Schedule has not started',
      active: 'Schedule is active',
      completed: 'Schedule has ended',
      invalidRange: 'The end time must be later than the start time.',
      requestFailed: 'The operation failed. Please try again.',
      closeLabel: 'Close node activation schedule',
    },
    'ru-RU': {
      action: 'Расписание активации узла',
      title: 'Расписание активации узла',
      description: 'Переключатель включится в начале интервала и выключится в конце. Используется часовой пояс браузера.',
      enableAt: 'Включить в',
      disableAt: 'Выключить в',
      save: 'Сохранить',
      saving: 'Сохранение…',
      cancel: 'Закрыть',
      drop: 'Удалить',
      dropping: 'Удаление…',
      loading: 'Загрузка…',
      saved: 'Расписание сохранено',
      dropped: 'Расписание удалено',
      none: 'Расписание не задано',
      pending: 'Расписание ещё не началось',
      active: 'Расписание выполняется',
      completed: 'Расписание завершено',
      invalidRange: 'Время окончания должно быть позже времени начала.',
      requestFailed: 'Операция не выполнена. Повторите попытку.',
      closeLabel: 'Закрыть расписание активации',
    },
  };

  const document = window.document || null;
  let observer = null;
  let dialog = null;
  let dialogReturnFocus = null;
  let mountScheduled = false;

  function normalizeLanguage(value) {
    const language = String(value || '').toLowerCase();
    if (language.startsWith('en')) return 'en-US';
    if (language.startsWith('ru')) return 'ru-RU';
    return 'zh-CN';
  }

  function currentLanguage() {
    return normalizeLanguage(
      window.localStorage?.getItem('i18nextLng')
      || window.navigator?.language
    );
  }

  function isMachineRoute(hash) {
    const route = String(hash || '').replace(/^#/, '').split('?')[0].replace(/\/+$/, '');
    return route === '/server/machine';
  }

  function parseServerId(value) {
    const match = String(value || '').match(/^\s*#(\d+)\b/);
    if (!match) return null;
    const serverId = Number(match[1]);
    return Number.isSafeInteger(serverId) && serverId > 0 ? serverId : null;
  }

  function normalizeSchedule(value) {
    if (value === null) return null;
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      throw new Error('Invalid schedule');
    }
    for (const field of ['server_id', 'enable_at', 'disable_at']) {
      if (!Number.isSafeInteger(value[field]) || value[field] < 1) {
        throw new Error(`Invalid ${field}`);
      }
    }
    if (value.disable_at <= value.enable_at) throw new Error('Invalid disable_at');
    if (typeof value.revision !== 'string' || !value.revision) throw new Error('Invalid revision');
    if (!['pending', 'active', 'completed'].includes(value.phase)) throw new Error('Invalid phase');
    for (const field of ['enabled_applied_at', 'disabled_applied_at']) {
      if (value[field] !== null && (!Number.isSafeInteger(value[field]) || value[field] < 1)) {
        throw new Error(`Invalid ${field}`);
      }
    }
    return {
      server_id: value.server_id,
      enable_at: value.enable_at,
      disable_at: value.disable_at,
      revision: value.revision,
      enabled_applied_at: value.enabled_applied_at,
      disabled_applied_at: value.disabled_applied_at,
      phase: value.phase,
    };
  }

  function normalizeInputRange(enableValue, disableValue) {
    const enableMilliseconds = new Date(enableValue).getTime();
    const disableMilliseconds = new Date(disableValue).getTime();
    if (!Number.isFinite(enableMilliseconds) || !Number.isFinite(disableMilliseconds)) {
      throw new Error('Invalid date');
    }
    const enableAt = Math.floor(enableMilliseconds / 1000);
    const disableAt = Math.floor(disableMilliseconds / 1000);
    if (disableAt <= enableAt) throw new Error('End time must be later');
    return { enable_at: enableAt, disable_at: disableAt };
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

  async function request(path, options = {}) {
    const token = authToken();
    if (!token) throw new Error('Admin session expired');
    if (typeof window.fetch !== 'function') throw new Error('Fetch is unavailable');
    const headers = {
      Authorization: token,
      'Content-Language': currentLanguage(),
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    };
    const response = await window.fetch(`/api/v2/${securePath()}/server/manage/${path}`, {
      method: options.method || 'GET',
      headers,
      credentials: 'same-origin',
      cache: 'no-store',
      ...(options.body ? { body: JSON.stringify(options.body) } : {}),
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || payload?.status === 'fail') {
      const validationMessage = payload?.errors
        ? Object.values(payload.errors).flat().find(Boolean)
        : null;
      throw new Error(validationMessage || payload?.message || `Request failed (${response.status || 'unknown'})`);
    }
    return payload?.data ?? null;
  }

  async function requestSchedule(serverId) {
    return normalizeSchedule(await request(`activationSchedule?server_id=${encodeURIComponent(serverId)}`));
  }

  async function saveSchedule(serverId, enableAt, disableAt) {
    return normalizeSchedule(await request('activationSchedule', {
      method: 'POST',
      body: { server_id: serverId, enable_at: enableAt, disable_at: disableAt },
    }));
  }

  async function dropSchedule(serverId) {
    return request('dropActivationSchedule', {
      method: 'POST',
      body: { server_id: serverId },
    });
  }

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function formatInput(timestamp) {
    const date = new Date(timestamp * 1000);
    const pad = (value) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function defaultRange() {
    const start = new Date();
    start.setSeconds(0, 0);
    start.setMinutes(Math.ceil(start.getMinutes() / 5) * 5);
    if (start.getTime() <= Date.now()) start.setMinutes(start.getMinutes() + 5);
    return {
      enable_at: Math.floor(start.getTime() / 1000),
      disable_at: Math.floor(start.getTime() / 1000) + 3600,
    };
  }

  function closeDialog() {
    const returnFocus = dialogReturnFocus;
    dialog?.remove();
    dialog = null;
    dialogReturnFocus = null;
    returnFocus?.focus?.();
  }

  function openDialog(serverId, returnFocus) {
    closeDialog();
    dialogReturnFocus = returnFocus || null;
    const copy = COPY[currentLanguage()];
    const overlay = element('div', 'xboard-node-schedule-overlay');
    const panel = element('section', 'xboard-node-schedule-dialog');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-labelledby', 'xboard-node-schedule-title');

    const header = element('header', 'xboard-node-schedule-header');
    const heading = element('div');
    const title = element('h2', 'xboard-node-schedule-title', copy.title);
    title.id = 'xboard-node-schedule-title';
    heading.append(title);
    heading.append(element('p', 'xboard-node-schedule-description', copy.description));
    header.append(heading);
    const close = element('button', 'xboard-node-schedule-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', copy.closeLabel);
    close.addEventListener('click', closeDialog);
    header.append(close);
    panel.append(header);

    const status = element('p', 'xboard-node-schedule-status', copy.loading);
    status.setAttribute('role', 'status');
    panel.append(status);

    const form = element('form', 'xboard-node-schedule-form');
    form.hidden = true;
    const enableLabel = element('label', 'xboard-node-schedule-field');
    enableLabel.append(element('span', '', copy.enableAt));
    const enableInput = element('input');
    enableInput.type = 'datetime-local';
    enableInput.required = true;
    enableInput.step = '60';
    enableLabel.append(enableInput);
    form.append(enableLabel);
    const disableLabel = element('label', 'xboard-node-schedule-field');
    disableLabel.append(element('span', '', copy.disableAt));
    const disableInput = element('input');
    disableInput.type = 'datetime-local';
    disableInput.required = true;
    disableInput.step = '60';
    disableLabel.append(disableInput);
    form.append(disableLabel);

    const actions = element('div', 'xboard-node-schedule-actions');
    const drop = element('button', 'xboard-node-schedule-drop', copy.drop);
    drop.type = 'button';
    drop.hidden = true;
    actions.append(drop);
    const spacer = element('span', 'xboard-node-schedule-spacer');
    actions.append(spacer);
    const cancel = element('button', 'xboard-node-schedule-secondary', copy.cancel);
    cancel.type = 'button';
    cancel.addEventListener('click', closeDialog);
    actions.append(cancel);
    const save = element('button', 'xboard-node-schedule-primary', copy.save);
    save.type = 'submit';
    actions.append(save);
    form.append(actions);
    panel.append(form);
    overlay.append(panel);
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) closeDialog();
    });
    document.body.append(overlay);
    dialog = overlay;
    close.focus();

    function showSchedule(schedule, message) {
      const range = schedule || defaultRange();
      enableInput.value = formatInput(range.enable_at);
      disableInput.value = formatInput(range.disable_at);
      status.textContent = message || (schedule ? copy[schedule.phase] : copy.none);
      status.dataset.state = schedule?.phase || 'none';
      drop.hidden = !schedule;
      form.hidden = false;
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      let range;
      try {
        range = normalizeInputRange(enableInput.value, disableInput.value);
      } catch (_) {
        status.textContent = copy.invalidRange;
        status.dataset.state = 'error';
        return;
      }
      save.disabled = true;
      drop.disabled = true;
      save.textContent = copy.saving;
      try {
        const schedule = await saveSchedule(serverId, range.enable_at, range.disable_at);
        showSchedule(schedule, copy.saved);
      } catch (error) {
        status.textContent = error?.message || copy.requestFailed;
        status.dataset.state = 'error';
      } finally {
        save.disabled = false;
        drop.disabled = false;
        save.textContent = copy.save;
      }
    });

    drop.addEventListener('click', async () => {
      drop.disabled = true;
      save.disabled = true;
      drop.textContent = copy.dropping;
      try {
        await dropSchedule(serverId);
        showSchedule(null, copy.dropped);
      } catch (error) {
        status.textContent = error?.message || copy.requestFailed;
        status.dataset.state = 'error';
      } finally {
        drop.disabled = false;
        save.disabled = false;
        drop.textContent = copy.drop;
      }
    });

    void requestSchedule(serverId)
      .then((schedule) => showSchedule(schedule))
      .catch((error) => {
        status.textContent = error?.message || copy.requestFailed;
        status.dataset.state = 'error';
      });
  }

  function isAssociatedNodeSwitch(node) {
    const row = node.closest?.('tr');
    const table = node.closest?.('table');
    if (!row || !table) return false;
    const headings = [...table.querySelectorAll('th')].map((heading) => heading.textContent.trim());
    const labels = ['已激活', 'Enabled', 'Активен'];
    return labels.some((label) => headings.includes(label));
  }

  function mount() {
    if (!document || !isMachineRoute(window.location?.hash)) {
      closeDialog();
      return false;
    }
    const copy = COPY[currentLanguage()];
    let mounted = false;
    for (const node of document.querySelectorAll(SWITCH_SELECTOR)) {
      if (!isAssociatedNodeSwitch(node)) continue;
      const row = node.closest('tr');
      const serverId = parseServerId(row?.querySelector('td')?.textContent);
      if (!serverId) continue;
      const cell = node.closest('td');
      if (!cell || cell.querySelector(TRIGGER_SELECTOR)) continue;
      cell.classList.add('xboard-node-schedule-cell');
      const trigger = element('button', 'xboard-node-schedule-trigger', '◷');
      trigger.type = 'button';
      trigger.dataset.xboardNodeScheduleTrigger = '1';
      trigger.dataset.serverId = String(serverId);
      trigger.title = copy.action;
      trigger.setAttribute('aria-label', copy.action);
      trigger.addEventListener('click', () => openDialog(serverId, trigger));
      cell.append(trigger);
      mounted = true;
    }
    return mounted;
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

  function handleKeydown(event) {
    if (!dialog) return;
    if (event.key === 'Escape') {
      closeDialog();
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = [...dialog.querySelectorAll('button:not([disabled]):not([hidden]), input:not([disabled]):not([hidden])')];
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
      event.preventDefault();
      first.focus();
    }
  }

  function start() {
    if (!document) return false;
    const root = document.getElementById('root');
    if (!root) return false;
    observer = new window.MutationObserver(scheduleMount);
    observer.observe(root, { childList: true, subtree: true });
    window.addEventListener('hashchange', scheduleMount);
    document.addEventListener('keydown', handleKeydown);
    scheduleMount();
    return true;
  }

  function stop() {
    observer?.disconnect();
    observer = null;
    window.removeEventListener?.('hashchange', scheduleMount);
    document?.removeEventListener?.('keydown', handleKeydown);
    closeDialog();
  }

  return {
    dropSchedule,
    isMachineRoute,
    normalizeInputRange,
    normalizeSchedule,
    parseServerId,
    requestSchedule,
    saveSchedule,
    start,
    stop,
  };
});
