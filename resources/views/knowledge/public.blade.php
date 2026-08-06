<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <meta name="robots" content="index,follow">
  <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($article->body), 150) }}">
  <link rel="canonical" href="{{ $canonicalUrl }}" data-public-canonical>
  <link rel="stylesheet" href="/assets/public-knowledge.css">
  <title>{{ $article->title }} - {{ $appName }}</title>
</head>
<body>
  <header class="public-knowledge-header">
    <a href="/" class="public-knowledge-brand" aria-label="{{ $appName }}">
      @if($logo)<img src="{{ $logo }}" alt="">@endif
      <span>{{ $appName }}</span>
    </a>
    <div class="public-knowledge-header-actions">
      <button type="button" class="public-knowledge-mobile-nav" data-mobile-panel="articles" aria-expanded="false">文章</button>
      <button type="button" class="public-knowledge-mobile-nav" data-mobile-panel="toc" aria-expanded="false">目录</button>
      <button type="button" class="public-knowledge-copy" data-share-url="{{ $canonicalUrl }}">复制分享链接</button>
    </div>
  </header>

  <main class="public-knowledge-layout" data-current-article="{{ $article->id }}">
    <aside class="public-knowledge-sidebar public-knowledge-articles" data-public-panel="articles" aria-label="公开文章">
      <div class="public-knowledge-sidebar-heading">所有文章</div>
      <nav class="public-knowledge-article-list">
        @foreach($articles->groupBy('category') as $category => $items)
          <section class="public-knowledge-category">
            <h2>{{ $category }}</h2>
            @foreach($items as $item)
              <a href="{{ $item['url'] }}"
                 data-article-id="{{ $item['id'] }}"
                 data-content-url="{{ $item['content_url'] }}"
                 @class(['active' => $item['id'] === (int) $article->id])
                 @if($item['id'] === (int) $article->id) aria-current="page" @endif>{{ $item['title'] }}</a>
            @endforeach
          </section>
        @endforeach
      </nav>
    </aside>

    <aside class="public-knowledge-sidebar public-knowledge-toc" data-public-panel="toc" aria-label="当前文章目录">
      <div class="public-knowledge-sidebar-heading">文章目录</div>
      <nav class="public-knowledge-toc-list" data-toc-list>
        @forelse($toc as $item)
          <a href="#{{ $item['id'] }}" data-toc-level="{{ $item['level'] }}">{{ $item['title'] }}</a>
        @empty
          <span class="public-knowledge-toc-empty">本文暂无目录</span>
        @endforelse
      </nav>
    </aside>

    <article class="public-knowledge-article" data-public-article aria-busy="false">
      <div class="public-knowledge-meta" data-article-category>{{ $article->category }}</div>
      <h1 data-article-title tabindex="-1">{{ $article->title }}</h1>
      <div class="public-knowledge-updated">最后更新：<span data-article-updated>{{ date('Y-m-d H:i', (int) $article->updated_at) }}</span></div>
      <div class="public-knowledge-body" data-article-body>{!! $body !!}</div>
    </article>
  </main>

  <div class="public-knowledge-panel-backdrop" data-panel-backdrop></div>
  <aside class="public-auth-card" aria-label="登录或注册">
    <strong>登录或注册</strong>
    <span>登录后可继续使用完整服务</span>
    <div><a href="/#/login">登录</a><a class="primary" href="/#/register">注册</a></div>
  </aside>
  <div class="public-knowledge-toast" role="status" aria-live="polite"></div>
  <script defer src="/assets/public-knowledge.js"></script>
</body>
</html>
