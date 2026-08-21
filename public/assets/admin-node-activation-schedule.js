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
      action: '定时设置',
      title: '每日节点激活计划',
      description: '每天到达开启时间后打开“已激活”开关，到达关闭时间后关闭。支持跨午夜，固定采用 Asia/Singapore 时区。',
      enableAt: '每天开启时间',
      disableAt: '每天关闭时间',
      save: '保存计划',
      saving: '保存中…',
      cancel: '关闭',
      drop: '删除计划',
      dropping: '删除中…',
      loading: '正在读取计划…',
      saved: '每日激活计划已保存，并已校准当前开关状态',
      dropped: '激活计划已删除',
      none: '当前没有激活计划',
      active: '当前处于每日开启时段',
      inactive: '当前处于每日关闭时段',
      legacy: '检测到旧的一次性计划；保存后将替换为每日循环计划',
      invalidRange: '开启时间和关闭时间必须是两个不同的有效时间。',
      everyDay: '每天',
      nextDay: '次日',
      requestFailed: '操作失败，请稍后重试。',
      closeLabel: '关闭节点激活计划',
    },
    'en-US': {
      action: 'Schedule',
      title: 'Daily node activation schedule',
      description: 'The Enabled switch turns on and off at these times every day. Overnight ranges are supported. Time zone: Asia/Singapore.',
      enableAt: 'Enable every day at',
      disableAt: 'Disable every day at',
      save: 'Save schedule',
      saving: 'Saving…',
      cancel: 'Close',
      drop: 'Delete schedule',
      dropping: 'Deleting…',
      loading: 'Loading schedule…',
      saved: 'Daily schedule saved and the current switch state reconciled',
      dropped: 'Activation schedule deleted',
      none: 'No activation schedule',
      active: 'Currently inside the daily active window',
      inactive: 'Currently outside the daily active window',
      legacy: 'A legacy one-time schedule exists; saving replaces it with a daily schedule',
      invalidRange: 'Enable and disable must be two different valid times.',
      everyDay: 'Daily',
      nextDay: 'next day',
      requestFailed: 'The operation failed. Please try again.',
      closeLabel: 'Close node activation schedule',
    },
    'ru-RU': {
      action: 'Расписание',
      title: 'Ежедневное расписание активации',
      description: 'Переключатель включается и выключается ежедневно. Поддерживается интервал через полночь. Часовой пояс: Asia/Singapore.',
      enableAt: 'Включать ежедневно в',
      disableAt: 'Выключать ежедневно в',
      save: 'Сохранить',
      saving: 'Сохранение…',
      cancel: 'Закрыть',
      drop: 'Удалить',
      dropping: 'Удаление…',
      loading: 'Загрузка…',
      saved: 'Ежедневное расписание сохранено, текущее состояние обновлено',
      dropped: 'Расписание удалено',
      none: 'Расписание не задано',
      active: 'Сейчас активный ежедневный интервал',
      inactive: 'Сейчас неактивный ежедневный интервал',
      legacy: 'Найдено старое одноразовое расписание; сохранение заменит его ежедневным',
      invalidRange: 'Время включения и выключения должно различаться.',
      everyDay: 'Ежедневно',
      nextDay: 'следующий день',
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
    if (!Number.isSafeInteger(value.server_id) || value.server_id < 1) throw new Error('Invalid server_id');
    if (typeof value.revision !== 'string' || !value.revision) throw new Error('Invalid revision');
    if (value.schedule_type === 'daily') {
      for (const field of ['enable_time', 'disable_time']) {
        if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(value[field])) throw new Error(`Invalid ${field}`);
      }
      if (value.enable_time === value.disable_time) throw new Error('Daily times must be different');
      if (value.timezone !== 'Asia/Singapore') throw new Error('Invalid timezone');
      if (!Number.isSafeInteger(value.next_transition_at) || value.next_transition_at < 1) {
        throw new Error('Invalid next_transition_at');
      }
      if (typeof value.next_target_enabled !== 'boolean') throw new Error('Invalid next_target_enabled');
      if (!['active', 'inactive'].includes(value.phase)) throw new Error('Invalid phase');
      return {
        server_id: value.server_id,
        schedule_type: 'daily',
        timezone: value.timezone,
        enable_time: value.enable_time,
        disable_time: value.disable_time,
        revision: value.revision,
        next_transition_at: value.next_transition_at,
        next_target_enabled: value.next_target_enabled,
        phase: value.phase,
      };
    }

    for (const field of ['enable_at', 'disable_at']) {
      if (!Number.isSafeInteger(value[field]) || value[field] < 1) throw new Error(`Invalid ${field}`);
    }
    if (value.disable_at <= value.enable_at) throw new Error('Invalid disable_at');
    if (!['pending', 'active', 'completed'].includes(value.phase)) throw new Error('Invalid phase');
    return {
      server_id: value.server_id,
      schedule_type: 'once',
      enable_at: value.enable_at,
      disable_at: value.disable_at,
      revision: value.revision,
      phase: value.phase,
    };
  }

  function normalizeDailyRange(enableValue, disableValue) {
    const timePattern = /^([01]\d|2[0-3]):[0-5]\d$/;
    if (!timePattern.test(enableValue) || !timePattern.test(disableValue)) throw new Error('Invalid time');
    if (enableValue === disableValue) throw new Error('Times must be different');
    return {
      schedule_type: 'daily',
      enable_time: enableValue,
      disable_time: disableValue,
    };
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

  async function saveSchedule(serverId, enableTime, disableTime) {
    return normalizeSchedule(await request('activationSchedule', {
      method: 'POST',
      body: {
        server_id: serverId,
        ...normalizeDailyRange(enableTime, disableTime),
      },
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

  function formatLegacyTime(timestamp) {
    const date = new Date(timestamp * 1000);
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Asia/Singapore',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(date);
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return `${values.hour}:${values.minute}`;
  }

  function defaultRange() {
    return {
      enable_time: '19:00',
      disable_time: '01:00',
    };
  }

  function rangeSummary(copy, enableTime, disableTime) {
    const crossesMidnight = disableTime < enableTime;
    return `${copy.everyDay} ${enableTime} – ${crossesMidnight ? `${copy.nextDay} ` : ''}${disableTime} · Asia/Singapore`;
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
    enableInput.type = 'time';
    enableInput.required = true;
    enableInput.step = '60';
    enableLabel.append(enableInput);
    form.append(enableLabel);
    const disableLabel = element('label', 'xboard-node-schedule-field');
    disableLabel.append(element('span', '', copy.disableAt));
    const disableInput = element('input');
    disableInput.type = 'time';
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
      const range = schedule?.schedule_type === 'daily'
        ? schedule
        : (schedule?.schedule_type === 'once'
          ? {
              enable_time: formatLegacyTime(schedule.enable_at),
              disable_time: formatLegacyTime(schedule.disable_at),
            }
          : defaultRange());
      enableInput.value = range.enable_time;
      disableInput.value = range.disable_time;
      const summary = rangeSummary(copy, range.enable_time, range.disable_time);
      const stateMessage = schedule?.schedule_type === 'daily'
        ? copy[schedule.phase]
        : (schedule ? copy.legacy : copy.none);
      status.textContent = `${message || stateMessage} · ${summary}`;
      status.dataset.state = schedule?.phase || 'none';
      drop.hidden = !schedule;
      form.hidden = false;
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      let range;
      try {
        range = normalizeDailyRange(enableInput.value, disableInput.value);
      } catch (_) {
        status.textContent = copy.invalidRange;
        status.dataset.state = 'error';
        return;
      }
      save.disabled = true;
      drop.disabled = true;
      save.textContent = copy.saving;
      try {
        const schedule = await saveSchedule(serverId, range.enable_time, range.disable_time);
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
      const trigger = element('button', 'xboard-node-schedule-trigger', `◷ ${copy.action}`);
      trigger.type = 'button';
      trigger.dataset.xboardNodeScheduleTrigger = '1';
      trigger.dataset.serverId = String(serverId);
      trigger.title = copy.action;
      trigger.setAttribute('aria-label', copy.action);
      trigger.addEventListener('click', () => openDialog(serverId, trigger));
      cell.insertBefore(trigger, node);
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
    normalizeDailyRange,
    normalizeSchedule,
    parseServerId,
    requestSchedule,
    saveSchedule,
    start,
    stop,
  };
});
