(function () {
  'use strict';

  if (window.__xboardKnowledgeRichText) return;

  const ATTACHMENT_PATTERN = /^knowledge-attachment:\/\/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  const SAFE_TAGS = new Set([
    'A', 'B', 'BLOCKQUOTE', 'BR', 'CODE', 'DIV', 'EM', 'H1', 'H2', 'H3',
    'H4', 'H5', 'H6', 'IMG', 'LI', 'OL', 'P', 'PRE', 'SPAN', 'STRONG',
    'UL', 'VIDEO',
  ]);
  const BLOCKED_TAGS = new Set(['EMBED', 'IFRAME', 'OBJECT', 'SCRIPT', 'STYLE', 'SVG']);

  function textOf(node) {
    return String(node?.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function attribute(node, name) {
    if (typeof node?.getAttribute === 'function') return node.getAttribute(name);
    return node?.attributes?.[name] ?? null;
  }

  function attachmentUrl(node, name) {
    const placeholder = attribute(node, 'data-knowledge-attachment-placeholder');
    return safeUrl(placeholder) || safeUrl(attribute(node, name));
  }

  function safeUrl(value) {
    const url = String(value || '').trim();
    if (!url) return '';
    if (ATTACHMENT_PATTERN.test(url)) return url;
    if (/^https?:\/\/[^\s]+$/i.test(url)) return url;
    if (/^\/(?!\/)[^\s]*$/.test(url)) return url;
    return '';
  }

  function safeHttpUrl(value) {
    const url = safeUrl(value);
    return /^https?:\/\//i.test(url) ? url : '';
  }

  function escapeInline(value) {
    return String(value || '')
      .replace(/\\/g, '\\\\')
      .replace(/([`*_[\]])/g, '\\$1');
  }

  function normalizeMarkdown(value) {
    return String(value || '')
      .replace(/\u00a0/g, ' ')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  function documentSourceReady(textareaValue, previewHtml, body, previewHtmlBefore = '', sourceWasReady = false) {
    const expected = String(body || '');
    const current = String(textareaValue || '');
    const preview = String(previewHtml || '');
    if (current !== expected) return false;
    if (!expected.trim()) return true;
    if (!preview.trim()) return false;
    return sourceWasReady || preview !== String(previewHtmlBefore || '');
  }

  function childNodes(node) {
    return Array.from(node?.childNodes || []);
  }

  function serializeChildren(node, context) {
    return childNodes(node).map((child) => serializeNode(child, context)).join('');
  }

  function listItem(node, context) {
    const marker = context.ordered ? `${context.index + 1}. ` : '- ';
    const body = normalizeMarkdown(serializeChildren(node, { ...context, inListItem: true }))
      .replace(/\n/g, '\n  ');
    return `${marker}${body}\n`;
  }

  function serializeNode(node, context = {}) {
    if (!node) return '';
    if (node.nodeType === 3) return escapeInline(node.nodeValue ?? node.textContent ?? '');
    if (node.nodeType !== 1) return '';

    const tag = String(node.tagName || node.nodeName || '').toUpperCase();
    if (attribute(node, 'data-rich-editor-ui') === '1') return '';
    const uploadMarker = attribute(node, 'data-knowledge-upload-marker');
    if (uploadMarker) return `<!-- ${uploadMarker} -->`;
    const content = () => serializeChildren(node, context);
    if (!SAFE_TAGS.has(tag)) return content();

    if (/^H[1-6]$/.test(tag)) return `${'#'.repeat(Number(tag[1]))} ${normalizeMarkdown(content())}\n\n`;
    if (tag === 'P' || tag === 'DIV') return `${normalizeMarkdown(content())}\n\n`;
    if (tag === 'BR') return '\n';
    if (tag === 'STRONG' || tag === 'B') return `**${content()}**`;
    if (tag === 'EM') return `*${content()}*`;
    if (tag === 'CODE' && context.inPre) return String(node.textContent || '');
    if (tag === 'CODE') return `\`${String(node.textContent || '').replace(/`/g, '\\`')}\``;
    if (tag === 'PRE') return `\`\`\`\n${serializeChildren(node, { ...context, inPre: true }).trimEnd()}\n\`\`\`\n\n`;
    if (tag === 'BLOCKQUOTE') {
      const quote = normalizeMarkdown(content()).split('\n').map((line) => `> ${line}`).join('\n');
      return `${quote}\n\n`;
    }
    if (tag === 'A') {
      const href = attachmentUrl(node, 'href');
      const label = escapeInline(textOf(node) || attribute(node, 'download') || href);
      return href ? `[${label}](${href})` : label;
    }
    if (tag === 'IMG') {
      const src = attachmentUrl(node, 'src');
      const alt = escapeInline(attribute(node, 'alt') || '图片');
      return src ? `![${alt}](${src})` : '';
    }
    if (tag === 'VIDEO') {
      const src = attachmentUrl(node, 'src');
      return src ? `<video controls preload="metadata" src="${src}"></video>\n\n` : '';
    }
    if (tag === 'UL' || tag === 'OL') {
      const ordered = tag === 'OL';
      return `${childNodes(node).filter((child) => String(child.tagName || '').toUpperCase() === 'LI')
        .map((child, index) => listItem(child, { ...context, ordered, index }))
        .join('')}\n`;
    }
    if (tag === 'LI') return listItem(node, { ...context, ordered: false, index: 0 });
    return content();
  }

  function domToMarkdown(root) {
    return normalizeMarkdown(serializeChildren(root, {}));
  }

  function copySafeAttributes(source, target, tag) {
    const placeholder = safeUrl(source.getAttribute('data-knowledge-attachment-placeholder'));
    if (placeholder) target.setAttribute('data-knowledge-attachment-placeholder', placeholder);
    if (tag === 'A') {
      const href = safeUrl(source.getAttribute('href'));
      if (href) target.setAttribute('href', href);
      target.setAttribute('rel', 'noopener noreferrer');
    }
    if (tag === 'IMG') {
      const src = safeUrl(source.getAttribute('src'));
      if (src) target.setAttribute('src', src);
      target.setAttribute('alt', source.getAttribute('alt') || '图片');
    }
    if (tag === 'VIDEO') {
      const src = safeUrl(source.getAttribute('src'));
      if (src) target.setAttribute('src', src);
      target.setAttribute('controls', '');
      target.setAttribute('preload', 'metadata');
    }
    const uuid = source.getAttribute('data-knowledge-attachment-uuid');
    if (/^[0-9a-f-]{36}$/i.test(uuid || '')) target.setAttribute('data-knowledge-attachment-uuid', uuid);
    const marker = source.getAttribute('data-knowledge-upload-marker');
    if (/^xboard-knowledge-upload:local-[a-z0-9-]+$/i.test(marker || '')) {
      target.setAttribute('data-knowledge-upload-marker', marker);
    }
  }

  function sanitizeNode(source, documentRef) {
    if (source.nodeType === 3) return documentRef.createTextNode(source.nodeValue || '');
    if (source.nodeType !== 1) return null;
    const tag = String(source.tagName || '').toUpperCase();
    if (BLOCKED_TAGS.has(tag)) return null;
    const container = SAFE_TAGS.has(tag)
      ? documentRef.createElement(tag.toLowerCase())
      : documentRef.createDocumentFragment();
    if (SAFE_TAGS.has(tag)) copySafeAttributes(source, container, tag);
    childNodes(source).forEach((child) => {
      const clean = sanitizeNode(child, documentRef);
      if (clean) container.appendChild(clean);
    });
    if (['A', 'IMG', 'VIDEO'].includes(tag)) {
      const urlAttribute = tag === 'A' ? 'href' : 'src';
      if (!container.getAttribute(urlAttribute)) {
        return tag === 'A' ? documentRef.createTextNode(source.textContent || '') : null;
      }
    }
    return container;
  }

  function sanitizeFragment(root, documentRef = document) {
    const fragment = documentRef.createDocumentFragment();
    childNodes(root).forEach((child) => {
      const clean = sanitizeNode(child, documentRef);
      if (clean) fragment.appendChild(clean);
    });
    return fragment;
  }

  window.__xboardKnowledgeRichText = {
    attachmentPattern: ATTACHMENT_PATTERN,
    documentSourceReady,
    domToMarkdown,
    normalizeMarkdown,
    safeHttpUrl,
    safeUrl,
    sanitizeFragment,
    serializeNode,
  };

  if (typeof document === 'undefined' || typeof MutationObserver === 'undefined') return;

  const EDITOR_SELECTOR = '.rc-md-editor';
  const CLIPBOARD_TYPE = 'application/x-xboard-knowledge';
  const mounted = new Set();
  const pendingDocuments = new WeakMap();

  function attachmentApi() {
    return window.__xboardKnowledgeAttachments || null;
  }

  function nativeSet(textarea, value) {
    const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set;
    if (setter) setter.call(textarea, value);
    else textarea.value = value;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function sync(state) {
    if (state.syncing) return;
    state.syncing = true;
    nativeSet(state.textarea, domToMarkdown(state.surface));
    state.syncing = false;
  }

  function replaceSurfaceFromPreview(state) {
    state.surface.innerHTML = state.preview.innerHTML || '<p><br></p>';
    state.surface.querySelectorAll('[data-knowledge-attachment-delete], [data-rich-editor-ui="1"]')
      .forEach((node) => node.remove());
    hydratePlaceholders(state);
    decorateAll(state);
  }

  function applyPendingDocument(state) {
    const pending = state.pendingDocument;
    if (!pending) return true;
    if (!documentSourceReady(
      state.textarea.value,
      state.preview.innerHTML,
      pending.body,
      pending.previewHtmlBefore,
      pending.sourceWasReady
    )) return false;

    replaceSurfaceFromPreview(state);
    state.documentId = pending.id;
    state.sourceBody = pending.body;
    state.pendingDocument = null;
    return true;
  }

  function scheduleDocumentApply(state) {
    const token = state.documentToken;
    clearTimeout(state.documentTimer);
    const attempt = () => {
      if (!state.editor.isConnected || token !== state.documentToken || !state.pendingDocument) return;
      if (applyPendingDocument(state)) return;
      state.documentAttempts += 1;
      if (state.documentAttempts >= 100) {
        state.pendingDocument = null;
        attachmentApi()?.toast?.('文章正文加载超时，请关闭编辑窗口后重试。', 'error');
        return;
      }
      state.documentTimer = setTimeout(attempt, state.documentAttempts < 10 ? 16 : 50);
    };
    state.documentTimer = setTimeout(attempt, 0);
  }

  function stageDocument(state, detail) {
    const body = String(detail?.body || '');
    const previewHtmlBefore = state.preview.innerHTML;
    const sourceWasReady = state.textarea.value === body;
    state.documentToken += 1;
    state.documentAttempts = 0;
    state.pendingDocument = {
      id: Number(detail?.id) || null,
      body,
      previewHtmlBefore,
      sourceWasReady,
    };
    if (!applyPendingDocument(state)) {
      state.surface.innerHTML = '<p><br></p>';
      scheduleDocumentApply(state);
    }
  }

  function button(label, title, action, className = '') {
    const item = document.createElement('button');
    item.type = 'button';
    item.className = `knowledge-rich-tool ${className}`.trim();
    item.textContent = label;
    item.title = title;
    item.setAttribute('aria-label', title);
    item.addEventListener('mousedown', (event) => event.preventDefault());
    item.addEventListener('click', action);
    return item;
  }

  function headingControl(state) {
    const select = document.createElement('select');
    select.className = 'knowledge-rich-heading';
    select.setAttribute('aria-label', '标题级别');
    [['P', '正文'], ['H1', '标题 1'], ['H2', '标题 2'], ['H3', '标题 3']]
      .forEach(([value, label]) => select.add(new Option(label, value)));
    select.addEventListener('change', () => {
      state.surface.focus();
      document.execCommand('formatBlock', false, select.value);
      select.value = 'P';
      sync(state);
    });
    return select;
  }

  function createToolbar(state) {
    const toolbar = document.createElement('div');
    toolbar.className = 'knowledge-rich-toolbar';
    toolbar.appendChild(headingControl(state));
    toolbar.appendChild(button('链接', '插入链接', () => {
      const href = window.prompt('请输入链接地址（https://）');
      const url = safeUrl(href);
      if (!url) return;
      state.surface.focus();
      document.execCommand('createLink', false, url);
      sync(state);
    }));
    toolbar.appendChild(button('二维码', '将链接生成为二维码图片', () => openQrDialog(state)));
    toolbar.appendChild(button('图片', '上传图片', () => attachmentApi()?.chooseFiles?.('image')));
    toolbar.appendChild(button('视频', '上传视频', () => attachmentApi()?.chooseFiles?.('video')));
    toolbar.appendChild(button('📎', '上传任意附件', () => attachmentApi()?.chooseFiles?.('file'), 'knowledge-rich-paperclip'));
    return toolbar;
  }

  function qrPngFile(svg) {
    return new Promise((resolve, reject) => {
      const source = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));
      const image = new Image();
      const release = () => URL.revokeObjectURL(source);
      image.onload = () => {
        const canvas = document.createElement('canvas');
        canvas.width = 512;
        canvas.height = 512;
        const context = canvas.getContext('2d');
        if (!context) {
          release();
          reject(new Error('当前浏览器无法生成二维码图片。'));
          return;
        }
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
          release();
          if (!blob) {
            reject(new Error('二维码图片生成失败。'));
            return;
          }
          const timestamp = new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);
          resolve(new File([blob], `链接二维码-${timestamp}.png`, { type: 'image/png' }));
        }, 'image/png');
      };
      image.onerror = () => {
        release();
        reject(new Error('二维码预览加载失败。'));
      };
      image.src = source;
    });
  }

  function openQrDialog(state) {
    const savedRange = rangeInside(state);
    const dialogHost = state.editor.closest?.(
      '[role="dialog"], [data-radix-dialog-content], .n-modal, [data-scope="dialog"][data-part="content"]'
    ) || document.body;
    const overlay = document.createElement('div');
    overlay.className = 'knowledge-rich-qr-overlay';
    overlay.dataset.richEditorUi = '1';
    const dialog = document.createElement('section');
    dialog.className = 'knowledge-rich-qr-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'knowledge-rich-qr-title');

    const heading = document.createElement('h3');
    heading.id = 'knowledge-rich-qr-title';
    heading.textContent = '插入二维码';
    const description = document.createElement('p');
    description.className = 'knowledge-rich-qr-description';
    description.textContent = '输入链接并确认预览，二维码将作为文章图片上传到服务器。';
    const label = document.createElement('label');
    label.className = 'knowledge-rich-qr-label';
    label.textContent = '链接地址';
    const input = document.createElement('input');
    input.className = 'knowledge-rich-qr-input';
    input.type = 'url';
    input.inputMode = 'url';
    input.autocomplete = 'url';
    input.placeholder = 'https://example.com';
    label.appendChild(input);
    const hint = document.createElement('small');
    hint.className = 'knowledge-rich-qr-hint';
    hint.textContent = '仅支持 http:// 或 https:// 链接，最长 2048 个字符。';
    const error = document.createElement('div');
    error.className = 'knowledge-rich-qr-error';
    error.setAttribute('role', 'alert');
    const preview = document.createElement('div');
    preview.className = 'knowledge-rich-qr-preview';
    preview.textContent = '二维码预览将在这里显示';
    const actions = document.createElement('div');
    actions.className = 'knowledge-rich-qr-actions';
    const cancel = button('取消', '取消插入二维码', () => close());
    const generate = button('生成预览', '生成二维码预览', () => generatePreview());
    const insert = button('插入二维码', '上传并插入二维码图片', () => insertQr(), 'is-primary');
    insert.disabled = true;
    actions.append(cancel, generate, insert);
    dialog.append(heading, description, label, hint, error, preview, actions);
    overlay.appendChild(dialog);
    // The admin knowledge form already lives inside a focus-trapped modal.
    // Mount this nested dialog inside that scope so its input can retain focus.
    dialogHost.appendChild(overlay);

    let svg = '';
    let previewUrl = '';
    let generating = false;
    let inserting = false;

    function resetPreview() {
      svg = '';
      insert.disabled = true;
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = '';
      preview.replaceChildren('二维码预览将在这里显示');
    }

    function close() {
      if (generating || inserting) return;
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      document.removeEventListener('keydown', onKeydown);
      overlay.remove();
      state.surface.focus();
    }

    async function generatePreview() {
      const url = safeHttpUrl(input.value);
      error.textContent = '';
      if (!url || url.length > 2048) {
        resetPreview();
        error.textContent = '请输入有效的 http:// 或 https:// 链接。';
        input.focus();
        return;
      }
      generating = true;
      generate.disabled = true;
      insert.disabled = true;
      generate.textContent = '生成中…';
      try {
        const result = await attachmentApi()?.generateQrCode?.(url);
        if (!result?.svg) throw new Error('服务器没有返回二维码。');
        resetPreview();
        svg = result.svg;
        previewUrl = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));
        const image = document.createElement('img');
        image.src = previewUrl;
        image.alt = '链接二维码预览';
        preview.replaceChildren(image);
        insert.disabled = false;
      } catch (exception) {
        resetPreview();
        error.textContent = exception?.message || '二维码生成失败，请稍后重试。';
      } finally {
        generating = false;
        generate.disabled = false;
        generate.textContent = '生成预览';
      }
    }

    async function insertQr() {
      if (!svg || inserting) return;
      inserting = true;
      insert.disabled = true;
      generate.disabled = true;
      insert.textContent = '正在插入…';
      error.textContent = '';
      try {
        const file = await qrPngFile(svg);
        uploadFiles(state, [file], savedRange);
        inserting = false;
        generating = false;
        close();
      } catch (exception) {
        inserting = false;
        insert.disabled = false;
        generate.disabled = false;
        insert.textContent = '插入二维码';
        error.textContent = exception?.message || '二维码插入失败，请稍后重试。';
      }
    }

    function onKeydown(event) {
      if (event.key === 'Escape') close();
    }

    input.addEventListener('input', () => {
      error.textContent = '';
      resetPreview();
    });
    input.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      generatePreview();
    });
    overlay.addEventListener('mousedown', (event) => {
      if (event.target === overlay) close();
    });
    document.addEventListener('keydown', onKeydown);
    input.focus();
  }

  function rangeInside(state) {
    const selection = window.getSelection?.();
    if (!selection?.rangeCount) return null;
    const range = selection.getRangeAt(0);
    return state.surface.contains(range.commonAncestorContainer) ? range.cloneRange() : null;
  }

  function insertNodeAt(state, node, range = null) {
    const target = range || rangeInside(state);
    if (!target) {
      state.surface.appendChild(node);
      return;
    }
    target.deleteContents();
    const lastInserted = node.nodeType === 11 ? node.lastChild : node;
    target.insertNode(node);
    if (lastInserted?.parentNode) target.setStartAfter(lastInserted);
    else target.selectNodeContents(state.surface);
    target.collapse(true);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(target);
  }

  function attachmentElement(attachment) {
    let media;
    if (attachment.disposition === 'inline' && String(attachment.mime_type).startsWith('image/')) {
      media = document.createElement('img');
      media.alt = attachment.original_name || '图片';
      media.src = attachment.url;
    } else if (attachment.disposition === 'inline' && String(attachment.mime_type).startsWith('video/')) {
      media = document.createElement('video');
      media.controls = true;
      media.preload = 'metadata';
      media.src = attachment.url;
    } else {
      media = document.createElement('a');
      media.href = attachment.url;
      media.textContent = attachment.original_name || '下载附件';
      media.rel = 'noopener noreferrer';
    }
    media.dataset.knowledgeAttachmentUuid = attachment.uuid;
    media.dataset.knowledgeAttachmentPlaceholder = attachment.placeholder;
    return media;
  }

  function applyAttachment(state, attachment) {
    const selector = `[data-knowledge-attachment-placeholder="${CSS.escape(attachment.placeholder)}"],`
      + `[src="${CSS.escape(attachment.placeholder)}"],[href="${CSS.escape(attachment.placeholder)}"]`;
    state.surface.querySelectorAll(selector).forEach((media) => {
      media.dataset.knowledgeAttachmentUuid = attachment.uuid;
      media.dataset.knowledgeAttachmentPlaceholder = attachment.placeholder;
      if (media.hasAttribute('src')) media.setAttribute('src', attachment.url);
      if (media.hasAttribute('href')) media.setAttribute('href', attachment.url);
      decorateAttachment(state, media);
    });
  }

  function hydratePlaceholders(state) {
    state.surface.querySelectorAll('[src], [href]').forEach((media) => {
      const value = media.getAttribute(media.hasAttribute('src') ? 'src' : 'href');
      if (!ATTACHMENT_PATTERN.test(value || '')) return;
      media.dataset.knowledgeAttachmentPlaceholder = value;
      media.dataset.knowledgeAttachmentUuid = value.slice('knowledge-attachment://'.length);
    });
    (attachmentApi()?.attachments?.() || []).forEach((attachment) => applyAttachment(state, attachment));
  }

  function decorateAttachment(state, media) {
    if (!media?.dataset?.knowledgeAttachmentUuid) return;
    let wrapper = media.closest('.knowledge-rich-attachment');
    if (!wrapper) {
      wrapper = document.createElement('span');
      wrapper.className = 'knowledge-rich-attachment';
      wrapper.contentEditable = 'false';
      media.parentNode?.insertBefore(wrapper, media);
      wrapper.appendChild(media);
    }
    if (wrapper.querySelector('[data-rich-editor-ui="1"]')) return;
    const remove = button('删除', `删除附件 ${media.getAttribute('alt') || media.textContent || ''}`, async () => {
      const removed = await attachmentApi()?.deleteAttachment?.(media.dataset.knowledgeAttachmentUuid);
      if (removed === false) return;
      wrapper.remove();
      sync(state);
    }, 'knowledge-rich-delete');
    remove.dataset.richEditorUi = '1';
    wrapper.appendChild(remove);
  }

  function decorateAll(state) {
    state.surface.querySelectorAll('[data-knowledge-attachment-uuid]').forEach((media) => decorateAttachment(state, media));
  }

  function insertHtml(state, html, range = null) {
    const template = document.createElement('template');
    template.innerHTML = String(html || '');
    const fragment = sanitizeFragment(template.content, document);
    const marker = document.createElement('span');
    marker.dataset.richEditorUi = '1';
    insertNodeAt(state, marker, range);
    marker.replaceWith(fragment);
    decorateAll(state);
    sync(state);
  }

  function insertUploadMarkers(state, items, range) {
    const fragment = document.createDocumentFragment();
    items.forEach((item) => {
      const marker = document.createElement('span');
      marker.className = 'knowledge-rich-uploading';
      marker.dataset.knowledgeUploadMarker = item.marker.replace(/^<!--\s*|\s*-->$/g, '').trim();
      marker.textContent = `正在上传 ${item.name}…`;
      marker.contentEditable = 'false';
      fragment.appendChild(marker);
    });
    insertNodeAt(state, fragment, range);
    sync(state);
  }

  function uploadFiles(state, files, insertionRange = rangeInside(state)) {
    const list = Array.from(files || []);
    if (!list.length) return;
    const items = attachmentApi()?.enqueueFiles?.(list) || [];
    if (items.length) insertUploadMarkers(state, items, insertionRange);
  }

  function selectedHtml(state) {
    const range = rangeInside(state);
    if (!range || range.collapsed) return '';
    const container = document.createElement('div');
    container.appendChild(range.cloneContents());
    container.querySelectorAll('[data-rich-editor-ui="1"]').forEach((node) => node.remove());
    return container.innerHTML;
  }

  function attachmentUuids(root) {
    return [...new Set(Array.from(root.querySelectorAll?.('[data-knowledge-attachment-uuid]') || [])
      .map((node) => node.dataset.knowledgeAttachmentUuid)
      .filter(Boolean))];
  }

  function bindClipboard(state) {
    state.surface.addEventListener('copy', (event) => {
      const html = selectedHtml(state);
      if (!html || !event.clipboardData) return;
      const holder = document.createElement('div');
      holder.innerHTML = html;
      const context = attachmentApi()?.context?.() || {};
      const transport = document.createElement('div');
      if (context.knowledgeId) transport.dataset.xboardKnowledgeSourceId = String(context.knowledgeId);
      transport.innerHTML = html;
      event.preventDefault();
      event.clipboardData.setData('text/html', transport.outerHTML);
      event.clipboardData.setData('text/plain', window.getSelection()?.toString() || '');
      event.clipboardData.setData(CLIPBOARD_TYPE, JSON.stringify({
        sourceKnowledgeId: context.knowledgeId || null,
        attachmentUuids: attachmentUuids(holder),
        html,
      }));
    });

    state.surface.addEventListener('paste', async (event) => {
      const imageFiles = attachmentApi()?.clipboardImages?.(event.clipboardData) || [];
      if (imageFiles.length) {
        event.preventDefault();
        event.stopPropagation();
        uploadFiles(state, imageFiles);
        return;
      }
      const encoded = event.clipboardData?.getData(CLIPBOARD_TYPE);
      const range = rangeInside(state);
      if (encoded) {
        event.preventDefault();
        event.stopPropagation();
        try {
          const payload = JSON.parse(encoded);
          const context = attachmentApi()?.context?.() || {};
          let html = payload.html || '';
          if (
            payload.attachmentUuids?.length
            && payload.sourceKnowledgeId
            && Number(payload.sourceKnowledgeId) !== Number(context.knowledgeId)
          ) {
            const clones = await attachmentApi().cloneAttachments(
              payload.sourceKnowledgeId,
              payload.attachmentUuids
            );
            const map = new Map(clones.map((item) => [item.source_uuid, item.attachment]));
            const holder = document.createElement('div');
            holder.innerHTML = html;
            holder.querySelectorAll('[data-knowledge-attachment-uuid]').forEach((node) => {
              const clone = map.get(node.dataset.knowledgeAttachmentUuid);
              if (!clone) return;
              const replacement = attachmentElement(clone);
              node.replaceWith(replacement);
            });
            html = holder.innerHTML;
          }
          insertHtml(state, html, range);
        } catch (error) {
          attachmentApi()?.toast?.(error.message || '粘贴附件失败。', 'error');
        }
        return;
      }
      const html = event.clipboardData?.getData('text/html');
      if (html) {
        event.preventDefault();
        event.stopPropagation();
        try {
          const holder = document.createElement('div');
          holder.innerHTML = html;
          const transport = holder.querySelector('[data-xboard-knowledge-source-id]');
          const sourceKnowledgeId = Number(transport?.dataset.xboardKnowledgeSourceId || 0);
          const context = attachmentApi()?.context?.() || {};
          const uuids = attachmentUuids(holder);
          if (sourceKnowledgeId && uuids.length && sourceKnowledgeId !== Number(context.knowledgeId)) {
            const clones = await attachmentApi().cloneAttachments(sourceKnowledgeId, uuids);
            const map = new Map(clones.map((item) => [item.source_uuid, item.attachment]));
            holder.querySelectorAll('[data-knowledge-attachment-uuid]').forEach((node) => {
              const clone = map.get(node.dataset.knowledgeAttachmentUuid);
              if (clone) node.replaceWith(attachmentElement(clone));
            });
          }
          const cleanHtml = transport ? transport.innerHTML : holder.innerHTML;
          insertHtml(state, cleanHtml, range);
        } catch (error) {
          attachmentApi()?.toast?.(error.message || '粘贴内容失败。', 'error');
        }
      }
    });
  }

  function bindUploads(state) {
    ['dragenter', 'dragover'].forEach((name) => state.surface.addEventListener(name, (event) => {
      if (!event.dataTransfer?.types?.includes('Files')) return;
      event.preventDefault();
      event.stopPropagation();
      state.shell.classList.add('is-dragging');
    }));
    state.surface.addEventListener('dragleave', () => state.shell.classList.remove('is-dragging'));
    state.surface.addEventListener('drop', (event) => {
      if (!event.dataTransfer?.files?.length) return;
      event.preventDefault();
      event.stopPropagation();
      state.shell.classList.remove('is-dragging');
      uploadFiles(state, event.dataTransfer.files);
    });
  }

  function mount(editor) {
    if (editor.dataset.knowledgeRichMounted === '1') return;
    const textarea = editor.querySelector('textarea');
    const preview = editor.querySelector('.sec-html');
    if (!textarea || !preview) return;

    editor.dataset.knowledgeRichMounted = '1';
    editor.classList.add('knowledge-rich-mounted');
    const shell = document.createElement('div');
    shell.className = 'knowledge-rich-shell';
    const surface = document.createElement('div');
    surface.className = 'knowledge-rich-surface';
    surface.contentEditable = 'true';
    surface.setAttribute('role', 'textbox');
    surface.setAttribute('aria-multiline', 'true');
    surface.setAttribute('aria-label', '知识文章正文');
    surface.innerHTML = preview.innerHTML || '<p><br></p>';
    surface.querySelectorAll('[data-knowledge-attachment-delete], [data-rich-editor-ui="1"]').forEach((node) => node.remove());
    const state = {
      editor,
      textarea,
      preview,
      shell,
      surface,
      syncing: false,
      documentId: null,
      sourceBody: '',
      pendingDocument: null,
      documentToken: 0,
      documentAttempts: 0,
      documentTimer: null,
      sourceObserver: null,
    };
    shell.appendChild(createToolbar(state));
    shell.appendChild(surface);
    editor.appendChild(shell);
    mounted.add(state);
    hydratePlaceholders(state);
    decorateAll(state);
    surface.addEventListener('input', () => sync(state));
    bindUploads(state);
    bindClipboard(state);
    state.sourceObserver = new MutationObserver(() => {
      if (state.pendingDocument && applyPendingDocument(state)) clearTimeout(state.documentTimer);
    });
    state.sourceObserver.observe(editor, { childList: true, subtree: true, characterData: true });

    const pending = pendingDocuments.get(editor);
    if (pending) {
      pendingDocuments.delete(editor);
      stageDocument(state, pending);
    }
  }

  function stateFor(editor) {
    return [...mounted].find((state) => state.editor === editor) || null;
  }

  function scan() {
    for (const state of [...mounted]) {
      const textarea = state.editor.querySelector('textarea');
      const preview = state.editor.querySelector('.sec-html');
      const replaced = state.editor.isConnected && (textarea !== state.textarea || preview !== state.preview);
      if (!state.editor.isConnected || replaced) {
        const detail = state.pendingDocument || (state.documentId ? {
          id: state.documentId,
          body: state.sourceBody,
        } : null);
        if (replaced && detail) pendingDocuments.set(state.editor, detail);
        clearTimeout(state.documentTimer);
        state.sourceObserver?.disconnect();
        state.shell.remove();
        delete state.editor.dataset.knowledgeRichMounted;
        mounted.delete(state);
      }
    }
    if (!/^#\/config\/knowledge(?:[/?]|$)/.test(location.hash || '')) return;
    document.querySelectorAll(EDITOR_SELECTOR).forEach(mount);
  }

  window.__xboardKnowledgeRichEditor = {
    documentAvailable(editor, detail) {
      const state = stateFor(editor);
      if (!state) {
        pendingDocuments.set(editor, detail);
        return false;
      }
      stageDocument(state, detail);
      return true;
    },
    attachmentAvailable(editor, attachment) {
      const state = stateFor(editor);
      if (!state) return false;
      applyAttachment(state, attachment);
      return true;
    },
    attachmentReady(editor, attachment, marker) {
      const state = stateFor(editor);
      if (!state) return false;
      const key = String(marker || '').replace(/^<!--\s*|\s*-->$/g, '').trim();
      const target = state.surface.querySelector(`[data-knowledge-upload-marker="${CSS.escape(key)}"]`);
      const media = attachmentElement(attachment);
      if (target) {
        const enclosingLink = target.closest('a');
        target.replaceWith(media);
        // A file picker can be opened while the caret is inside an existing
        // link. Never leave the uploaded attachment nested in that anchor.
        if (enclosingLink && enclosingLink !== media) enclosingLink.after(media);
      } else {
        state.surface.appendChild(media);
      }
      decorateAttachment(state, media);
      sync(state);
      return true;
    },
    attachmentFailed(editor, marker, itemId, message) {
      const state = stateFor(editor);
      if (!state) return false;
      const key = String(marker || '').replace(/^<!--\s*|\s*-->$/g, '').trim();
      const target = state.surface.querySelector(`[data-knowledge-upload-marker="${CSS.escape(key)}"]`);
      if (!target) return false;
      target.classList.add('is-failed');
      target.textContent = `上传失败：${message}（点击重试）`;
      target.tabIndex = 0;
      target.setAttribute('role', 'button');
      const retry = () => {
        target.classList.remove('is-failed');
        target.textContent = '正在重试上传…';
        target.removeAttribute('role');
        target.removeAttribute('tabindex');
        attachmentApi()?.retryUpload?.(itemId);
      };
      target.onclick = retry;
      target.onkeydown = (event) => {
        if (event.key === 'Enter' || event.key === ' ') retry();
      };
      return true;
    },
    attachmentCancelled(editor, marker) {
      const state = stateFor(editor);
      if (!state) return false;
      const key = String(marker || '').replace(/^<!--\s*|\s*-->$/g, '').trim();
      state.surface.querySelector(`[data-knowledge-upload-marker="${CSS.escape(key)}"]`)?.remove();
      sync(state);
      return true;
    },
    scan,
  };

  const observer = new MutationObserver(scan);
  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('hashchange', scan);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
  else scan();
}());
