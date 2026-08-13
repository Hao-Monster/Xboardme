<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ClientCatalogService;
use Illuminate\Http\Request;

class ClientCatalogController extends Controller
{
    public function __construct(private ClientCatalogService $catalog)
    {
    }

    public function index()
    {
        return $this->success(['clients' => $this->catalog->adminCatalog()]);
    }

    public function save(Request $request)
    {
        $validated = $request->validate([
            'links' => ['present', 'array'],
        ]);

        $this->catalog->saveOverrides($validated['links']);

        return $this->success(['clients' => $this->catalog->adminCatalog()]);
    }
}
