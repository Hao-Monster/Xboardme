<?php

namespace App\Http\Controllers;

use App\Services\ClientCatalogService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ClientLinkController extends Controller
{
    public function __invoke(
        string $client,
        string $platform,
        string $action,
        ClientCatalogService $catalog
    ): RedirectResponse {
        try {
            return redirect()->to($catalog->resolveAction($client, $platform, $action), 302, [
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
            ]);
        } catch (\Throwable $error) {
            report($error);
            throw new NotFoundHttpException('该客户端链接尚未配置。', $error);
        }
    }
}
