(() => {
  'use strict';
  const copyButton = document.querySelector('[data-share-url]');
  const toast = document.querySelector('.public-knowledge-toast');

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

  copyButton?.addEventListener('click', async () => {
    try {
      await copyText(copyButton.dataset.shareUrl || window.location.href);
      if (toast) {
        toast.textContent = '分享链接已复制';
        toast.classList.add('show');
        window.setTimeout(() => toast.classList.remove('show'), 1800);
      }
    } catch (_) {
      if (toast) {
        toast.textContent = '复制失败，请复制浏览器地址';
        toast.classList.add('show');
      }
    }
  });
})();
