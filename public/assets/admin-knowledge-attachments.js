(function () {
  'use strict';

  if (window.__xboardKnowledgeAttachments) return;

  const TOKEN_KEY = 'XBOARD_ACCESS_TOKEN';
  const EDITOR_SELECTOR = '.rc-md-editor';
  const MAX_PARALLEL_UPLOADS = 2;
  const CHUNK_RETRIES = 3;
  const states = new Set();
  let pendingKnowledgeId = null;
  let activeWorkers = 0;

  function securePath() {
    return String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, '');
  }

  function authToken() {
    try {
      const stored = JSON.parse(localStorage.getItem(TOKEN_KEY) || 'null');
      if (!stored?.value || (stored.expire && stored.expire <= Date.now())) return null;
      return stored.value;
    } catch (_) {
      return null;
    }
  }

  function endpoint(path) {
    return `/api/v2/${securePath()}${path}`;
  }

  function dataOf(payload) {
    return payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
  }

  async function request(path, options = {}) {
    const token = authToken();
    if (!token) throw new Error('管理员登录已失效，请重新登录。');

    const headers = {
      Authorization: token,
      'Content-Language': localStorage.getItem('i18nextLng') || 'zh-CN',
      ...(options.headers || {}),
    };
    let body = options.body;
    if (options.json !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.json);
    }

    const response = await fetch(endpoint(path), {
      method: options.method || 'GET',
      headers,
      body,
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
    return dataOf(payload);
  }

  function createDraftToken() {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
  }

  function formatBytes(value) {
    const bytes = Math.max(0, Number(value) || 0);
    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(2)} MB`;
    if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${bytes} B`;
  }

  function markdownLabel(name) {
    return String(name || '附件').replace(/([\\[\]])/g, '\\$1').replace(/[\r\n]+/g, ' ');
  }

  function markdownFor(attachment) {
    const placeholder = attachment.placeholder;
    const name = markdownLabel(attachment.original_name);
    if (attachment.disposition === 'inline' && String(attachment.mime_type).startsWith('image/')) {
      return `![${name}](${placeholder})`;
    }
    if (attachment.disposition === 'inline' && String(attachment.mime_type).startsWith('video/')) {
      return `<video controls preload="metadata" src="${placeholder}"></video>`;
    }
    return `[${name}](${placeholder})`;
  }

  function appendDraftToken(body, draftToken) {
    if (!draftToken || !body) return body;
    if (body instanceof FormData || body instanceof URLSearchParams) {
      body.set('draft_token', draftToken);
      return body;
    }
    if (typeof body === 'string') {
      try {
        const parsed = JSON.parse(body);
        parsed.draft_token = draftToken;
        return JSON.stringify(parsed);
      } catch (_) {
        const params = new URLSearchParams(body);
        params.set('draft_token', draftToken);
        return params.toString();
      }
    }
    return body;
  }

  function isKnowledgePage() {
    return /^#\/config\/knowledge(?:[/?]|$)/.test(location.hash || '');
  }

  function isVisible(element) {
    return Boolean(element?.isConnected && (element.offsetParent !== null || element.getClientRects?.().length));
  }

  function activeState() {
    return [...states].reverse().find((state) => isVisible(state.panel)) || null;
  }

  function toast(message, type = 'ok') {
    let root = document.getElementById('knowledge-attachment-toasts');
    if (!root) {
      root = document.createElement('div');
      root.id = 'knowledge-attachment-toasts';
      document.body.appendChild(root);
    }
    const item = document.createElement('div');
    item.className = `knowledge-attachment-toast ${type}`;
    item.textContent = message;
    root.appendChild(item);
    setTimeout(() => item.remove(), 4000);
  }

  function installRequestBridge() {
    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
      this.__knowledgeAttachmentUrl = String(url || '');
      return originalOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
      const url = this.__knowledgeAttachmentUrl || '';
      if (/\/knowledge\/save(?:\?|$)/.test(url)) {
        body = appendDraftToken(body, activeState()?.draftToken);
      }
      if (/\/knowledge\/fetch\?[^#]*\bid=\d+/.test(url)) {
        this.addEventListener('load', () => rememberKnowledgeDetail(url, this.responseText));
      }
      return originalSend.call(this, body);
    };

    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init = {}) {
      const url = typeof input === 'string' ? input : input?.url || '';
      if (/\/knowledge\/save(?:\?|$)/.test(url) && init.body) {
        init = { ...init, body: appendDraftToken(init.body, activeState()?.draftToken) };
      }
      return originalFetch(input, init).then((response) => {
        if (/\/knowledge\/fetch\?[^#]*\bid=\d+/.test(url)) {
          response.clone().text().then((text) => rememberKnowledgeDetail(url, text)).catch(() => {});
        }
        return response;
      });
    };
  }

  function rememberKnowledgeDetail(url, responseText) {
    try {
      const response = JSON.parse(responseText);
      const fromUrl = Number(new URL(url, location.origin).searchParams.get('id'));
      const knowledgeId = Number(response?.data?.id || fromUrl);
      if (!knowledgeId) return;
      const current = activeState();
      if (current) assignKnowledge(current, knowledgeId);
      else pendingKnowledgeId = { id: knowledgeId, at: Date.now() };
    } catch (_) {
      // Not a knowledge detail response.
    }
  }

  function assignKnowledge(state, knowledgeId) {
    if (state.knowledgeId === knowledgeId) return;
    state.knowledgeId = knowledgeId;
    loadAttachments(state).catch((error) => toast(error.message, 'error'));
  }

  function panelMarkup() {
    return `
      <div class="knowledge-attachment-heading">
        <div>
          <strong>文章附件</strong>
          <span>支持图片、视频、压缩包及其他文件；文件保存在服务器私有目录。</span>
        </div>
        <button type="button" data-knowledge-attachment="choose">选择文件</button>
      </div>
      <div class="knowledge-attachment-dropzone" data-knowledge-attachment="dropzone">
        拖放文件到这里，或点击“选择文件”（支持多选）
      </div>
      <input type="file" multiple hidden data-knowledge-attachment="input">
      <div class="knowledge-attachment-list" data-knowledge-attachment="list"></div>`;
  }

  function mountEditor(editor) {
    if (editor.dataset.knowledgeAttachmentsMounted === '1') return;
    editor.dataset.knowledgeAttachmentsMounted = '1';
    const textarea = editor.querySelector('textarea');
    if (!textarea) return;

    const panel = document.createElement('section');
    panel.className = 'knowledge-attachment-panel';
    panel.innerHTML = panelMarkup();
    editor.parentElement.insertBefore(panel, editor);

    const state = {
      editor,
      textarea,
      panel,
      input: panel.querySelector('[data-knowledge-attachment="input"]'),
      list: panel.querySelector('[data-knowledge-attachment="list"]'),
      draftToken: createDraftToken(),
      knowledgeId: null,
      items: new Map(),
    };
    states.add(state);
    bindPanel(state);
    render(state);

    if (pendingKnowledgeId && Date.now() - pendingKnowledgeId.at < 3000) {
      assignKnowledge(state, pendingKnowledgeId.id);
      pendingKnowledgeId = null;
    }
  }

  function bindPanel(state) {
    const dropzone = state.panel.querySelector('[data-knowledge-attachment="dropzone"]');
    state.panel.addEventListener('click', async (event) => {
      const action = event.target.closest('[data-knowledge-attachment]')?.dataset.knowledgeAttachment;
      const itemId = event.target.closest('[data-item-id]')?.dataset.itemId;
      if (action === 'choose') state.input.click();
      if (action === 'insert' && itemId) insertAttachment(state, itemId);
      if (action === 'retry' && itemId) retryUpload(state, itemId);
      if (action === 'delete' && itemId) await deleteAttachment(state, itemId);
    });
    state.input.addEventListener('change', () => {
      enqueueFiles(state, state.input.files);
      state.input.value = '';
    });
    ['dragenter', 'dragover'].forEach((name) => dropzone.addEventListener(name, (event) => {
      event.preventDefault();
      dropzone.classList.add('is-dragging');
    }));
    ['dragleave', 'drop'].forEach((name) => dropzone.addEventListener(name, (event) => {
      event.preventDefault();
      dropzone.classList.remove('is-dragging');
    }));
    dropzone.addEventListener('drop', (event) => enqueueFiles(state, event.dataTransfer?.files));
    editorDropTarget(state.editor, state);
  }

  function editorDropTarget(editor, state) {
    editor.addEventListener('dragover', (event) => {
      if (event.dataTransfer?.types?.includes('Files')) event.preventDefault();
    });
    editor.addEventListener('drop', (event) => {
      if (!event.dataTransfer?.files?.length) return;
      event.preventDefault();
      enqueueFiles(state, event.dataTransfer.files);
    });
  }

  function enqueueFiles(state, fileList) {
    const files = Array.from(fileList || []);
    if (!files.length) return;
    files.forEach((file) => {
      const id = `local-${Date.now()}-${Math.random().toString(16).slice(2)}`;
      state.items.set(id, {
        id,
        file,
        name: file.name,
        size: file.size,
        status: file.size > 0 ? 'queued' : 'failed',
        progress: 0,
        error: file.size > 0 ? '' : '不能上传空文件。',
        uploadUuid: null,
        attachment: null,
      });
    });
    render(state);
    pumpQueue();
  }

  function nextQueuedItem() {
    for (const state of states) {
      for (const item of state.items.values()) {
        if (item.status === 'queued') return { state, item };
      }
    }
    return null;
  }

  function pumpQueue() {
    while (activeWorkers < MAX_PARALLEL_UPLOADS) {
      const next = nextQueuedItem();
      if (!next) return;
      activeWorkers += 1;
      next.item.status = 'uploading';
      render(next.state);
      uploadItem(next.state, next.item)
        .catch((error) => {
          next.item.status = 'failed';
          next.item.error = error.message || '上传失败。';
          render(next.state);
        })
        .finally(() => {
          activeWorkers -= 1;
          pumpQueue();
        });
    }
  }

  async function uploadItem(state, item) {
    let session;
    if (item.uploadUuid) {
      try {
        session = await request(`/knowledge/attachment/upload/${item.uploadUuid}`);
        if (session.attachment) return finishItem(state, item, session.attachment);
      } catch (error) {
        if (![404, 410].includes(error.status)) throw error;
        item.uploadUuid = null;
      }
    }
    if (!item.uploadUuid) {
      session = await request('/knowledge/attachment/upload/initialize', {
        method: 'POST',
        json: { original_name: item.name, size: item.size, draft_token: state.draftToken },
      });
      item.uploadUuid = session.upload_uuid;
    }

    const uploaded = new Set(session.uploaded_chunks || []);
    const chunkSize = Number(session.chunk_size);
    const totalChunks = Number(session.total_chunks);
    for (let index = 0; index < totalChunks; index += 1) {
      if (!uploaded.has(index)) {
        const chunk = item.file.slice(index * chunkSize, Math.min(item.size, (index + 1) * chunkSize));
        const hash = await sha256(chunk);
        const form = new FormData();
        form.set('index', String(index));
        form.set('sha256', hash);
        form.set('file', chunk, `${item.name}.part`);
        await retry(() => request(`/knowledge/attachment/upload/${item.uploadUuid}/chunk`, {
          method: 'POST', body: form,
        }), CHUNK_RETRIES);
      }
      item.progress = Math.round(((index + 1) / totalChunks) * 95);
      render(state);
    }

    const attachment = await request(`/knowledge/attachment/upload/${item.uploadUuid}/complete`, {
      method: 'POST', json: {},
    });
    finishItem(state, item, attachment);
  }

  function finishItem(state, item, attachment) {
    const currentKey = [...state.items.entries()].find(([, value]) => value === item)?.[0];
    item.status = 'ready';
    item.progress = 100;
    item.error = '';
    item.attachment = attachment;
    item.id = attachment.uuid;
    if (currentKey) state.items.delete(currentKey);
    state.items.set(attachment.uuid, item);
    render(state);
    toast(`${attachment.original_name} 上传完成`);
  }

  async function sha256(blob) {
    if (!crypto.subtle) throw new Error('当前浏览器不支持安全分片校验。');
    const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
    return Array.from(new Uint8Array(digest), (value) => value.toString(16).padStart(2, '0')).join('');
  }

  async function retry(operation, attempts) {
    let lastError;
    for (let attempt = 0; attempt < attempts; attempt += 1) {
      try {
        return await operation();
      } catch (error) {
        lastError = error;
        if (attempt + 1 < attempts) await new Promise((resolve) => setTimeout(resolve, 500 * (2 ** attempt)));
      }
    }
    throw lastError;
  }

  function retryUpload(state, itemId) {
    const item = state.items.get(itemId);
    if (!item || item.status !== 'failed' || !item.file) return;
    item.status = 'queued';
    item.error = '';
    render(state);
    pumpQueue();
  }

  function setTextareaValue(textarea, value) {
    const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set;
    if (setter) setter.call(textarea, value);
    else textarea.value = value;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function insertAttachment(state, itemId) {
    const attachment = state.items.get(itemId)?.attachment;
    if (!attachment) return;
    const snippet = markdownFor(attachment);
    const textarea = state.textarea;
    const start = Number.isInteger(textarea.selectionStart) ? textarea.selectionStart : textarea.value.length;
    const end = Number.isInteger(textarea.selectionEnd) ? textarea.selectionEnd : start;
    const prefix = start > 0 && textarea.value[start - 1] !== '\n' ? '\n' : '';
    const suffix = end < textarea.value.length && textarea.value[end] !== '\n' ? '\n' : '';
    const inserted = `${prefix}${snippet}${suffix}`;
    setTextareaValue(textarea, textarea.value.slice(0, start) + inserted + textarea.value.slice(end));
    textarea.focus();
    textarea.setSelectionRange(start + inserted.length, start + inserted.length);
    toast('附件已插入正文');
  }

  function removePlaceholder(state, placeholder) {
    if (!placeholder || !state.textarea.value.includes(placeholder)) return;
    const attachment = [...state.items.values()].find((item) => item.attachment?.placeholder === placeholder)?.attachment;
    let body = state.textarea.value;
    if (attachment) body = body.split(markdownFor(attachment)).join('');
    body = body.split(placeholder).join('').replace(/\n{3,}/g, '\n\n');
    setTextareaValue(state.textarea, body);
  }

  async function deleteAttachment(state, itemId) {
    const item = state.items.get(itemId);
    if (!item || item.status === 'uploading') return;
    if (!window.confirm(`确认移除附件“${item.name}”吗？`)) return;
    if (item.attachment) removePlaceholder(state, item.attachment.placeholder);
    if (item.attachment && !item.attachment.knowledge_id) {
      try {
        await request('/knowledge/attachment/drop', { method: 'POST', json: { uuid: item.attachment.uuid } });
      } catch (error) {
        toast(error.message, 'error');
        return;
      }
    }
    state.items.delete(itemId);
    render(state);
  }

  async function loadAttachments(state) {
    const query = state.knowledgeId
      ? `knowledge_id=${state.knowledgeId}`
      : `draft_token=${state.draftToken}`;
    const result = await request(`/knowledge/attachment/fetch?${query}&per_page=100`);
    for (const attachment of result.items || []) {
      state.items.set(attachment.uuid, {
        id: attachment.uuid,
        name: attachment.original_name,
        size: attachment.size,
        status: 'ready',
        progress: 100,
        error: '',
        file: null,
        uploadUuid: attachment.uuid,
        attachment,
      });
    }
    render(state);
  }

  function render(state) {
    if (!state.list) return;
    state.list.replaceChildren();
    if (!state.items.size) {
      const empty = document.createElement('div');
      empty.className = 'knowledge-attachment-empty';
      empty.textContent = '尚未添加附件';
      state.list.appendChild(empty);
      return;
    }
    state.items.forEach((item, itemId) => {
      const row = document.createElement('div');
      row.className = `knowledge-attachment-item is-${item.status}`;
      row.dataset.itemId = itemId;
      const info = document.createElement('div');
      info.className = 'knowledge-attachment-info';
      const name = document.createElement('strong');
      name.textContent = item.name;
      const meta = document.createElement('span');
      meta.textContent = `${formatBytes(item.size)} · ${statusLabel(item)}`;
      info.append(name, meta);
      if (item.status === 'uploading') {
        const progress = document.createElement('div');
        progress.className = 'knowledge-attachment-progress';
        const bar = document.createElement('i');
        bar.style.width = `${item.progress}%`;
        progress.appendChild(bar);
        info.appendChild(progress);
      }
      if (item.error) {
        const error = document.createElement('em');
        error.textContent = item.error;
        info.appendChild(error);
      }
      const actions = document.createElement('div');
      actions.className = 'knowledge-attachment-actions';
      if (item.status === 'ready') actions.appendChild(actionButton('插入正文', 'insert'));
      if (item.status === 'failed' && item.file) actions.appendChild(actionButton('重试', 'retry'));
      if (!['uploading'].includes(item.status)) actions.appendChild(actionButton('删除', 'delete', true));
      row.append(info, actions);
      state.list.appendChild(row);
    });
  }

  function actionButton(label, action, danger = false) {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.knowledgeAttachment = action;
    button.textContent = label;
    if (danger) button.className = 'is-danger';
    return button;
  }

  function statusLabel(item) {
    if (item.status === 'queued') return '等待上传';
    if (item.status === 'uploading') return `上传中 ${item.progress}%`;
    if (item.status === 'failed') return '上传失败';
    return item.attachment?.mime_type || '已上传';
  }

  function scan() {
    for (const state of [...states]) {
      if (!state.editor.isConnected) states.delete(state);
    }
    if (!isKnowledgePage()) return;
    document.querySelectorAll(EDITOR_SELECTOR).forEach(mountEditor);
  }

  window.__xboardKnowledgeAttachments = {
    appendDraftToken,
    createDraftToken,
    formatBytes,
    markdownFor,
    scan,
  };

  installRequestBridge();
  const observer = new MutationObserver(scan);
  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('hashchange', scan);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
  else scan();
}());
