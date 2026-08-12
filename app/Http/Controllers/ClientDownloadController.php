<?php

namespace App\Http\Controllers;

use App\Services\ClientCatalogService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class ClientDownloadController extends Controller
{
    public function __invoke(string $client, string $platform, ClientCatalogService $catalog): RedirectResponse
    {
        try {
            return redirect()->away($catalog->resolve($client, $platform), 302, [
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
            ]);
        } catch (\Throwable $error) {
            report($error);
            throw new ServiceUnavailableHttpException(60, '暂时无法获取最新安装包，请稍后重试。', $error);
        }
    }
}
