<?php

namespace App\Services\Plugin;

use App\Models\Plugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use WeakMap;

class PluginManager
{
    public const ROUTE_METADATA_KEY = 'xboard_plugin_route';
    public const ROUTE_SIGNATURE_METADATA_KEY = 'xboard_plugin_route_signature';

    protected string $pluginPath;
    protected string $corePluginPath;
    protected array $loadedPlugins = [];
    protected bool $pluginsInitialized = false;
    protected array $configTypesCache = [];

    /**
     * @var array<string, string>
     */
    protected array $activePluginSignatures = [];

    /**
     * @var array<string, bool>
     */
    protected array $pluginHasRouteFilesCache = [];

    /**
     * @var array<string, class-string<ServiceProvider>|false>
     */
    protected array $providerClassCache = [];

    /**
     * @var WeakMap<RouteCollection, array<string, string>>|null
     */
    protected ?WeakMap $mountedRouteSignatures = null;

    public function __construct()
    {
        $this->pluginPath = base_path('plugins');
        $this->corePluginPath = base_path('plugins-core');
    }

    /**
     * 获取插件的命名空间
     */
    public function getPluginNamespace(string $pluginCode): string
    {
        return 'Plugin\\' . Str::studly($pluginCode);
    }

    public function resolvePluginPath(string $pluginCode): ?string
    {
        $dirName = Str::studly($pluginCode);
        $corePath = $this->corePluginPath . '/' . $dirName;
        if (File::isDirectory($corePath)) {
            return $corePath;
        }
        $userPath = $this->pluginPath . '/' . $dirName;
        if (File::isDirectory($userPath)) {
            return $userPath;
        }
        return null;
    }

