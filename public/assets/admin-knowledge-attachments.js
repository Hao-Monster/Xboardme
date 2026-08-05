(function () {
  'use strict';

  if (window.__xboardKnowledgeAttachments) return;

  const TOKEN_KEY = 'XBOARD_ACCESS_TOKEN';
  const EDITOR_SELECTOR = '.rc-md-editor';
  const MAX_PARALLEL_UPLOADS = 2;
  const CHUNK_RETRIES = 3;
  const MARKER_PREFIX = 'xboard-knowledge-upload:';
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

  function uploadMarker(id) {
    return `<!-- ${MARKER_PREFIX}${id} -->`;
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
    return [...states].reverse().find((state) => isVisible(state.editor)) || null;
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

  function blockingItems(state) {
    if (!state) return [];
    return [...state.items.values()].filter((item) => (
      ['queued', 'uploading', 'cancelling', 'failed'].includes(item.status) &&
      (!item.marker || state.textarea.value.includes(item.marker))
    ));
  }

  function saveGuardError(state) {
    const blocked = blockingItems(state);
    if (!blocked.length) return null;
    const hasFailure = blocked.some((item) => item.status === 'failed');
    return new Error(hasFailure
      ? '存在上传失败的附件，请重试或取消后再提交。'
      : '附件仍在上传，请等待上传完成后再提交。');
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
        const current = activeState();
        const guardError = saveGuardError(current);
        if (guardError) {
          toast(guardError.message, 'error');
          throw guardError;
        }
        body = appendDraftToken(body, current?.draftToken);
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
        const current = activeState();
        const guardError = saveGuardError(current);
        if (guardError) {
          toast(guardError.message, 'error');
          return Promise.reject(guardError);
        }
        init = { ...init, body: appendDraftToken(init.body, current?.draftToken) };
      }
      return originalFetch(input, init).then((response) => {
        if (/\/knowledge\/fetch\?[^#]*\bid=\d+/.test(url)) {
          response.clone().text().then((text) => rememberKnowledgeDetail(url, text)).catch(() => {});
        }
        return response;
      });
    };
  }

  function installGlobalGuards() {
    document.addEventListener('click', (event) => {
      const state = activeState();
      const button = event.target.closest?.('button');
      const dialog = state?.editor.closest?.('[role="dialog"]');
      if (!state || !button || !dialog?.contains(button)) return;
      const isSubmit = button.type === 'submit' || /^(提交|确认|保存|Submit|Save)$/i.test(button.textContent.trim());
      if (!isSubmit) return;
      const error = saveGuardError(state);
      if (!error) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      toast(error.message, 'error');
    }, true);

    window.addEventListener('beforeunload', (event) => {
      if (![...states].some((state) => blockingItems(state).length)) return;
      event.preventDefault();
      event.returnValue = '';
    });
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

  function toolbarButton() {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button knowledge-attachment-trigger';
    button.dataset.knowledgeAttachment = 'choose';
    button.title = '添加附件';
    button.setAttribute('aria-label', '添加附件');
    button.innerHTML = `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M8.5 12.5 15 6a3.54 3.54 0 0 1 5 5l-8.5 8.5a5 5 0 0 1-7.07-7.07L13 3.86a2.5 2.5 0 0 1 3.54 3.54L8 15.93a1 1 0 0 1-1.41-1.42l7.78-7.78" />
      </svg>
      <span data-knowledge-attachment="badge" hidden>0</span>`;
    return button;
  }

  function mountEditor(editor) {
    if (editor.dataset.knowledgeAttachmentsMounted === '1') return;
    const textarea = editor.querySelector('textarea');
    const toolbar = editor.querySelector('.rc-md-navigation .button-wrap');
    if (!textarea || !toolbar) return;
    editor.dataset.knowledgeAttachmentsMounted = '1';

    const trigger = toolbarButton();
    toolbar.appendChild(trigger);
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.hidden = true;
    input.setAttribute('aria-label', '选择文章附件');
    editor.appendChild(input);

    const state = {
      editor,
      textarea,
      trigger,
      input,
      badge: trigger.querySelector('[data-knowledge-attachment="badge"]'),
      draftToken: createDraftToken(),
      knowledgeId: null,
      items: new Map(),
      internalEdit: false,
      dragDepth: 0,
      previewObserver: null,
    };
    states.add(state);
    bindEditor(state);
    installPreviewObserver(state);
    render(state);

    if (pendingKnowledgeId && Date.now() - pendingKnowledgeId.at < 3000) {
      assignKnowledge(state, pendingKnowledgeId.id);
      pendingKnowledgeId = null;
    }
  }

  function bindEditor(state) {
    state.trigger.addEventListener('click', (event) => {
      event.preventDefault();
      state.input.click();
    });
    state.input.addEventListener('change', () => {
      enqueueFiles(state, state.input.files);
      state.input.value = '';
    });
    state.textarea.addEventListener('paste', (event) => {
      const files = clipboardImages(event.clipboardData);
      if (!files.length) return;
      event.preventDefault();
      enqueueFiles(state, files, state.textarea.selectionEnd);
      toast(files.length > 1 ? `已粘贴 ${files.length} 张图片` : '图片已粘贴并开始上传');
    });
    state.textarea.addEventListener('input', () => {
      if (!state.internalEdit) synchronizeManualChanges(state);
      render(state);
      schedulePreview(state);
    });
    state.editor.addEventListener('dragenter', (event) => {
      if (!hasDraggedFiles(event.dataTransfer)) return;
      event.preventDefault();
      state.dragDepth += 1;
      state.editor.classList.add('is-attachment-dragging');
    });
    state.editor.addEventListener('dragover', (event) => {
      if (!hasDraggedFiles(event.dataTransfer)) return;
      event.preventDefault();
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
    });
    state.editor.addEventListener('dragleave', () => {
      state.dragDepth = Math.max(0, state.dragDepth - 1);
      if (!state.dragDepth) state.editor.classList.remove('is-attachment-dragging');
    });
    state.editor.addEventListener('drop', (event) => {
      if (!event.dataTransfer?.files?.length) return;
      event.preventDefault();
      state.dragDepth = 0;
      state.editor.classList.remove('is-attachment-dragging');
      enqueueFiles(state, event.dataTransfer.files, state.textarea.selectionEnd);
    });
    state.editor.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') state.input.value = '';
    });
  }

  function hasDraggedFiles(dataTransfer) {
    return Boolean(dataTransfer?.types && Array.from(dataTransfer.types).includes('Files'));
  }

  function clipboardImages(clipboardData) {
    const images = [];
    for (const item of Array.from(clipboardData?.items || [])) {
      if (item.kind !== 'file' || !String(item.type || '').startsWith('image/')) continue;
      const file = item.getAsFile?.();
      if (!file) continue;
      if (file.name && !/^image\.(png|jpe?g|gif|webp|bmp)$/i.test(file.name)) {
        images.push(file);
        continue;
      }
      const subtype = String(file.type).split('/')[1]?.replace('jpeg', 'jpg') || 'png';
      const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
      images.push(new File([file], `粘贴图片-${stamp}.${subtype}`, { type: file.type, lastModified: Date.now() }));
    }
    return images;
  }

  function enqueueFiles(state, fileList, insertionOffset = null) {
    const files = Array.from(fileList || []);
    if (!files.length) return;
    const items = files.map((file, index) => {
      const id = `local-${Date.now()}-${index}-${Math.random().toString(16).slice(2)}`;
      return {
        id,
        file,
        name: file.name || `附件-${index + 1}`,
        size: file.size,
        status: file.size > 0 ? 'queued' : 'failed',
        progress: 0,
        error: file.size > 0 ? '' : '不能上传空文件。',
        uploadUuid: null,
        attachment: null,
        marker: uploadMarker(id),
        controller: null,
        cancelled: false,
        undoIndex: null,
      };
    });
    items.forEach((item) => state.items.set(item.id, item));
    insertMarkers(state, items.map((item) => item.marker), insertionOffset);
    render(state);
    pumpQueue();
  }

  function insertMarkers(state, markers, insertionOffset = null) {
    const textarea = state.textarea;
    const value = textarea.value;
    const offset = Number.isInteger(insertionOffset)
      ? Math.max(0, Math.min(insertionOffset, value.length))
      : (Number.isInteger(textarea.selectionEnd) ? textarea.selectionEnd : value.length);
    const prefix = offset > 0 && value[offset - 1] !== '\n' ? '\n' : '';
    const suffix = offset < value.length && value[offset] !== '\n' ? '\n' : '';
    const inserted = `${prefix}${markers.join('\n')}${suffix}`;
    setEditorValue(state, value.slice(0, offset) + inserted + value.slice(offset));
    const caret = offset + inserted.length;
    textarea.focus();
    textarea.setSelectionRange(caret, caret);
  }

  function nextQueuedItem() {
    for (const state of states) {
      for (const item of state.items.values()) {
        if (item.status === 'queued' && !item.cancelled) return { state, item };
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
      next.item.controller = new AbortController();
      render(next.state);
      uploadItem(next.state, next.item)
        .catch((error) => {
          if (next.item.cancelled || error?.name === 'AbortError') return;
          next.item.status = 'failed';
          next.item.error = error.message || '上传失败。';
          render(next.state);
        })
        .finally(() => {
          next.item.controller = null;
          activeWorkers -= 1;
          pumpQueue();
        });
    }
  }

  async function uploadItem(state, item) {
    const signal = item.controller?.signal;
    let session;
    if (item.uploadUuid) {
      try {
        session = await request(`/knowledge/attachment/upload/${item.uploadUuid}`, { signal });
        if (session.attachment) return finishItem(state, item, session.attachment);
      } catch (error) {
        if (error?.name === 'AbortError') throw error;
        if (![404, 410].includes(error.status)) throw error;
        item.uploadUuid = null;
      }
    }
    if (!item.uploadUuid) {
      session = await request('/knowledge/attachment/upload/initialize', {
        method: 'POST',
        json: { original_name: item.name, size: item.size, draft_token: state.draftToken },
        signal,
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
          method: 'POST', body: form, signal,
        }), CHUNK_RETRIES);
      }
      item.progress = Math.round(((index + 1) / totalChunks) * 95);
      render(state);
    }

    const attachment = await request(`/knowledge/attachment/upload/${item.uploadUuid}/complete`, {
      method: 'POST', json: {}, signal,
    });
    return finishItem(state, item, attachment);
  }

  async function finishItem(state, item, attachment) {
    if (item.cancelled || !state.textarea.value.includes(item.marker)) {
      await discardDraft(state, attachment).catch(() => {});
      state.items.delete(item.id);
      render(state);
      return;
    }
    const currentKey = [...state.items.entries()].find(([, value]) => value === item)?.[0];
    const marker = item.marker;
    item.status = 'ready';
    item.progress = 100;
    item.error = '';
    item.attachment = attachment;
    item.id = attachment.uuid;
    item.marker = null;
    replaceText(state, marker, markdownFor(attachment));
    if (currentKey) state.items.delete(currentKey);
    state.items.set(attachment.uuid, item);
    render(state);
    ensurePreviewVisible(state);
    schedulePreview(state);
    toast(`${attachment.original_name} 已上传并插入正文`);
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
        if (error?.name === 'AbortError') throw error;
        if (attempt + 1 < attempts) await new Promise((resolve) => setTimeout(resolve, 500 * (2 ** attempt)));
      }
    }
    throw lastError;
  }

  function retryUpload(state, itemId) {
    const item = state.items.get(itemId);
    if (!item || item.status !== 'failed' || !item.file || item.cancelled) return;
    if (item.marker && !state.textarea.value.includes(item.marker)) insertMarkers(state, [item.marker]);
    item.status = 'queued';
    item.error = '';
    render(state);
    pumpQueue();
  }

  function setEditorValue(state, value) {
    const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set;
    state.internalEdit = true;
    if (setter) setter.call(state.textarea, value);
    else state.textarea.value = value;
    state.textarea.dispatchEvent(new Event('input', { bubbles: true }));
    state.textarea.dispatchEvent(new Event('change', { bubbles: true }));
    state.internalEdit = false;
  }

  function replaceText(state, search, replacement) {
    const index = state.textarea.value.indexOf(search);
    if (index < 0) return false;
    const value = state.textarea.value.slice(0, index) + replacement + state.textarea.value.slice(index + search.length);
    setEditorValue(state, value);
    const caret = index + replacement.length;
    state.textarea.focus();
    state.textarea.setSelectionRange(caret, caret);
    return true;
  }

  function normalizeBody(body) {
    return body.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trimEnd();
  }

  function removeAttachmentMarkup(body, attachment) {
    const placeholder = attachment?.placeholder;
    if (!placeholder || !body.includes(placeholder)) return { body, count: 0, index: body.length };
    const count = body.split(placeholder).length - 1;
    const index = body.indexOf(placeholder);
    let next = body.split(markdownFor(attachment)).join('');
    next = next.split(placeholder).join('');
    return { body: normalizeBody(next), count, index };
  }

  async function cancelUpload(state, itemId, ask = true) {
    const item = state.items.get(itemId);
    if (!item || item.cancelled) return;
    if (ask && !window.confirm(`确认取消附件“${item.name}”吗？`)) return;
    item.cancelled = true;
    item.status = 'cancelling';
    item.controller?.abort();
    render(state);
    try {
      if (item.uploadUuid) {
        try {
          await request(`/knowledge/attachment/upload/${item.uploadUuid}/cancel`, {
            method: 'POST', json: { draft_token: state.draftToken },
          });
        } catch (error) {
          if (error.status === 409) {
            await request('/knowledge/attachment/drop', {
              method: 'POST', json: { uuid: item.uploadUuid, draft_token: state.draftToken },
            }).catch((dropError) => {
              if (dropError.status !== 404) throw dropError;
            });
          } else if (error.status !== 404) throw error;
        }
      }
      if (item.marker && state.textarea.value.includes(item.marker)) replaceText(state, item.marker, '');
      state.items.delete(itemId);
      render(state);
      toast('附件上传已取消');
    } catch (error) {
      item.cancelled = false;
      item.status = 'failed';
      item.error = error.message || '取消上传失败。';
      render(state);
      toast(item.error, 'error');
    }
  }

  async function discardDraft(state, attachment) {
    return request('/knowledge/attachment/drop', {
      method: 'POST',
      json: { uuid: attachment.uuid, draft_token: state.draftToken },
    });
  }

  async function deleteAttachment(state, itemId) {
    const item = state.items.get(itemId);
    if (!item) return;
    if (['queued', 'uploading', 'failed', 'cancelling'].includes(item.status)) {
      await cancelUpload(state, itemId);
      return;
    }
    const attachment = item.attachment;
    if (!attachment) return;
    const removal = removeAttachmentMarkup(state.textarea.value, attachment);
    const referenceText = removal.count > 1 ? `（正文中共有 ${removal.count} 处引用）` : '';
    if (!window.confirm(`确认删除附件“${item.name}”吗？${referenceText}`)) return;

    if (!attachment.knowledge_id) {
      try {
        await discardDraft(state, attachment);
        setEditorValue(state, removal.body);
        state.items.delete(itemId);
        render(state);
        toast('错误附件已永久删除');
      } catch (error) {
        toast(error.message, 'error');
      }
      return;
    }

    item.status = 'pending-delete';
    item.undoIndex = removal.index;
    setEditorValue(state, removal.body);
    render(state);
    toast('附件已从正文移除，保存文章后删除；可用编辑器撤销操作恢复');
  }

  function undoDelete(state, itemId) {
    const item = state.items.get(itemId);
    if (!item?.attachment || item.status !== 'pending-delete') return;
    const value = state.textarea.value;
    const index = Math.max(0, Math.min(Number(item.undoIndex) || value.length, value.length));
    insertMarkers(state, [markdownFor(item.attachment)], index);
    item.status = 'ready';
    item.undoIndex = null;
    render(state);
    schedulePreview(state);
    toast('已撤销删除');
  }

  function synchronizeManualChanges(state) {
    for (const [itemId, item] of state.items.entries()) {
      if (item.marker && !state.textarea.value.includes(item.marker) && !item.cancelled) {
        cancelUpload(state, itemId, false);
        continue;
      }
      if (!item.attachment?.knowledge_id) continue;
      const referenced = state.textarea.value.includes(item.attachment.placeholder);
      if (!referenced && item.status === 'ready') {
        item.status = 'pending-delete';
        item.undoIndex = state.textarea.value.length;
      } else if (referenced && item.status === 'pending-delete') {
        item.status = 'ready';
        item.undoIndex = null;
      }
    }
  }

  async function loadAttachments(state) {
    const query = state.knowledgeId
      ? `knowledge_id=${state.knowledgeId}`
      : `draft_token=${state.draftToken}`;
    const result = await request(`/knowledge/attachment/fetch?${query}&per_page=100`);
    for (const attachment of result.items || []) {
      const referenced = state.textarea.value.includes(attachment.placeholder);
      state.items.set(attachment.uuid, {
        id: attachment.uuid,
        name: attachment.original_name,
        size: attachment.size,
        status: attachment.knowledge_id && !referenced ? 'pending-delete' : 'ready',
        progress: 100,
        error: '',
        file: null,
        uploadUuid: attachment.uuid,
        attachment,
        marker: null,
        controller: null,
        cancelled: false,
        undoIndex: null,
      });
    }
    render(state);
    schedulePreview(state);
  }

  function installPreviewObserver(state) {
    state.previewObserver = new MutationObserver(() => patchPreview(state));
    state.previewObserver.observe(state.editor, { childList: true, subtree: true });
    state.editor.addEventListener('click', (event) => {
      const button = event.target.closest?.('[data-knowledge-attachment-delete]');
      if (!button) return;
      event.preventDefault();
      event.stopPropagation();
      deleteAttachment(state, button.dataset.knowledgeAttachmentDelete);
    });
    schedulePreview(state);
  }

  function schedulePreview(state) {
    setTimeout(() => patchPreview(state), 0);
  }

  function patchPreview(state) {
    if (!state.editor.isConnected) return;
    const attachments = new Map();
    for (const item of state.items.values()) {
      if (item.attachment?.placeholder && item.attachment?.url) {
        attachments.set(item.attachment.placeholder, item.attachment);
      }
    }
    const preview = state.editor.querySelector('.sec-html');
    if (!preview || !attachments.size) return;
    preview.querySelectorAll('[src], [href], [data-knowledge-attachment-placeholder]').forEach((element) => {
      const original = element.dataset.knowledgeAttachmentPlaceholder
        || element.getAttribute('src')
        || element.getAttribute('href');
      const attachment = attachments.get(original);
      if (!attachment) return;
      const attribute = element.hasAttribute('src') ? 'src' : 'href';
      element.dataset.knowledgeAttachmentPlaceholder = attachment.placeholder;
      element.dataset.knowledgeAttachmentUuid = attachment.uuid;
      if (element.getAttribute(attribute) !== attachment.url) element.setAttribute(attribute, attachment.url);
      if (attribute === 'href') element.setAttribute('rel', 'noopener noreferrer');
      installPreviewDeleteButton(element, attachment);
    });
  }

  function installPreviewDeleteButton(element, attachment) {
    let wrapper = element.closest('.knowledge-attachment-preview');
    if (!wrapper) {
      wrapper = document.createElement('span');
      wrapper.className = 'knowledge-attachment-preview';
      element.parentNode?.insertBefore(wrapper, element);
      wrapper.appendChild(element);
    }
    wrapper.dataset.knowledgeAttachmentUuid = attachment.uuid;
    wrapper.dataset.knowledgeAttachmentPlaceholder = attachment.placeholder;
    if (wrapper.querySelector('[data-knowledge-attachment-delete]')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'knowledge-attachment-preview-delete';
    button.dataset.knowledgeAttachmentDelete = attachment.uuid;
    button.setAttribute('aria-label', `删除附件 ${attachment.original_name}`);
    button.textContent = '删除';
    wrapper.appendChild(button);
  }

  function ensurePreviewVisible(state) {
    const preview = state.editor.querySelector('.sec-html');
    if (!preview?.classList.contains('in-visible')) return;
    const splitButton = [...state.editor.querySelectorAll('.rc-md-navigation .button')]
      .find((button) => button.querySelector('.rmel-icon-view-split') || /预览|view/i.test(button.title || button.getAttribute('aria-label') || ''));
    splitButton?.click();
    setTimeout(() => patchPreview(state), 0);
  }

  function render(state) {
    const referenced = [...state.items.values()].filter((item) => (
      item.attachment?.placeholder && state.textarea.value.includes(item.attachment.placeholder)
    )).length;
    const active = [...state.items.values()].filter((item) => ['queued', 'uploading', 'failed', 'cancelling'].includes(item.status)).length;
    const badgeValue = referenced + active;
    state.badge.textContent = String(badgeValue);
    state.badge.hidden = badgeValue < 1;
    state.trigger.classList.toggle('has-active-upload', active > 0);
    const failed = [...state.items.values()].filter((item) => item.status === 'failed').length;
    state.trigger.title = failed
      ? `${failed} 个附件上传失败，请重新选择文件上传`
      : (active ? `${active} 个附件正在上传` : '添加附件');
    state.trigger.setAttribute('aria-label', state.trigger.title);
  }

  function scan() {
    for (const state of [...states]) {
      if (!state.editor.isConnected) {
        state.previewObserver?.disconnect();
        for (const item of state.items.values()) item.controller?.abort();
        states.delete(state);
        continue;
      }
      const toolbar = state.editor.querySelector('.rc-md-navigation .button-wrap');
      if (toolbar && !state.trigger.isConnected) toolbar.appendChild(state.trigger);
      if (!state.input.isConnected) state.editor.appendChild(state.input);
    }
    if (!isKnowledgePage()) return;
    document.querySelectorAll(EDITOR_SELECTOR).forEach(mountEditor);
  }

  window.__xboardKnowledgeAttachments = {
    appendDraftToken,
    blockingItems,
    clipboardImages,
    createDraftToken,
    formatBytes,
    markdownFor,
    removeAttachmentMarkup,
    scan,
    uploadMarker,
  };

  installRequestBridge();
  installGlobalGuards();
  const observer = new MutationObserver(scan);
  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('hashchange', scan);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
  else scan();
}());
