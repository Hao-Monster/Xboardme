<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $knowledge->title }}</title><style>body{margin:0;background:#f6f8fa;color:#17212b;font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.article{box-sizing:border-box;max-width:920px;margin:40px auto;padding:42px 48px;background:#fff;border-radius:12px;box-shadow:0 10px 30px #17212b12}img,video{max-width:100%;height:auto}pre{overflow:auto;padding:16px;background:#f4f6f8;border-radius:8px}a{color:#137b88}@media(max-width:640px){.article{margin:0;padding:24px 18px;border-radius:0}}</style></head>
<body><main class="article"><h1>{{ $knowledge->title }}</h1>{!! $html !!}</main></body>
</html>
