<?php

namespace App\Services\Plugin;

use ArrayObject;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class HookManager
{
    private static ?string $currentOwner = null;

    /**
     * Resolve an ArrayObject store bound on the container so mutations persist
     * across Octane sandbox clones while remaining isolated per test application.
     *
     * @return ArrayObject<string, mixed>
     */
    protected static function resolveStore(string $key): ArrayObject
    {
        $app = App::getFacadeApplication() ?? app();
        if (!$app->bound($key)) {
            $store = new ArrayObject();
            $app->instance($key, $store);
            return $store;
        }

        $existing = $app->make($key);
        if ($existing instanceof ArrayObject) {
            return $existing;
        }

        $store = new ArrayObject(is_array($existing) ? $existing : []);
        $app->instance($key, $store);
        return $store;
    }

    /**
     * Execute a callback while attributing all registered hooks to a plugin owner.
     */
    public static function withOwner(string $owner, callable $callback): mixed
    {
        $previous = self::$currentOwner;
        self::$currentOwner = $owner;

        $ownersStore = self::resolveStore('hook.owners');
        $owners = $ownersStore->getArrayCopy();
        $owners[$owner] ??= [];
        $ownersStore->exchangeArray($owners);

        try {
            return $callback();
        } finally {
            self::$currentOwner = $previous;
        }
    }

    /**
     * Determine whether hooks for the given plugin owner are currently initialized.
     */
    public static function isInitializedForOwner(string $owner): bool
    {
        $owners = self::resolveStore('hook.owners')->getArrayCopy();
        if (!array_key_exists($owner, $owners) || !is_array($owners[$owner])) {
            return false;
        }

        if ($owners[$owner] === []) {
            return true;
        }

        $actions = null;
        $filters = null;

        foreach ($owners[$owner] as $entry) {
            if (!is_array($entry)) {
                return false;
            }

            $type = $entry['type'] ?? '';
            $hook = $entry['hook'] ?? '';
            $priority = $entry['priority'] ?? 20;
            $id = $entry['id'] ?? '';

            if ($type === 'action') {
                $actions ??= self::getActions();
                if (!isset($actions[$hook][$priority][$id])) {
                    return false;
                }
                continue;
            }

            if ($type === 'filter') {
                $filters ??= self::getFilters();
                if (!isset($filters[$hook][$priority][$id])) {
                    return false;
                }
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Remove all action and filter hooks registered by a specific plugin owner.
     */
    public static function removeByOwner(string $owner): void
    {
        $ownersStore = self::resolveStore('hook.owners');
        $owners = $ownersStore->getArrayCopy();
        if (!array_key_exists($owner, $owners)) {
            return;
        }

        $entries = is_array($owners[$owner]) ? $owners[$owner] : [];
        if ($entries !== []) {
            $actions = self::getActions();
            $filters = self::getFilters();

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $type = $entry['type'] ?? '';
                $hook = $entry['hook'] ?? '';
                $priority = $entry['priority'] ?? 20;
                $id = $entry['id'] ?? '';

                if ($type === 'action' && isset($actions[$hook][$priority][$id])) {
                    unset($actions[$hook][$priority][$id]);
                    if (empty($actions[$hook][$priority])) {
                        unset($actions[$hook][$priority]);
                    }
                    if (empty($actions[$hook])) {
                        unset($actions[$hook]);
                    }
                } elseif ($type === 'filter' && isset($filters[$hook][$priority][$id])) {
                    unset($filters[$hook][$priority][$id]);
                    if (empty($filters[$hook][$priority])) {
                        unset($filters[$hook][$priority]);
                    }
                    if (empty($filters[$hook])) {
                        unset($filters[$hook]);
                    }
                }
            }

            self::setActions($actions);
            self::setFilters($filters);
        }

        unset($owners[$owner]);
        $ownersStore->exchangeArray($owners);
    }

    protected static function recordOwnerHook(string $owner, string $type, string $hook, int $priority, string $id): void
    {
        $ownersStore = self::resolveStore('hook.owners');
        $owners = $ownersStore->getArrayCopy();
        if (!isset($owners[$owner]) || !is_array($owners[$owner])) {
            $owners[$owner] = [];
        }
        $owners[$owner][] = [
            'type' => $type,
            'hook' => $hook,
            'priority' => $priority,
            'id' => $id,
        ];
        $ownersStore->exchangeArray($owners);
    }

    /**
     * Container for storing action hooks
     */
    public static function getActions(): array
    {
        return self::resolveStore('hook.actions')->getArrayCopy();
    }

    /**
     * Container for storing filter hooks
     */
    public static function getFilters(): array
    {
        return self::resolveStore('hook.filters')->getArrayCopy();
    }

    /**
     * Set action hooks
     */
    protected static function setActions(array $actions): void
    {
        self::resolveStore('hook.actions')->exchangeArray($actions);
    }

    /**
     * Set filter hooks
     */
    protected static function setFilters(array $filters): void
    {
        self::resolveStore('hook.filters')->exchangeArray($filters);
    }

    /**
     * Generate unique identifier for callback
     *
     * @param callable $callback
     * @return string
     */
    protected static function getCallableId(callable $callback): string
    {
        if (is_object($callback)) {
            return spl_object_hash($callback);
        }

        if (is_array($callback) && count($callback) === 2) {
            [$class, $method] = $callback;

            if (is_object($class)) {
                return spl_object_hash($class) . '::' . $method;
            } else {
                return (string) $class . '::' . $method;
            }
        }

        if (is_string($callback)) {
            return $callback;
        }

        return 'callable_' . uniqid();
    }

    /**
     * Intercept response
     *
     * @param SymfonyResponse|string|array $response New response content
     * @return never
     * @throws \Exception
     */
    public static function intercept(SymfonyResponse|string|array $response): never
    {
        if (is_string($response)) {
            $response = response($response);
        } elseif (is_array($response)) {
            $response = response()->json($response);
        }

        throw new InterceptResponseException($response);
    }

    /**
     * Trigger action hook
     *
     * @param string $hook Hook name
     * @param mixed $payload Data passed to hook
     * @return void
     */
    public static function call(string $hook, mixed $payload = null): void
    {
        $actions = self::getActions();

        if (!isset($actions[$hook])) {
            return;
        }

        ksort($actions[$hook]);

        foreach ($actions[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback($payload);
            }
        }
    }

    /**
     * Trigger filter hook
     *
     * @param string $hook Hook name
     * @param mixed $value Value to filter
     * @param mixed ...$args Other parameters
     * @return mixed
     */
    public static function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        $filters = self::getFilters();

        if (!isset($filters[$hook])) {
            return $value;
        }

        ksort($filters[$hook]);

        $result = $value;
        foreach ($filters[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $result = $callback($result, ...$args);
            }
        }

        return $result;
    }

    /**
     * Register action hook listener
     *
     * @param string $hook Hook name
     * @param callable $callback Callback function
     * @param int $priority Priority
     * @return void
     */
    public static function register(string $hook, callable $callback, int $priority = 20): void
    {
        $actions = self::getActions();

        if (!isset($actions[$hook])) {
            $actions[$hook] = [];
        }

        if (!isset($actions[$hook][$priority])) {
            $actions[$hook][$priority] = [];
        }

        $callbackId = self::getCallableId($callback);
        $actions[$hook][$priority][$callbackId] = $callback;

        self::setActions($actions);

        if (self::$currentOwner !== null) {
            self::recordOwnerHook(self::$currentOwner, 'action', $hook, $priority, $callbackId);
        }
    }

    /**
     * Register filter hook
     *
     * @param string $hook Hook name
     * @param callable $callback Callback function
     * @param int $priority Priority
     * @return void
     */
    public static function registerFilter(string $hook, callable $callback, int $priority = 20): void
    {
        $filters = self::getFilters();

        if (!isset($filters[$hook])) {
            $filters[$hook] = [];
        }

        if (!isset($filters[$hook][$priority])) {
            $filters[$hook][$priority] = [];
        }

        $callbackId = self::getCallableId($callback);
        $filters[$hook][$priority][$callbackId] = $callback;

        self::setFilters($filters);

        if (self::$currentOwner !== null) {
            self::recordOwnerHook(self::$currentOwner, 'filter', $hook, $priority, $callbackId);
        }
    }

    /**
     * Remove hook listener
     *
     * @param string $hook Hook name
     * @param callable|null $callback Callback function
     * @return void
     */
    public static function remove(string $hook, ?callable $callback = null): void
    {
        $actions = self::getActions();
        $filters = self::getFilters();

        if ($callback === null) {
            if (isset($actions[$hook])) {
                unset($actions[$hook]);
                self::setActions($actions);
            }

            if (isset($filters[$hook])) {
                unset($filters[$hook]);
                self::setFilters($filters);
            }

            return;
        }

        $callbackId = self::getCallableId($callback);

        if (isset($actions[$hook])) {
            foreach ($actions[$hook] as $priority => $callbacks) {
                if (isset($callbacks[$callbackId])) {
                    unset($actions[$hook][$priority][$callbackId]);

                    if (empty($actions[$hook][$priority])) {
                        unset($actions[$hook][$priority]);
                    }

                    if (empty($actions[$hook])) {
                        unset($actions[$hook]);
                    }
                }
            }
            self::setActions($actions);
        }

        if (isset($filters[$hook])) {
            foreach ($filters[$hook] as $priority => $callbacks) {
                if (isset($callbacks[$callbackId])) {
                    unset($filters[$hook][$priority][$callbackId]);

                    if (empty($filters[$hook][$priority])) {
                        unset($filters[$hook][$priority]);
                    }

                    if (empty($filters[$hook])) {
                        unset($filters[$hook]);
                    }
                }
            }
            self::setFilters($filters);
        }
    }

    /**
     * Check if hook exists
     *
     * @param string $hook Hook name
     * @return bool
     */
    public static function hasHook(string $hook): bool
    {
        $actions = self::getActions();
        $filters = self::getFilters();

        return isset($actions[$hook]) || isset($filters[$hook]);
    }

    /**
     * Clear all hooks (called when Octane resets)
     */
    public static function reset(): void
    {
        self::resolveStore('hook.actions')->exchangeArray([]);
        self::resolveStore('hook.filters')->exchangeArray([]);
        self::resolveStore('hook.owners')->exchangeArray([]);
    }
}
