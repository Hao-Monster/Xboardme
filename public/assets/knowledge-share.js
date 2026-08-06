(() => {
  'use strict';
  if (window.__xboardKnowledgeShareInstalled) return;
  window.__xboardKnowledgeShareInstalled = true;

  const articles = new Map();
  let scheduled = false;

  function collect(value) {
    if (!value || typeof value !== 'object') return;
    if (value.id != null && value.title && value.share_url) {
      articles.set(String(value.id), { id: String(value.id), title: String(value.title), shareUrl: String(value.share_url), show: value.show !== false && value.show !== 0 });
    }
    Object.values(value).forEach(collect);
  }

  function ingest(payload) {
    try { collect(typeof payload === 'string' ? JSON.parse(payload) : payload); scheduleEnhance(); } catch (_) {}
  }

  if (window.fetch) {
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const response = await nativeFetch(...args);
      const url = String(args[0]?.url || args[0] || '');
      if (url.includes('/knowledge/fetch')) response.clone().json().then(ingest).catch(() => {});
      return response;
    };
  }

  const nativeOpen = XMLHttpRequest.prototype.open;
  const nativeSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function(method, url, ...rest) { this.__knowledgeShareUrl = String(url || ''); return nativeOpen.call(this, method, url, ...rest); };
  XMLHttpRequest.prototype.send = function(...args) {
    if (this.__knowledgeShareUrl?.includes('/knowledge/fetch')) this.addEventListener('load', () => ingest(this.responseText), { once: true });
    return nativeSend.apply(this, args);
  };

  async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(value); return; }
    const input = document.createElement('textarea'); input.value = value; input.style.position = 'fixed'; input.style.opacity = '0'; document.body.appendChild(input); input.select(); document.execCommand('copy'); input.remove();
  }

  function button(article, extraClass = '') {
    const element = document.createElement('button'); element.type = 'button'; element.className = `knowledge-share-button ${extraClass}`.trim(); element.dataset.knowledgeShareId = article.id; element.textContent = '复制分享链接';
    element.addEventListener('click', async (event) => { event.preventDefault(); event.stopPropagation(); await copyText(article.shareUrl); element.textContent = '已复制'; element.classList.add('copied'); window.setTimeout(() => { element.textContent = '复制分享链接'; element.classList.remove('copied'); }, 1600); });
    return element;
  }

  function enhanceAdminList() {
    if (!window.location.hash.includes('/config/knowledge')) return;
    document.querySelectorAll('tr').forEach((row) => {
      if (row.querySelector('[data-knowledge-share-id]')) return;
      const cells = Array.from(row.querySelectorAll('td'));
      const idCell = cells.find((cell) => /^\s*\d+\s*$/.test(cell.textContent || ''));
      if (!idCell) return;
      const article = articles.get((idCell.textContent || '').trim());
      if (!article?.show) return;
      (cells[cells.length - 1] || row).appendChild(button(article));
    });
  }

  function findDialog(heading) {
    const explicit = heading.closest('[role="dialog"], .ant-modal-content, .semi-modal-content, .arco-modal');
    if (explicit) return explicit;
    let current = heading.parentElement;
    for (let depth = 0; current && depth < 7; depth += 1, current = current.parentElement) if ((current.textContent || '').includes('最后更新') && current.querySelector('button')) return current;
    return heading.parentElement;
  }

  function enhanceUserDetail() {
    if (!window.location.hash.includes('/knowledge') || document.documentElement.classList.contains('distributor-mode')) return;
    document.querySelectorAll('h1, h2, h3').forEach((heading) => {
      const title = (heading.textContent || '').trim();
      const article = Array.from(articles.values()).find((item) => item.show && item.title === title);
      if (!article) return;
      const dialog = findDialog(heading);
      if (!dialog || dialog.querySelector(`[data-knowledge-share-id="${article.id}"]`)) return;
      heading.insertAdjacentElement('afterend', button(article, 'knowledge-share-detail'));
    });
  }

  function enhance() { scheduled = false; enhanceAdminList(); enhanceUserDetail(); }
  function scheduleEnhance() { if (scheduled) return; scheduled = true; window.setTimeout(enhance, 50); }
  new MutationObserver(scheduleEnhance).observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('hashchange', scheduleEnhance);
  window.__xboardKnowledgeShare = {
    collect,
    ingest,
    enhanceAdminList,
    enhanceUserDetail,
    getArticle: (id) => articles.get(String(id)),
  };
})();