    public function getPluginPath(string $pluginCode): string
    {
        return $this->resolvePluginPath($pluginCode)
            ?? $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function getUserPluginPath(string $pluginCode): string
    {
        return $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function isCorePlugin(string $pluginCode): bool
    {
        $dirName = Str::studly($pluginCode);
        return File::isDirectory($this->corePluginPath . '/' . $dirName);
    }

    public function getPluginPaths(): array
    {
        return [$this->corePluginPath, $this->pluginPath];
    }

    /**
     * 加载插件类
     */
    protected function loadPlugin(string $pluginCode): ?AbstractPlugin
    {
        if (isset($this->loadedPlugins[$pluginCode])) {
            return $this->loadedPlugins[$pluginCode];
        }

        $pluginClass = $this->getPluginNamespace($pluginCode) . '\\Plugin';

        if (!class_exists($pluginClass)) {
            $pluginFile = $this->getPluginPath($pluginCode) . '/Plugin.php';
            if (!File::exists($pluginFile)) {
                Log::warning("Plugin class file not found: {$pluginFile}");
                Plugin::query()->where('code', $pluginCode)->delete();
                return null;
            }
            require_once $pluginFile;
        }

        if (!class_exists($pluginClass)) {
            Log::error("Plugin class not found: {$pluginClass}");
            return null;
        }

        $plugin = new $pluginClass($pluginCode);
        $this->loadedPlugins[$pluginCode] = $plugin;

        return $plugin;
    }

    /**
     * 注册插件的服务提供者
     */
    protected function registerServiceProvider(string $pluginCode): void
    {
        if (!array_key_exists($pluginCode, $this->providerClassCache)) {
            $providerClass = $this->getPluginNamespace($pluginCode) . '\\Providers\\PluginServiceProvider';
            if (!class_exists($providerClass)) {
                $providerFile = $this->getPluginPath($pluginCode) . '/Providers/PluginServiceProvider.php';
                if (File::exists($providerFile)) {
                    require_once $providerFile;
                }
            }
            /** @var class-string<ServiceProvider>|false $resolved */
            $resolved = class_exists($providerClass) && is_subclass_of($providerClass, ServiceProvider::class)
                ? $providerClass
                : false;
            $this->providerClassCache[$pluginCode] = $resolved;
        }

        $providerClass = $this->providerClassCache[$pluginCode];
        if ($providerClass !== false && app()->getProvider($providerClass) === null) {
            app()->register($providerClass);
        }
    }

    protected function computePluginSignature(string $version, ?string $rawConfig): string
    {
        return $version . ':' . sha1((string) ($rawConfig ?? ''));
    }

    protected function hasRouteFiles(string $pluginCode): bool
    {
        if (!array_key_exists($pluginCode, $this->pluginHasRouteFilesCache)) {
            $routesPath = $this->getPluginPath($pluginCode) . '/routes';
            $this->pluginHasRouteFilesCache[$pluginCode] = File::exists($routesPath . '/web.php')
                || File::exists($routesPath . '/api.php');
        }

        return $this->pluginHasRouteFilesCache[$pluginCode];
    }

    /**
     * @return array<string, string>
     */
    protected function getMountedRouteSignatures(RouteCollection $routes): array
    {
        $map = $this->mountedRouteSignatures ??= new WeakMap();
        if (isset($map[$routes])) {
            return $map[$routes];
        }

        $discovered = [];
        foreach ($routes->getRoutes() as $route) {
            $owner = $route->action['metadata'][self::ROUTE_METADATA_KEY] ?? null;
            if (is_string($owner) && $owner !== '') {
                $signature = $route->action['metadata'][self::ROUTE_SIGNATURE_METADATA_KEY] ?? '';
                $discovered[$owner] = is_string($signature) ? $signature : '';
            }
        }

        $map[$routes] = $discovered;
        return $discovered;
    }

    /**
     * @param array<int, string> $codesToRemove
     */
    protected function removePluginRoutes(array $codesToRemove): void
    {
        if ($codesToRemove === [] || !app()->bound('router')) {
            return;
        }

        /** @var Router $router */
        $router = app('router');
        $routes = $router->getRoutes();
        if (!$routes instanceof RouteCollection || $routes->count() === 0) {
            return;
        }

        $removeLookup = array_flip($codesToRemove);
        $rebuilt = new RouteCollection();
        $removedAny = false;
        $remainingSignatures = [];

        foreach ($routes->getRoutes() as $route) {
            $owner = $route->action['metadata'][self::ROUTE_METADATA_KEY] ?? null;
            if (is_string($owner) && isset($removeLookup[$owner])) {
                $removedAny = true;
                continue;
            }

            $rebuilt->add($route);
            if (is_string($owner) && $owner !== '') {
                $signature = $route->action['metadata'][self::ROUTE_SIGNATURE_METADATA_KEY] ?? '';
                $remainingSignatures[$owner] = is_string($signature) ? $signature : '';
            }
        }

        if (!$removedAny) {
            return;
        }

        $rebuilt->refreshNameLookups();
        $rebuilt->refreshActionLookups();
        $router->setRoutes($rebuilt);

        if (app()->resolved('url')) {
            app('url')->setRoutes($rebuilt);
        }

        $map = $this->mountedRouteSignatures ??= new WeakMap();
        $map[$rebuilt] = $remainingSignatures;
    }

    /**
     * 加载插件的路由
     */
    protected function loadRoutes(string $pluginCode, string $signature = ''): void
    {
        $routesPath = $this->getPluginPath($pluginCode) . '/routes';
        if (!File::exists($routesPath)) {
            return;
        }

        /** @var Router $router */
        $router = app('router');
        $existingCollection = $router->getRoutes();
        $existingRouteIds = [];
        if ($existingCollection instanceof RouteCollection) {
            foreach ($existingCollection->getRoutes() as $existingRoute) {
                $existingRouteIds[spl_object_id($existingRoute)] = true;
            }
        }

        $webRouteFile = $routesPath . '/web.php';
        $apiRouteFile = $routesPath . '/api.php';
        if (File::exists($webRouteFile)) {
            Route::middleware('web')
                ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                ->group(function () use ($webRouteFile) {
                    require $webRouteFile;
                });
        }
        if (File::exists($apiRouteFile)) {
            Route::middleware('api')
                ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                ->group(function () use ($apiRouteFile) {
                    require $apiRouteFile;
                });
        }

        $updatedCollection = $router->getRoutes();
        if ($updatedCollection instanceof RouteCollection) {
            foreach ($updatedCollection->getRoutes() as $route) {
                if (!isset($existingRouteIds[spl_object_id($route)])) {
                    $route->action['metadata'][self::ROUTE_METADATA_KEY] = $pluginCode;
                    $route->action['metadata'][self::ROUTE_SIGNATURE_METADATA_KEY] = $signature;
                }
            }
            $updatedCollection->refreshNameLookups();
            $updatedCollection->refreshActionLookups();
            if (app()->resolved('url')) {
                app('url')->setRoutes($updatedCollection);
            }
        }
    }

    /**
     * 加载插件的视图
     */
    protected function loadViews(string $pluginCode): void
    {
        $viewsPath = $this->getPluginPath($pluginCode) . '/resources/views';
        if (File::exists($viewsPath)) {
            $namespace = Str::studly($pluginCode);
            $finder = View::getFinder();
            $hints = $finder instanceof \Illuminate\View\FileViewFinder
                ? ($finder->getHints()[$namespace] ?? [])
                : [];
            if (!in_array($viewsPath, $hints, true)) {
                View::addNamespace($namespace, $viewsPath);
            }
            return;
        }
    }

    /**
     * 注册插件命令
     */
    protected function registerPluginCommands(string $pluginCode, AbstractPlugin $pluginInstance): void
    {
        try {
            // 调用插件的命令注册方法
            $pluginInstance->registerCommands();
        } catch (\Exception $e) {
            Log::error("Failed to register commands for plugin '{$pluginCode}': " . $e->getMessage());
        }
    }

    /**
     * 安装插件
     */
    public function install(string $pluginCode): bool
    {
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';

        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$this->validateConfig($config)) {
            throw new \Exception('Invalid plugin config');
        }

        // 检查插件是否已安装
        if (Plugin::where('code', $pluginCode)->exists()) {
            throw new \Exception('Plugin already installed');
        }

        // 检查依赖
        if (!$this->checkDependencies($config['require'] ?? [])) {
            throw new \Exception('Dependencies not satisfied');
        }

        // 运行数据库迁移
        $this->runMigrations(pluginCode: $pluginCode);

        DB::beginTransaction();
        try {
            // 提取配置默认值
            $defaultValues = $this->extractDefaultConfig($config);

            // 创建插件实例
            $plugin = $this->loadPlugin($pluginCode);

            // 注册到数据库
            Plugin::create([
                'code' => $pluginCode,
                'name' => $config['name'],
                'version' => $config['version'],
                'type' => $config['type'] ?? Plugin::TYPE_FEATURE,
                'is_enabled' => false,
                'config' => json_encode($defaultValues),
                'installed_at' => now(),
            ]);

            // 运行插件安装方法
            if (method_exists($plugin, 'install')) {
                $plugin->install();
            }

            // 发布插件资源
            $this->publishAssets($pluginCode);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * 提取插件默认配置
     */
    protected function extractDefaultConfig(array $config): array
    {
        $defaultValues = [];
        if (isset($config['config']) && is_array($config['config'])) {
            foreach ($config['config'] as $key => $item) {
                if (is_array($item)) {
                    $defaultValues[$key] = $item['default'] ?? null;
                } else {
                    $defaultValues[$key] = $item;
                }
            }
        }
        return $defaultValues;
    }

    /**
     * 获取 Migrator 实例并确保迁移仓库存在
     */
    protected function getMigrator(): \Illuminate\Database\Migrations\Migrator
    {
        $migrator = app('migrator');

        if (!$migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        return $migrator;
    }

    /**
     * 运行插件数据库迁移
     */
    protected function runMigrations(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->run([$migrationsPath]);
        }
    }

    /**
     * 回滚插件数据库迁移
     */
    protected function runMigrationsRollback(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->rollback([$migrationsPath]);
        }
    }

    /**
     * 发布插件资源
     */
    protected function publishAssets(string $pluginCode): void
    {
        $assetsPath = $this->getPluginPath($pluginCode) . '/resources/assets';
        if (File::exists($assetsPath)) {
            $publishPath = public_path('plugins/' . $pluginCode);
            File::ensureDirectoryExists($publishPath);
            File::copyDirectory($assetsPath, $publishPath);
        }
    }

    /**
     * 验证配置文件
     */
    protected function validateConfig(array $config): bool
    {
        $requiredFields = [
            'name',
            'code',
            'version',
            'description',
            'author'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                return false;
            }
        }

        // 验证插件代码格式
        if (!preg_match('/^[a-z0-9_]+$/', $config['code'])) {
            return false;
        }

        // 验证版本号格式
        if (!preg_match('/^\d+\.\d+\.\d+$/', $config['version'])) {
            return false;
        }

        // 验证插件类型
        if (isset($config['type'])) {
            $validTypes = ['feature', 'payment'];
            if (!in_array($config['type'], $validTypes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 启用插件
     */
    public function enable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);

        if (!$plugin) {
            Plugin::where('code', $pluginCode)->delete();
            throw new \Exception('Plugin not found: ' . $pluginCode);
        }

        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'is_enabled' => true,
                'updated_at' => now(),
            ]);

        $this->initializeEnabledPlugins();

        return true;
    }

    /**
     * 禁用插件
     */
    public function disable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);
        if (!$plugin) {
            throw new \Exception('Plugin not found');
        }

        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'is_enabled' => false,
                'updated_at' => now(),
            ]);

        $plugin->cleanup();
        $this->initializeEnabledPlugins();

        return true;
    }

    /**
     * 卸载插件
     */
    public function uninstall(string $pluginCode): bool
    {
        $this->disable($pluginCode);
        $this->runMigrationsRollback($pluginCode);
        Plugin::query()->where('code', $pluginCode)->delete();

        return true;
    }

    /**
     * 删除插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function delete(string $pluginCode): bool
    {
        if (Plugin::where('code', $pluginCode)->exists()) {
            $this->uninstall($pluginCode);
        }

        if ($this->isCorePlugin($pluginCode)) {
            throw new \Exception('核心插件不允许删除');
        }

        $pluginPath = $this->getUserPluginPath($pluginCode);
        if (!File::exists($pluginPath)) {
            throw new \Exception('插件不存在');
        }

        File::deleteDirectory($pluginPath);

        return true;
    }

    /**
     * 检查依赖关系
     */
    protected function checkDependencies(array $requires): bool
    {
        foreach ($requires as $package => $version) {
            if ($package === 'xboard') {
                // 检查xboard版本
                // 实现版本比较逻辑
            }
        }
        return true;
    }

    /**
     * 升级插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function update(string $pluginCode): bool
    {
        $dbPlugin = Plugin::where('code', $pluginCode)->first();
        if (!$dbPlugin) {
            throw new \Exception('Plugin not installed: ' . $pluginCode);
        }

        // 获取插件配置文件中的最新版本
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$config || !isset($config['version'])) {
            throw new \Exception('Invalid plugin config or missing version');
        }

        $newVersion = $config['version'];
        $oldVersion = $dbPlugin->version;

        if (version_compare($newVersion, $oldVersion, '<=')) {
            throw new \Exception('Plugin is already up to date');
        }

        $this->disable($pluginCode);
        $this->runMigrations($pluginCode);

        $plugin = $this->loadPlugin($pluginCode);
            if ($plugin) {
                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $plugin->setConfig($values);
                }

                $plugin->update($oldVersion, $newVersion);
            }

        $dbPlugin->update([
            'version' => $newVersion,
            'updated_at' => now(),
        ]);

        $this->enable($pluginCode);

        return true;
    }

    /**
     * 上传插件
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return bool
     * @throws \Exception
     */
    public function upload($file): bool
    {
        $tmpPath = storage_path('tmp/plugins');
        if (!File::exists($tmpPath)) {
            File::makeDirectory($tmpPath, 0755, true);
        }

        $extractPath = $tmpPath . '/' . uniqid();
        $zip = new \ZipArchive();

        if ($zip->open($file->path()) !== true) {
            throw new \Exception('无法打开插件包文件');
        }

        $zip->extractTo($extractPath);
        $zip->close();

        $configFile = File::glob($extractPath . '/*/config.json');
        if (empty($configFile)) {
            $configFile = File::glob($extractPath . '/config.json');
        }

        if (empty($configFile)) {
            File::deleteDirectory($extractPath);
            throw new \Exception('插件包格式错误：缺少配置文件');
        }

        $pluginPath = dirname(reset($configFile));
        $config = json_decode(File::get($pluginPath . '/config.json'), true);

        if (!$this->validateConfig($config)) {
            File::deleteDirectory($extractPath);
            throw new \Exception('插件配置文件格式错误');
        }

        $targetPath = $this->getUserPluginPath($config['code']);
        if (File::exists($targetPath)) {
            $installedConfigPath = $targetPath . '/config.json';
            if (!File::exists($installedConfigPath)) {
                throw new \Exception('已安装插件缺少配置文件，无法判断是否可升级');
            }
            $installedConfig = json_decode(File::get($installedConfigPath), true);

            $oldVersion = $installedConfig['version'] ?? null;
            $newVersion = $config['version'] ?? null;
            if (!$oldVersion || !$newVersion) {
                throw new \Exception('插件缺少版本号，无法判断是否可升级');
            }
            if (version_compare($newVersion, $oldVersion, '<=')) {
                throw new \Exception('上传插件版本不高于已安装版本，无法升级');
            }

            File::deleteDirectory($targetPath);
        }

        File::copyDirectory($pluginPath, $targetPath);
        File::deleteDirectory($pluginPath);
        File::deleteDirectory($extractPath);

        if (Plugin::where('code', $config['code'])->exists()) {
            return $this->update($config['code']);
        }

        return true;
    }

    /**
     * Reconciles active worker plugin state (routes, hooks, providers, views, config)
     * against the enabled plugin rows in the database without duplicating registrations.
     */
    public function initializeEnabledPlugins(): void
    {
        $enabledPlugins = Plugin::query()
            ->where('is_enabled', true)
            ->orderBy('code')
            ->get();

        $desiredByCode = [];
        $desiredSignatures = [];
        foreach ($enabledPlugins as $dbPlugin) {
            $code = (string) $dbPlugin->code;
            $desiredByCode[$code] = $dbPlugin;
            $rawConfig = $dbPlugin->getRawOriginal('config');
            $desiredSignatures[$code] = $this->computePluginSignature(
                (string) $dbPlugin->version,
                is_string($rawConfig) ? $rawConfig : null
            );
        }

        /** @var Router|null $router */
        $router = app()->bound('router') ? app('router') : null;
        $routeCollection = $router?->getRoutes();
        $mountedSignatures = $routeCollection instanceof RouteCollection
            ? $this->getMountedRouteSignatures($routeCollection)
            : [];

        $staleCodes = array_values(array_unique(array_merge(
            array_diff(array_keys($this->activePluginSignatures), array_keys($desiredSignatures)),
            array_diff(array_keys($mountedSignatures), array_keys($desiredSignatures))
        )));

        if ($staleCodes !== []) {
            foreach ($staleCodes as $staleCode) {
                HookManager::removeByOwner($staleCode);
                unset(
                    $this->activePluginSignatures[$staleCode],
                    $this->loadedPlugins[$staleCode],
                    $this->pluginHasRouteFilesCache[$staleCode],
                    $this->providerClassCache[$staleCode],
                    $this->configTypesCache[$staleCode]
                );
            }
            $this->removePluginRoutes($staleCodes);
            $routeCollection = $router?->getRoutes();
            $mountedSignatures = $routeCollection instanceof RouteCollection
                ? $this->getMountedRouteSignatures($routeCollection)
                : [];
        }

        foreach ($desiredByCode as $pluginCode => $dbPlugin) {
            try {
                $signature = $desiredSignatures[$pluginCode];
                $signatureChanged = ($this->activePluginSignatures[$pluginCode] ?? null) !== $signature;
                $hooksReady = HookManager::isInitializedForOwner($pluginCode);
                $hasRoutes = $this->hasRouteFiles($pluginCode);
                $routesReady = !$hasRoutes || (($mountedSignatures[$pluginCode] ?? null) === $signature);

                if (!$signatureChanged && $hooksReady && $routesReady) {
                    $this->registerServiceProvider($pluginCode);
                    continue;
                }

                if ($signatureChanged) {
                    unset(
                        $this->loadedPlugins[$pluginCode],
                        $this->pluginHasRouteFilesCache[$pluginCode],
                        $this->providerClassCache[$pluginCode],
                        $this->configTypesCache[$pluginCode]
                    );
                }

                $pluginInstance = $this->loadPlugin($pluginCode);
                if (!$pluginInstance) {
                    HookManager::removeByOwner($pluginCode);
                    unset($this->activePluginSignatures[$pluginCode]);
                    $this->removePluginRoutes([$pluginCode]);
                    continue;
                }

                $values = [];
                if (!empty($dbPlugin->config)) {
                    $decoded = json_decode((string) $dbPlugin->config, true);
                    $values = is_array($decoded) ? $decoded : [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                }
                $pluginInstance->setConfig($values);

                $this->registerServiceProvider($pluginCode);

                if (!$routesReady) {
                    if (isset($mountedSignatures[$pluginCode])) {
                        $this->removePluginRoutes([$pluginCode]);
                    }
                    $this->loadRoutes($pluginCode, $signature);
                    $routeCollection = $router?->getRoutes();
                    if ($routeCollection instanceof RouteCollection) {
                        $map = $this->mountedRouteSignatures ??= new WeakMap();
                        $currentMap = $this->getMountedRouteSignatures($routeCollection);
                        $currentMap[$pluginCode] = $signature;
                        $map[$routeCollection] = $currentMap;
                        $mountedSignatures = $currentMap;
                    }
                }

                $this->loadViews($pluginCode);
                $this->registerPluginCommands($pluginCode, $pluginInstance);

                if ($signatureChanged || !$hooksReady) {
                    HookManager::removeByOwner($pluginCode);
                    HookManager::withOwner($pluginCode, static function () use ($pluginInstance): void {
                        $pluginInstance->boot();
                    });
                }

                $this->activePluginSignatures[$pluginCode] = $signature;
            } catch (\Exception $e) {
                Log::error("Failed to initialize plugin '{$pluginCode}': " . $e->getMessage());
            }
        }

        $this->pluginsInitialized = true;
    }

    /**
     * Register scheduled tasks for all enabled plugins.
     * Called from Console Kernel. Only loads main plugin class and config for scheduling.
     * Avoids full HTTP/plugin boot overhead.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     */
    public function registerPluginSchedules(Schedule $schedule): void
    {
        Plugin::where('is_enabled', true)
            ->get()
            ->each(function ($dbPlugin) use ($schedule) {
                try {
                    $pluginInstance = $this->loadPlugin($dbPlugin->code);
                    if (!$pluginInstance) {
                        return;
                    }
                    if (!empty($dbPlugin->config)) {
                        $values = json_decode($dbPlugin->config, true) ?: [];
                        $values = $this->castConfigValuesByType($dbPlugin->code, $values);
                        $pluginInstance->setConfig($values);
                    }
                    $pluginInstance->schedule($schedule);

                } catch (\Exception $e) {
                    Log::error("Failed to register schedule for plugin '{$dbPlugin->code}': " . $e->getMessage());
                }
            });
    }

    /**
     * Get all enabled plugin instances.
     *
     * This method ensures that all enabled plugins are initialized and then returns them.
     * It's the central point for accessing active plugins.
     *
     * @return array<AbstractPlugin>
     */
    public function getEnabledPlugins(): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled plugins by type
     */
    public function getEnabledPluginsByType(string $type): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->byType($type)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled payment plugins
     */
    public function getEnabledPaymentPlugins(): array
    {
        return $this->getEnabledPluginsByType('payment');
    }

    /**
     * install default plugins
     */
    public static function installDefaultPlugins(): void
    {
        $pluginManager = app(self::class);
        $coreDir = base_path('plugins-core');

        if (!File::isDirectory($coreDir)) {
            return;
        }

        foreach (File::directories($coreDir) as $directory) {
            $configFile = $directory . '/config.json';
            if (!File::exists($configFile)) {
                continue;
            }
            $config = json_decode(File::get($configFile), true);
            $code = $config['code'] ?? null;
            if (!$code) {
                continue;
            }
            if (!Plugin::where('code', $code)->exists()) {
                $pluginManager->install($code);
                $pluginManager->enable($code);
                Log::info("Installed and enabled core plugin: {$code}");
            }
        }
    }

    /**
     * 根据 config.json 的类型信息对配置值进行类型转换（仅处理 type=json 键）。
     */
    protected function castConfigValuesByType(string $pluginCode, array $values): array
    {
        $types = $this->getConfigTypes($pluginCode);
        foreach ($values as $key => $value) {
            $type = $types[$key] ?? null;

            if ($type === 'json') {
                if (is_array($value)) {
                    continue;
                }
                
                if (is_string($value) && $value !== '') {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $values[$key] = $decoded;
                    }
                }
            }
        }
        return $values;
    }

    /**
     * 读取并缓存插件 config.json 中的键类型映射。
     */
    protected function getConfigTypes(string $pluginCode): array
    {
        if (isset($this->configTypesCache[$pluginCode])) {
            return $this->configTypesCache[$pluginCode];
        }
        $types = [];
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (File::exists($configFile)) {
            $config = json_decode(File::get($configFile), true);
            $fields = $config['config'] ?? [];
            foreach ($fields as $key => $meta) {
                $types[$key] = is_array($meta) ? ($meta['type'] ?? 'string') : 'string';
            }
        }
        $this->configTypesCache[$pluginCode] = $types;
        return $types;
    }
}