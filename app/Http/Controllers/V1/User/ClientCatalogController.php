<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\ClientCatalogService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;

class ClientCatalogController extends Controller
{
    public function __construct(private ClientCatalogService $catalog)
    {
    }

    public function index()
    {
        return $this->success($this->catalog->catalog());
    }

    public function qr(Request $request)
    {
        $validated = $request->validate([
            'client' => 'required|string|max:64|regex:/^[a-z0-9-]+$/',
            'platform' => 'required|in:' . implode(',', ClientCatalogService::PLATFORMS),
        ]);
        $this->catalog->find($validated['client']);
        $downloadUrl = route('client-catalog.download', $validated);
        $renderer = new ImageRenderer(new RendererStyle(360, 2), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($downloadUrl);

        return $this->success([
            'download_url' => $downloadUrl,
            'qr_code' => 'data:image/svg+xml;base64,' . base64_encode($svg),
        ]);
    }
}
