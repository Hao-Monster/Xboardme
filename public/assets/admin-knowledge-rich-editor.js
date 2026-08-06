(function () {
  'use strict';

  if (window.__xboardKnowledgeRichText) return;

  const ATTACHMENT_PATTERN = /^knowledge-attachment:\/\/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  const SAFE_TAGS = new Set([
    'A', 'B', 'BLOCKQUOTE', 'BR', 'CODE', 'DIV', 'EM', 'H1', 'H2', 'H3',
    'H4', 'H5', 'H6', 'IMG', 'LI', 'OL', 'P', 'PRE', 'SPAN', 'STRONG',
    'UL', 'VIDEO',
  ]);

  function textOf(node) {
    return String(node?.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function attribute(node, name) {
    if (typeof node?.getAttribute === 'function') return node.getAttribute(name);
    return node?.attributes?.[name] ?? null;
  }

  function safeUrl(value) {
    const url = String(value || '').trim();
    if (!url) return '';
    if (ATTACHMENT_PATTERN.test(url)) return url;
    if (/^https?:\/\/[^\s]+$/i.test(url)) return url;
    if (/^\/(?!\/)[^\s]*$/.test(url)) return url;
    return '';
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
      const href = safeUrl(attribute(node, 'href'));
      const label = escapeInline(textOf(node) || attribute(node, 'download') || href);
      return href ? `[${label}](${href})` : label;
    }
    if (tag === 'IMG') {
      const src = safeUrl(attribute(node, 'src'));
      const alt = escapeInline(attribute(node, 'alt') || '图片');
      return src ? `![${alt}](${src})` : '';
    }
    if (tag === 'VIDEO') {
      const src = safeUrl(attribute(node, 'src'));
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
  }

  function sanitizeNode(source, documentRef) {
    if (source.nodeType === 3) return documentRef.createTextNode(source.nodeValue || '');
    if (source.nodeType !== 1) return null;
    const tag = String(source.tagName || '').toUpperCase();
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
    domToMarkdown,
    normalizeMarkdown,
    safeUrl,
    sanitizeFragment,
    serializeNode,
  };
}());
