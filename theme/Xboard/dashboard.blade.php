<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <title>{{$title}}</title>
  <link rel="stylesheet" href="/theme/{{$theme}}/assets/distributor.css?v={{$version}}" />
  <link rel="stylesheet" href="/assets/knowledge-share.css?v={{$version}}" />
  <link rel="stylesheet" href="/theme/{{$theme}}/assets/client-center.css?v={{$version}}" />
  <script defer src="/assets/knowledge-share.js?v={{$version}}"></script>
  <script defer src="/theme/{{$theme}}/assets/client-center.js?v={{$version}}"></script>
  <script src="/theme/{{$theme}}/assets/distributor-message-guard.js?v={{$version}}"></script>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi.js"></script>
</head>

<body>

  <script>
    window.routerBase = "/";
    window.settings = {
      title: '{{$title}}',
      assets_path: '/theme/{{$theme}}/assets',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? "default" }}',
      },
      version: '{{$version}}',
      background_url: '{{$theme_config['background_url']}}',
      description: '{{$description}}',
      i18n: [
        'zh-CN',
        'en-US',
        'ja-JP',
        'vi-VN',
        'ko-KR',
        'zh-TW',
        'fa-IR'
      ],
      logo: '{{$logo}}'
    }
  </script>
  <div id="app"></div>
  <script defer src="/theme/{{$theme}}/assets/distributor.js?v={{$version}}"></script>
  {!! $theme_config['custom_html'] !!}
</body>

</html>
