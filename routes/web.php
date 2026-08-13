<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

Route::get('/client-download/{client}/{platform}', \App\Http\Controllers\ClientDownloadController::class)
    ->middleware('throttle:120,1')
    ->where('client', '[a-z0-9-]+')
    ->where('platform', 'android|ios|windows|macos|linux')
    ->name('client-catalog.download');

Route::get('/client-link/{client}/{platform}/{action}', \App\Http\Controllers\ClientLinkController::class)
    ->middleware('throttle:120,1')
    ->where('client', '[a-z0-9-]+')
    ->where('platform', 'android|ios|windows|macos|linux')
    ->where('action', 'qr|cloud|tutorial')
    ->name('client-catalog.link');

Route::get('/knowledge-attachments/{attachmentUuid}', [\App\Http\Controllers\KnowledgeAttachmentController::class, 'read'])
    ->middleware(['signed', 'throttle:600,1'])
    ->whereUuid('attachmentUuid')
    ->name('knowledge.attachments.read');

Route::get('/guide-attachments/{attachmentUuid}', [\App\Http\Controllers\KnowledgeAttachmentController::class, 'readPublic'])
    ->middleware('throttle:600,1')
    ->whereUuid('attachmentUuid')
    ->name('knowledge.public.attachments.read');

Route::get('/guide/{knowledge}/content', [\App\Http\Controllers\PublicKnowledgeController::class, 'content'])
    ->middleware('throttle:240,1')
    ->whereNumber('knowledge')
    ->name('knowledge.public.content');

Route::get('/guide/{knowledge}/{slug?}', [\App\Http\Controllers\PublicKnowledgeController::class, 'show'])
    ->middleware('throttle:120,1')
    ->whereNumber('knowledge')
    ->name('knowledge.public.show');

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);
        
        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    $theme = admin_setting('frontend_theme', 'Xboard');
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'Xboard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'Xboard is best'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeService->getConfig($theme)
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
});

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');
