<?php

namespace App\Services;

use App\Models\Knowledge;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

class PublicKnowledgeService
{
    private const ALLOWED_TAGS = [
        'a', 'blockquote', 'br', 'code', 'del', 'div', 'em', 'figcaption', 'figure',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'img', 'li', 'ol', 'p', 'pre',
        'source', 'span', 'strong', 'table', 'tbody', 'td', 'th', 'thead', 'tr',
        'u', 'ul', 'video',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel'],
        'div' => ['class'],
        'figcaption' => ['class'],
        'figure' => ['class'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'source' => ['src', 'type'],
        'span' => ['class'],
        'table' => ['class'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
        'video' => ['src', 'controls', 'preload', 'poster', 'width', 'height'],
    ];

    public function __construct(private KnowledgeAttachmentAccessService $attachmentAccessService)
    {
    }

    public function findPublished(int $id): Knowledge
    {
        return Knowledge::query()->whereKey($id)->where('show', true)->firstOrFail();
    }

    public function slug(string $title): string
    {
        $slug = Str::slug(Str::limit($title, 80, ''));
        return $slug !== '' ? $slug : 'article';
    }

    public function shareUrl(Knowledge $knowledge): string
    {
        return route('knowledge.public.show', [
            'knowledge' => $knowledge->id,
            'slug' => $this->slug($knowledge->title),
        ]);
    }

    public function shareUrlFor(int $id, string $title): string
    {
        return route('knowledge.public.show', [
            'knowledge' => $id,
            'slug' => $this->slug($title),
        ]);
    }

    public function render(Knowledge $knowledge): string
    {
        $body = (string) $knowledge->body;
        $loginUrl = url('/#/login');
        $body = str_replace(
            ['{{siteName}}', '{{subscribeUrl}}', '{{urlEncodeSubscribeUrl}}', '{{safeBase64SubscribeUrl}}'],
            [admin_setting('app_name', 'XBoard'), $loginUrl, urlencode($loginUrl), rtrim(strtr(base64_encode($loginUrl), '+/', '-_'), '=')],
            $body
        );
        $body = $this->attachmentAccessService->replaceWithPublicUrls($body, (int) $knowledge->id);
        $html = Str::markdown($body, [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        return $this->sanitize($html);
    }

    private function sanitize(string $html): string
    {
        if (!class_exists(DOMDocument::class)) {
            $allowed = '<' . implode('><', self::ALLOWED_TAGS) . '>';
            $html = strip_tags($html, $allowed);
            return preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div data-public-knowledge-root>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementsByTagName('div')->item(0);
        if (!$root) {
            return '';
        }
        $this->sanitizeChildren($root);

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= $document->saveHTML($child);
        }
        return $result;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $parent->removeChild($node);
                continue;
            }

            $allowedAttributes = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if (!in_array($name, $allowedAttributes, true) || !$this->safeAttribute($name, $attribute->value)) {
                    $node->removeAttribute($attribute->name);
                }
            }

            if ($tag === 'a' && $node->getAttribute('target') === '_blank') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }
            if ($tag === 'img') {
                $node->setAttribute('loading', 'lazy');
            }
            if ($tag === 'video') {
                $node->setAttribute('controls', 'controls');
                $node->setAttribute('preload', 'metadata');
            }

            $this->sanitizeChildren($node);
        }
    }

    private function safeAttribute(string $name, string $value): bool
    {
        if (!in_array($name, ['href', 'src', 'poster'], true)) {
            return true;
        }

        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || str_starts_with($value, '#') || str_starts_with($value, '/')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, $name === 'href' ? ['http', 'https', 'mailto'] : ['http', 'https'], true);
    }
}
