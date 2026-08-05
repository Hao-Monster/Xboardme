<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Knowledge;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BookStackService
{
    public function configured(): bool
    {
        return config('bookstack.base_url') !== ''
            && config('bookstack.token_id') !== ''
            && config('bookstack.token_secret') !== ''
            && config('bookstack.book_id') > 0;
    }

    public function ensurePage(Knowledge $knowledge): array
    {
        $this->ensureConfigured();

        if ($knowledge->bookstack_page_id) {
            $page = $this->request()->put('/api/pages/' . $knowledge->bookstack_page_id, [
                'name' => $knowledge->title,
            ])->throw()->json();
        } else {
            $page = $this->request()->post('/api/pages', [
                'book_id' => config('bookstack.book_id'),
                'name' => $knowledge->title,
                'html' => '<p>请在此编写正文。</p>',
            ])->throw()->json();
            $knowledge->bookstack_page_id = (int) ($page['id'] ?? 0);
        }

        if (!$knowledge->bookstack_page_id) {
            throw new ApiException('BookStack 未返回文章标识。', 502);
        }

        $knowledge->bookstack_url = $this->absoluteUrl((string) ($page['url'] ?? ''));
        $knowledge->share_token ??= Str::random(64);
        $knowledge->saveOrFail();

        return ['page_id' => $knowledge->bookstack_page_id, 'edit_url' => $this->editUrl($knowledge, $page)];
    }

    public function pageHtml(Knowledge $knowledge): string
    {
        $this->ensureConfigured();
        if (!$knowledge->bookstack_page_id) {
            throw new ApiException('该知识文章尚未创建正文。', 404);
        }
        $page = $this->request()->get('/api/pages/' . $knowledge->bookstack_page_id)->throw()->json();
        return (string) ($page['html'] ?? '');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('bookstack.base_url'))
            ->acceptJson()
            ->timeout(config('bookstack.timeout'))
            ->withToken(config('bookstack.token_id') . ':' . config('bookstack.token_secret'), 'Token');
    }

    private function editUrl(Knowledge $knowledge, array $page): string
    {
        $url = $this->absoluteUrl((string) ($page['url'] ?? $knowledge->bookstack_url));
        return $url !== '' ? $url . '/edit' : config('bookstack.base_url') . '/edit/pages/' . $knowledge->bookstack_page_id;
    }

    private function absoluteUrl(string $url): string
    {
        if ($url === '') return '';
        return Str::startsWith($url, ['http://', 'https://']) ? $url : config('bookstack.base_url') . '/' . ltrim($url, '/');
    }

    private function ensureConfigured(): void
    {
        if (!$this->configured()) {
            throw new ApiException('BookStack 尚未完成 API 配置，请联系管理员。', 503);
        }
    }
}
