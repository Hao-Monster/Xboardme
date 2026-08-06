(() => {
  'use strict';

  const layout = document.querySelector('.public-knowledge-layout');
  const article = document.querySelector('[data-public-article]');
  const body = document.querySelector('[data-article-body]');
  const title = document.querySelector('[data-article-title]');
  const category = document.querySelector('[data-article-category]');
  const updated = document.querySelector('[data-article-updated]');
  const tocList = document.querySelector('[data-toc-list]');
  const canonical = document.querySelector('[data-public-canonical]');
  const copyButton = document.querySelector('[data-share-url]');
  const toast = document.querySelector('.public-knowledge-toast');
  const backdrop = document.querySelector('[data-panel-backdrop]');
  const articleLinks = [...document.querySelectorAll('[data-content-url]')];
  let requestController = null;
  let headingObserver = null;

  function showToast(message) {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    window.setTimeout(() => toast.classList.remove('show'), 1800);
  }

  async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value);
      return;
    }
    const input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    input.remove();
  }

  function closePanels() {
    document.body.classList.remove('public-knowledge-panel-open');
    document.querySelectorAll('[data-public-panel]').forEach((panel) => panel.classList.remove('is-open'));
    document.querySelectorAll('[data-mobile-panel]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    backdrop?.classList.remove('is-open');
  }

  function openPanel(name, trigger) {
    const panel = document.querySelector(`[data-public-panel="${name}"]`);
    const opening = !panel?.classList.contains('is-open');
    closePanels();
    if (!opening || !panel) return;
    panel.classList.add('is-open');
    trigger.setAttribute('aria-expanded', 'true');
    backdrop?.classList.add('is-open');
    document.body.classList.add('public-knowledge-panel-open');
  }

  function renderToc(items) {
    if (!tocList) return;
    tocList.replaceChildren();
    if (!Array.isArray(items) || !items.length) {
      const empty = document.createElement('span');
      empty.className = 'public-knowledge-toc-empty';
      empty.textContent = '本文暂无目录';
      tocList.appendChild(empty);
      return;
    }
    items.forEach((item) => {
      const link = document.createElement('a');
      link.href = `#${encodeURIComponent(String(item.id || ''))}`;
      link.dataset.tocLevel = String(Math.min(6, Math.max(1, Number(item.level) || 1)));
      link.textContent = String(item.title || '');
      link.addEventListener('click', closePanels);
      tocList.appendChild(link);
    });
  }

  function observeHeadings() {
    headingObserver?.disconnect();
    if (!('IntersectionObserver' in window) || !body || !tocList) return;
    const links = new Map([...tocList.querySelectorAll('a[href^="#"]')].map((link) => [
      decodeURIComponent(link.hash.slice(1)), link,
    ]));
    headingObserver = new IntersectionObserver((entries) => {
      const visible = entries.filter((entry) => entry.isIntersecting)
        .sort((left, right) => left.boundingClientRect.top - right.boundingClientRect.top)[0];
      if (!visible) return;
      links.forEach((link) => link.classList.remove('active'));
      links.get(visible.target.id)?.classList.add('active');
    }, { rootMargin: '-76px 0px -70% 0px', threshold: [0, 1] });
    body.querySelectorAll('h1[id],h2[id],h3[id],h4[id],h5[id],h6[id]')
      .forEach((heading) => headingObserver.observe(heading));
  }

  function setActiveArticle(id) {
    articleLinks.forEach((link) => {
      const active = String(link.dataset.articleId) === String(id);
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  }

  async function loadArticle(link, options = {}) {
    if (!link || !article || !body || !title) return false;
    requestController?.abort();
    requestController = new AbortController();
    article.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(link.dataset.contentUrl, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        credentials: 'same-origin',
        signal: requestController.signal,
      });
      if (!response.ok) throw new Error(`文章加载失败 (${response.status})`);
      const data = await response.json();
      if (!data || !data.id || typeof data.body !== 'string') throw new Error('文章数据格式错误');

      body.innerHTML = data.body;
      title.textContent = String(data.title || '');
      if (category) category.textContent = String(data.category || '');
      if (updated) updated.textContent = String(data.updated_at || '');
      renderToc(data.toc);
      setActiveArticle(data.id);
      layout.dataset.currentArticle = String(data.id);
      if (copyButton) copyButton.dataset.shareUrl = String(data.share_url || link.href);
      if (canonical) canonical.href = String(data.share_url || link.href);
      document.title = String(data.page_title || data.title || document.title);
      if (options.history !== false) window.history.pushState({ articleId: data.id }, '', data.share_url || link.href);
      closePanels();
      observeHeadings();
      window.scrollTo({ top: 0, behavior: options.instant ? 'auto' : 'smooth' });
      if (options.focus !== false) title.focus({ preventScroll: true });
      return true;
    } catch (error) {
      if (error?.name !== 'AbortError') showToast(error?.message || '文章加载失败，请重试');
      return false;
    } finally {
      article.setAttribute('aria-busy', 'false');
    }
  }

  articleLinks.forEach((link) => link.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    loadArticle(link);
  }));

  document.querySelectorAll('[data-mobile-panel]').forEach((button) => {
    button.addEventListener('click', () => openPanel(button.dataset.mobilePanel, button));
  });
  backdrop?.addEventListener('click', closePanels);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closePanels();
  });

  copyButton?.addEventListener('click', async () => {
    try {
      await copyText(copyButton.dataset.shareUrl || window.location.href);
      showToast('分享链接已复制');
    } catch (_) {
      showToast('复制失败，请复制浏览器地址');
    }
  });

  window.addEventListener('popstate', () => {
    const currentPath = window.location.pathname.replace(/\/$/, '');
    const link = articleLinks.find((item) => new URL(item.href, window.location.origin).pathname.replace(/\/$/, '') === currentPath);
    if (link) loadArticle(link, { history: false, instant: true, focus: false });
  });

  observeHeadings();
})();
