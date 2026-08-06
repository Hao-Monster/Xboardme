<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <meta name="robots" content="index,follow">
  <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($article->body), 150) }}">
  <link rel="canonical" href="{{ $canonicalUrl }}">
  <link rel="stylesheet" href="/assets/public-knowledge.css">
  <title>{{ $article->title }} - {{ $appName }}</title>
</head>
<body>
  <header class="public-knowledge-header">
    <a href="/" class="public-knowledge-brand" aria-label="{{ $appName }}">
      @if($logo)<img src="{{ $logo }}" alt="">@endif
      <span>{{ $appName }}</span>
    </a>
    <button type="button" class="public-knowledge-copy" data-share-url="{{ $canonicalUrl }}">复制分享链接</button>
  </header>
  <main class="public-knowledge-layout">
    <article class="public-knowledge-article">
      <div class="public-knowledge-meta">{{ $article->category }}</div>
      <h1>{{ $article->title }}</h1>
      <div class="public-knowledge-updated">最后更新：{{ date('Y-m-d H:i', (int) $article->updated_at) }}</div>
      <div class="public-knowledge-body">{!! $body !!}</div>
    </article>
  </main>
  <aside class="public-auth-card" aria-label="登录或注册">
    <strong>登录或注册</strong>
    <span>登录后可继续使用完整服务</span>
    <div><a href="/#/login">登录</a><a class="primary" href="/#/register">注册</a></div>
  </aside>
  <div class="public-knowledge-toast" role="status" aria-live="polite"></div>
  <script defer src="/assets/public-knowledge.js"></script>
</body>
</html>
