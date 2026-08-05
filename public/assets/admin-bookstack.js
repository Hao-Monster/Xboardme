(function () {
  'use strict';
  if (window.__xboardBookStackBridge) return;
  window.__xboardBookStackBridge = true;

  const tokenKey = 'XBOARD_ACCESS_TOKEN';
  let currentKnowledgeId = null;
  let openAfterSave = false;

  function securePath() { return String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, ''); }
  function endpoint(path) { return `/api/v2/${securePath()}${path}`; }
  function token() { try { return JSON.parse(localStorage.getItem(tokenKey) || 'null')?.value; } catch (_) { return null; } }
  function toast(message, error) {
    const item = document.createElement('div'); item.className = `bookstack-toast${error ? ' error' : ''}`; item.textContent = message;
    document.body.appendChild(item); setTimeout(() => item.remove(), 4200);
  }
  async function ensurePage(id) {
    const response = await fetch(endpoint('/knowledge/bookstack/ensure'), { method: 'POST', credentials: 'same-origin', headers: { Authorization: token(), 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
    const payload = await response.json().catch(() => null);
    if (!response.ok || payload?.status === 'fail') throw new Error(payload?.message || '无法创建 BookStack 正文页面');
    return payload.data || payload;
  }
  function installRequestObserver() {
    const open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) { this.__bookStackUrl = String(url || ''); return open.apply(this, arguments); };
    XMLHttpRequest.prototype.send = function (body) {
      if (/\/knowledge\/fetch\?[^#]*\bid=\d+/.test(this.__bookStackUrl || '')) this.addEventListener('load', () => {
        try { const data = JSON.parse(this.responseText)?.data; if (data?.id) currentKnowledgeId = Number(data.id); } catch (_) {}
      });
      if (/\/knowledge\/save(?:\?|$)/.test(this.__bookStackUrl || '')) this.addEventListener('load', async () => {
        if (!openAfterSave || this.status < 200 || this.status >= 300) return;
        openAfterSave = false;
        try {
          const id = Number(JSON.parse(this.responseText)?.data?.id);
          if (!id) throw new Error('文章保存成功，但未取得文章标识');
          currentKnowledgeId = id;
          const page = await ensurePage(id);
          window.open(page.edit_url, '_blank', 'noopener');
        } catch (error) { toast(error.message || '无法打开 BookStack 编辑器', true); }
      });
      return send.call(this, body);
    };
  }
  function enhanceDialog() {
    if (!/^#\/config\/knowledge(?:[/?]|$)/.test(location.hash)) return;
    document.querySelectorAll('[role="dialog"]').forEach((dialog) => {
      if (dialog.dataset.bookstackEnhanced) return;
      const editor = dialog.querySelector('.rc-md-editor');
      if (!editor) return;
      if (/添加知识/.test(dialog.textContent || '')) currentKnowledgeId = null;
      dialog.dataset.bookstackEnhanced = '1';
      editor.style.display = 'none';
      const panel = document.createElement('section');
      panel.className = 'bookstack-editor-panel';
      panel.innerHTML = '<strong>正文编辑</strong><p>正文、图片、视频和附件均由 BookStack 管理，支持直接拖入与粘贴。</p><small>点击右下角“打开 BookStack 编辑器”即可保存基础信息并进入正文编辑。</small>';
      editor.parentNode.insertBefore(panel, editor);
      const submit = [...dialog.querySelectorAll('button')].find((button) => /^(提交|保存|Submit|Save)$/i.test(button.textContent.trim()));
      if (!submit) return;
      submit.textContent = '打开 BookStack 编辑器';
      submit.addEventListener('click', async (event) => {
        if (!currentKnowledgeId) {
          openAfterSave = true;
          const textarea = editor.querySelector('textarea');
          if (textarea && !textarea.value.trim()) {
            textarea.value = '<p>BookStack 正文由 BookStack 管理。</p>';
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.dispatchEvent(new Event('change', { bubbles: true }));
          }
          return;
        }
        event.preventDefault(); event.stopImmediatePropagation();
        submit.disabled = true;
        try { const page = await ensurePage(currentKnowledgeId); window.open(page.edit_url, '_blank', 'noopener'); }
        catch (error) { toast(error.message || '打开 BookStack 失败', true); }
        finally { submit.disabled = false; }
      }, true);
    });
  }
  installRequestObserver();
  new MutationObserver(enhanceDialog).observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('hashchange', enhanceDialog); enhanceDialog();
})();
