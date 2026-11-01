<?php

declare(strict_types=1);

/**
 * Lightweight single-process TCP RESP2 Redis test server for isolated PHPUnit
 * integration, queue lifecycle, cache batching, and fault-injection tests.
 */
$address = $argv[1] ?? '127.0.0.1:0';
$requirePassword = $argv[2] ?? '';

$server = @stream_socket_server("tcp://{$address}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Failed to bind loopback RESP server: [{$errno}] {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, false);

$boundName = stream_socket_get_name($server, false);
fwrite(STDOUT, "READY {$boundName}\n");
fflush(STDOUT);

/** @var array<int, resource> $clients */
$clients = [];
/** @var array<int, string> $buffers */
$buffers = [];
/** @var array<int, array{db: int, authed: bool, multi: bool, queue: array<int, array<int, string>>}> $states */
$states = [];

/**
 * Storage per DB index:
 *   strings: array<string, array{value: string, expiresAt: float|null}>
 *   lists:   array<string, array<int, string>>
 *   zsets:   array<string, array<string, float>>
 *   hashes:  array<string, array<string, string>>
 *   sets:    array<string, array<string, true>>
 *
 * @var array<int, array{
 *     strings: array<string, array{value: string, expiresAt: float|null}>,
 *     lists: array<string, array<int, string>>,
 *     zsets: array<string, array<string, float>>,
 *     hashes: array<string, array<string, string>>,
 *     sets: array<string, array<string, true>>
 * }> $databases
 */
$databases = [];

/** @var list<string> $commandLog */
$commandLog = [];

$faultMode = 'NONE';
$faultSleepMs = 0;

$ensureDb = static function (int $db) use (&$databases): void {
    if (!isset($databases[$db])) {
        $databases[$db] = [
            'strings' => [],
            'lists' => [],
            'zsets' => [],
            'hashes' => [],
            'sets' => [],
        ];
    }
};
$ensureDb(0);
$ensureDb(1);

$encodeResp = static function (mixed $value) use (&$encodeResp): string {
    if (is_array($value)) {
        if (array_key_exists('__simple', $value)) {
            return '+' . $value['__simple'] . "\r\n";
        }
        if (array_key_exists('__error', $value)) {
            return '-' . $value['__error'] . "\r\n";
        }
        $out = '*' . count($value) . "\r\n";
        foreach ($value as $item) {
            $out .= $encodeResp($item);
        }
        return $out;
    }
    if (is_int($value)) {
        return ':' . $value . "\r\n";
    }
    if ($value === null) {
        return "$-1\r\n";
    }
    $str = (string) $value;
    return '$' . strlen($str) . "\r\n" . $str . "\r\n";
};

$parseRespFrame = static function (string &$buffer): ?array {
    if ($buffer === '') {
        return null;
    }

    if ($buffer[0] !== '*') {
        $rn = strpos($buffer, "\r\n");
        if ($rn === false) {
            return null;
        }
        $line = substr($buffer, 0, $rn);
        $buffer = substr($buffer, $rn + 2);
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        return $parts === [] ? null : $parts;
    }

    $rn = strpos($buffer, "\r\n");
    if ($rn === false) {
        return null;
    }

    $count = (int) substr($buffer, 1, $rn - 1);
    $offset = $rn + 2;
    $args = [];

    for ($i = 0; $i < $count; $i++) {
        if (!isset($buffer[$offset])) {
            return null;
        }
        if ($buffer[$offset] !== '$') {
            return null;
        }
        $lineEnd = strpos($buffer, "\r\n", $offset);
        if ($lineEnd === false) {
            return null;
        }
        $len = (int) substr($buffer, $offset + 1, $lineEnd - $offset - 1);
        $offset = $lineEnd + 2;
        if ($len < 0) {
            $args[] = '';
            continue;
        }
        if (strlen($buffer) < $offset + $len + 2) {
            return null;
        }
        $args[] = substr($buffer, $offset, $len);
        $offset += $len + 2;
    }

    $buffer = substr($buffer, $offset);
    return $args;
};

$deleteKeyAllTypes = static function (int $db, string $key) use (&$databases, $ensureDb): int {
    $ensureDb($db);
    $existed = 0;
    foreach (['strings', 'lists', 'zsets', 'hashes', 'sets'] as $bucket) {
        if (array_key_exists($key, $databases[$db][$bucket])) {
            unset($databases[$db][$bucket][$key]);
            $existed = 1;
        }
    }
    return $existed;
};

$readString = static function (int $db, string $key) use (&$databases, $ensureDb): ?string {
    $ensureDb($db);
    if (!isset($databases[$db]['strings'][$key])) {
        return null;
    }
    $entry = $databases[$db]['strings'][$key];
    if ($entry['expiresAt'] !== null && microtime(true) >= $entry['expiresAt']) {
        unset($databases[$db]['strings'][$key]);
        return null;
    }
    return $entry['value'];
};

$sortZset = static function (array &$zset): void {
    uksort($zset, static function (string $a, string $b) use (&$zset): int {
        $cmp = $zset[$a] <=> $zset[$b];
        return $cmp !== 0 ? $cmp : strcmp($a, $b);
    });
};

$executeSingle = static function (int $db, array $args) use (
    &$databases,
    &$commandLog,
    &$faultMode,
    &$faultSleepMs,
    $ensureDb,
    $deleteKeyAllTypes,
    $readString,
    $sortZset
): mixed {
    $ensureDb($db);
    $cmd = strtoupper($args[0] ?? '');

    if ($cmd === 'XTEST_CMDLOG_RESET') {
        $commandLog = [];
        return ['__simple' => 'OK'];
    }
    if ($cmd === 'XTEST_CMDLOG_GET') {
        return $commandLog;
    }
    if ($cmd === 'XTEST_SET_FAULT') {
        $faultMode = strtoupper($args[1] ?? 'NONE');
        $faultSleepMs = isset($args[2]) ? (int) $args[2] : 0;
        return ['__simple' => 'OK'];
    }
    if ($cmd === 'XTEST_SHUTDOWN') {
        return ['__simple' => 'BYE'];
    }

    if ($faultMode === 'ERROR_ON_HORIZON') {
        foreach (array_slice($args, 1) as $arg) {
            if (str_contains($arg, '_horizon:')) {
                return ['__error' => 'ERR simulated horizon metadata redis failure'];
            }
        }
    }

    if (!in_array($cmd, ['PING', 'AUTH', 'SELECT'], true)) {
        $commandLog[] = sprintf('DB:%d %s %s', $db, $cmd, implode(' ', array_slice($args, 1)));
    }

    switch ($cmd) {
        case 'PING':
            return isset($args[1]) ? $args[1] : ['__simple' => 'PONG'];

        case 'FLUSHDB':
            $databases[$db] = [
                'strings' => [],
                'lists' => [],
                'zsets' => [],
                'hashes' => [],
                'sets' => [],
            ];
            return ['__simple' => 'OK'];

        case 'FLUSHALL':
            $databases = [];
            $ensureDb(0);
            $ensureDb(1);
            return ['__simple' => 'OK'];

        case 'GET':
            return $readString($db, (string) ($args[1] ?? ''));

        case 'MGET':
            $keys = array_slice($args, 1);
            $values = [];
            foreach ($keys as $key) {
                $values[] = $readString($db, (string) $key);
            }
            return $values;

        case 'SET':
            $key = (string) ($args[1] ?? '');
            $val = (string) ($args[2] ?? '');
            $expiresAt = null;
            $nx = false;
            $xx = false;
            $count = count($args);
            for ($i = 3; $i < $count; $i++) {
                $opt = strtoupper((string) $args[$i]);
                if ($opt === 'EX' && isset($args[$i + 1])) {
                    $expiresAt = microtime(true) + max(0, (int) $args[++$i]);
                } elseif ($opt === 'PX' && isset($args[$i + 1])) {
                    $expiresAt = microtime(true) + (max(0, (int) $args[++$i]) / 1000.0);
                } elseif ($opt === 'NX') {
                    $nx = true;
                } elseif ($opt === 'XX') {
                    $xx = true;
                }
            }
            $existing = $readString($db, $key);
            if ($nx && $existing !== null) {
                return null;
            }
            if ($xx && $existing === null) {
                return null;
            }
            $databases[$db]['strings'][$key] = ['value' => $val, 'expiresAt' => $expiresAt];
            return ['__simple' => 'OK'];

        case 'SETEX':
            $key = (string) ($args[1] ?? '');
            $ttl = max(1, (int) ($args[2] ?? 1));
            $val = (string) ($args[3] ?? '');
            $databases[$db]['strings'][$key] = [
                'value' => $val,
                'expiresAt' => microtime(true) + $ttl,
            ];
            return ['__simple' => 'OK'];

        case 'DEL':
            $deleted = 0;
            foreach (array_slice($args, 1) as $key) {
                $deleted += $deleteKeyAllTypes($db, (string) $key);
            }
            return $deleted;

        case 'EXISTS':
            $count = 0;
            foreach (array_slice($args, 1) as $key) {
                $k = (string) $key;
                if ($readString($db, $k) !== null
                    || !empty($databases[$db]['lists'][$k])
                    || !empty($databases[$db]['zsets'][$k])
                    || !empty($databases[$db]['hashes'][$k])
                    || !empty($databases[$db]['sets'][$k])
                ) {
                    $count++;
                }
            }
            return $count;

        case 'EXPIRE':
            $key = (string) ($args[1] ?? '');
            $ttl = (int) ($args[2] ?? 0);
            if (isset($databases[$db]['strings'][$key])) {
                $databases[$db]['strings'][$key]['expiresAt'] = microtime(true) + $ttl;
                return 1;
            }
            return 1;

        case 'EXPIREAT':
            $key = (string) ($args[1] ?? '');
            $ts = (int) ($args[2] ?? 0);
            if (isset($databases[$db]['strings'][$key])) {
                $databases[$db]['strings'][$key]['expiresAt'] = (float) $ts;
                return 1;
            }
            return 1;

        case 'PEXPIRE':
            $key = (string) ($args[1] ?? '');
            $ttlMs = (int) ($args[2] ?? 0);
            if (isset($databases[$db]['strings'][$key])) {
                $databases[$db]['strings'][$key]['expiresAt'] = microtime(true) + ($ttlMs / 1000.0);
                return 1;
            }
            return 1;

        case 'INCR':
        case 'DECR':
        case 'INCRBY':
        case 'DECRBY':
            $key = (string) ($args[1] ?? '');
            $delta = match ($cmd) {
                'INCR' => 1,
                'DECR' => -1,
                'INCRBY' => (int) ($args[2] ?? 0),
                'DECRBY' => -1 * (int) ($args[2] ?? 0),
            };
            $cur = (int) ($readString($db, $key) ?? '0');
            $next = $cur + $delta;
            $expiresAt = $databases[$db]['strings'][$key]['expiresAt'] ?? null;
            $databases[$db]['strings'][$key] = ['value' => (string) $next, 'expiresAt' => $expiresAt];
            return $next;

        case 'HINCRBY':
            $key = (string) ($args[1] ?? '');
            $field = (string) ($args[2] ?? '');
            $delta = (int) ($args[3] ?? 0);
            $cur = (int) ($databases[$db]['hashes'][$key][$field] ?? '0');
            $next = $cur + $delta;
            $databases[$db]['hashes'][$key][$field] = (string) $next;
            return $next;

        case 'HINCRBYFLOAT':
            $key = (string) ($args[1] ?? '');
            $field = (string) ($args[2] ?? '');
            $delta = (float) ($args[3] ?? 0);
            $cur = (float) ($databases[$db]['hashes'][$key][$field] ?? '0');
            $next = $cur + $delta;
            $str = (string) $next;
            $databases[$db]['hashes'][$key][$field] = $str;
            return $str;

        case 'KEYS':
            $pattern = (string) ($args[1] ?? '*');
            $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
            $all = array_unique(array_merge(
                array_keys($databases[$db]['strings']),
                array_keys($databases[$db]['lists']),
                array_keys($databases[$db]['zsets']),
                array_keys($databases[$db]['hashes']),
                array_keys($databases[$db]['sets'])
            ));
            return array_values(array_filter($all, static fn (string $k): bool => preg_match($regex, $k) === 1));

        case 'RPUSH':
            $key = (string) ($args[1] ?? '');
            if (!isset($databases[$db]['lists'][$key])) {
                $databases[$db]['lists'][$key] = [];
            }
            foreach (array_slice($args, 2) as $val) {
                $databases[$db]['lists'][$key][] = (string) $val;
            }
            return count($databases[$db]['lists'][$key]);

        case 'LPOP':
            $key = (string) ($args[1] ?? '');
            if (empty($databases[$db]['lists'][$key])) {
                return null;
            }
            $val = array_shift($databases[$db]['lists'][$key]);
            if ($databases[$db]['lists'][$key] === []) {
                unset($databases[$db]['lists'][$key]);
            }
            return $val;

        case 'LLEN':
            $key = (string) ($args[1] ?? '');
            return count($databases[$db]['lists'][$key] ?? []);

        case 'LINDEX':
            $key = (string) ($args[1] ?? '');
            $index = (int) ($args[2] ?? 0);
            $list = $databases[$db]['lists'][$key] ?? [];
            if ($index < 0) {
                $index = count($list) + $index;
            }
            return $list[$index] ?? null;

        case 'LRANGE':
            $key = (string) ($args[1] ?? '');
            $start = (int) ($args[2] ?? 0);
            $stop = (int) ($args[3] ?? -1);
            $list = $databases[$db]['lists'][$key] ?? [];
            $len = count($list);
            if ($len === 0) {
                return [];
            }
            if ($start < 0) {
                $start = max(0, $len + $start);
            }
            if ($stop < 0) {
                $stop = $len + $stop;
            }
            if ($start > $stop || $start >= $len) {
                return [];
            }
            return array_slice($list, $start, $stop - $start + 1);

        case 'ZADD':
            $key = (string) ($args[1] ?? '');
            if (!isset($databases[$db]['zsets'][$key])) {
                $databases[$db]['zsets'][$key] = [];
            }
            $added = 0;
            $idx = 2;
            while (isset($args[$idx]) && in_array(strtoupper((string) $args[$idx]), ['NX', 'XX', 'CH', 'GT', 'LT'], true)) {
                $idx++;
            }
            while (isset($args[$idx], $args[$idx + 1])) {
                $score = (float) $args[$idx];
                $member = (string) $args[$idx + 1];
                if (!array_key_exists($member, $databases[$db]['zsets'][$key])) {
                    $added++;
                }
                $databases[$db]['zsets'][$key][$member] = $score;
                $idx += 2;
            }
            $sortZset($databases[$db]['zsets'][$key]);
            return $added;

        case 'ZCARD':
            $key = (string) ($args[1] ?? '');
            return count($databases[$db]['zsets'][$key] ?? []);

        case 'ZREM':
            $key = (string) ($args[1] ?? '');
            $removed = 0;
            foreach (array_slice($args, 2) as $member) {
                $m = (string) $member;
                if (isset($databases[$db]['zsets'][$key]) && array_key_exists($m, $databases[$db]['zsets'][$key])) {
                    unset($databases[$db]['zsets'][$key][$m]);
                    $removed++;
                }
            }
            if (empty($databases[$db]['zsets'][$key])) {
                unset($databases[$db]['zsets'][$key]);
            }
            return $removed;

        case 'ZRANGE':
        case 'ZREVRANGE':
            $key = (string) ($args[1] ?? '');
            $start = (int) ($args[2] ?? 0);
            $stop = (int) ($args[3] ?? -1);
            $zset = $databases[$db]['zsets'][$key] ?? [];
            $members = array_keys($zset);
            if ($cmd === 'ZREVRANGE') {
                $members = array_reverse($members);
            }
            $len = count($members);
            if ($len === 0) {
                return [];
            }
            if ($start < 0) {
                $start = max(0, $len + $start);
            }
            if ($stop < 0) {
                $stop = $len + $stop;
            }
            if ($start > $stop || $start >= $len) {
                return [];
            }
            return array_slice($members, $start, $stop - $start + 1);

        case 'ZRANGEBYSCORE':
        case 'ZREVRANGEBYSCORE':
            $key = (string) ($args[1] ?? '');
            $firstBound = $cmd === 'ZREVRANGEBYSCORE' ? ($args[3] ?? '-inf') : ($args[2] ?? '-inf');
            $secondBound = $cmd === 'ZREVRANGEBYSCORE' ? ($args[2] ?? '+inf') : ($args[3] ?? '+inf');
            $minRaw = strtolower((string) $firstBound);
            $maxRaw = strtolower((string) $secondBound);
            $min = $minRaw === '-inf' ? -INF : (float) ltrim($minRaw, '(');
            $max = ($maxRaw === '+inf' || $maxRaw === 'inf') ? INF : (float) ltrim($maxRaw, '(');
            $minExclusive = str_starts_with($minRaw, '(');
            $maxExclusive = str_starts_with($maxRaw, '(');

            $offset = 0;
            $limitCount = null;
            for ($i = 4; $i < count($args); $i++) {
                if (strtoupper((string) $args[$i]) === 'LIMIT' && isset($args[$i + 1], $args[$i + 2])) {
                    $offset = max(0, (int) $args[$i + 1]);
                    $rawCount = (int) $args[$i + 2];
                    $limitCount = $rawCount < 0 ? null : $rawCount;
                    break;
                }
            }

            $matched = [];
            foreach (($databases[$db]['zsets'][$key] ?? []) as $member => $score) {
                $geMin = $minExclusive ? ($score > $min) : ($score >= $min);
                $leMax = $maxExclusive ? ($score < $max) : ($score <= $max);
                if ($geMin && $leMax) {
                    $matched[] = (string) $member;
                }
            }
            if ($cmd === 'ZREVRANGEBYSCORE') {
                $matched = array_reverse($matched);
            }
            return $limitCount === null
                ? array_slice($matched, $offset)
                : array_slice($matched, $offset, $limitCount);

        case 'ZREMRANGEBYRANK':
            $key = (string) ($args[1] ?? '');
            $start = (int) ($args[2] ?? 0);
            $stop = (int) ($args[3] ?? 0);
            $zset = $databases[$db]['zsets'][$key] ?? [];
            $members = array_keys($zset);
            $len = count($members);
            if ($len === 0) {
                return 0;
            }
            if ($start < 0) {
                $start = max(0, $len + $start);
            }
            if ($stop < 0) {
                $stop = $len + $stop;
            }
            $removed = 0;
            for ($i = $start; $i <= min($stop, $len - 1); $i++) {
                unset($databases[$db]['zsets'][$key][$members[$i]]);
                $removed++;
            }
            if (empty($databases[$db]['zsets'][$key])) {
                unset($databases[$db]['zsets'][$key]);
            }
            return $removed;

        case 'ZREMRANGEBYSCORE':
            $key = (string) ($args[1] ?? '');
            $minRaw = strtolower((string) ($args[2] ?? '-inf'));
            $maxRaw = strtolower((string) ($args[3] ?? '+inf'));
            $min = $minRaw === '-inf' ? -INF : (float) $minRaw;
            $max = ($maxRaw === '+inf' || $maxRaw === 'inf') ? INF : (float) $maxRaw;
            $removed = 0;
            foreach (($databases[$db]['zsets'][$key] ?? []) as $member => $score) {
                if ($score >= $min && $score <= $max) {
                    unset($databases[$db]['zsets'][$key][$member]);
                    $removed++;
                }
            }
            return $removed;

        case 'HSET':
        case 'HMSET':
            $key = (string) ($args[1] ?? '');
            if (!isset($databases[$db]['hashes'][$key])) {
                $databases[$db]['hashes'][$key] = [];
            }
            $added = 0;
            for ($i = 2; isset($args[$i], $args[$i + 1]); $i += 2) {
                $field = (string) $args[$i];
                if (!array_key_exists($field, $databases[$db]['hashes'][$key])) {
                    $added++;
                }
                $databases[$db]['hashes'][$key][$field] = (string) $args[$i + 1];
            }
            return $cmd === 'HMSET' ? ['__simple' => 'OK'] : $added;

        case 'HGET':
            $key = (string) ($args[1] ?? '');
            $field = (string) ($args[2] ?? '');
            return $databases[$db]['hashes'][$key][$field] ?? null;

        case 'HMGET':
            $key = (string) ($args[1] ?? '');
            $out = [];
            foreach (array_slice($args, 2) as $field) {
                $out[] = $databases[$db]['hashes'][$key][(string) $field] ?? null;
            }
            return $out;

        case 'HGETALL':
            $key = (string) ($args[1] ?? '');
            $flat = [];
            foreach (($databases[$db]['hashes'][$key] ?? []) as $field => $val) {
                $flat[] = (string) $field;
                $flat[] = (string) $val;
            }
            return $flat;

        case 'HDEL':
            $key = (string) ($args[1] ?? '');
            $removed = 0;
            foreach (array_slice($args, 2) as $field) {
                $f = (string) $field;
                if (isset($databases[$db]['hashes'][$key][$f])) {
                    unset($databases[$db]['hashes'][$key][$f]);
                    $removed++;
                }
            }
            return $removed;

        case 'SADD':
            $key = (string) ($args[1] ?? '');
            if (!isset($databases[$db]['sets'][$key])) {
                $databases[$db]['sets'][$key] = [];
            }
            $added = 0;
            foreach (array_slice($args, 2) as $member) {
                $m = (string) $member;
                if (!isset($databases[$db]['sets'][$key][$m])) {
                    $databases[$db]['sets'][$key][$m] = true;
                    $added++;
                }
            }
            return $added;

        case 'SREM':
            $key = (string) ($args[1] ?? '');
            $removed = 0;
            foreach (array_slice($args, 2) as $member) {
                $m = (string) $member;
                if (isset($databases[$db]['sets'][$key][$m])) {
                    unset($databases[$db]['sets'][$key][$m]);
                    $removed++;
                }
            }
            return $removed;

        case 'SMEMBERS':
            $key = (string) ($args[1] ?? '');
            return array_keys($databases[$db]['sets'][$key] ?? []);

        case 'SCARD':
            $key = (string) ($args[1] ?? '');
            return count($databases[$db]['sets'][$key] ?? []);

        case 'EVAL':
            $script = (string) ($args[1] ?? '');
            $numKeys = (int) ($args[2] ?? 0);
            $keys = array_slice($args, 3, $numKeys);
            $argvEval = array_slice($args, 3 + $numKeys);

            // 1. LuaScripts::push()
            if (str_contains($script, "redis.call('rpush', KEYS[1], ARGV[1])")
                && str_contains($script, "redis.call('rpush', KEYS[2], 1)")
            ) {
                $databases[$db]['lists'][$keys[0]][] = (string) $argvEval[0];
                $databases[$db]['lists'][$keys[1]][] = '1';
                return null;
            }

            // 2. LuaScripts::size()
            if (str_contains($script, "return redis.call('llen', KEYS[1]) + redis.call('zcard', KEYS[2]) + redis.call('zcard', KEYS[3])")) {
                return count($databases[$db]['lists'][$keys[0]] ?? [])
                    + count($databases[$db]['zsets'][$keys[1]] ?? [])
                    + count($databases[$db]['zsets'][$keys[2]] ?? []);
            }

            // 3. LuaScripts::later()
            if (str_contains($script, "redis.call('zadd', KEYS[1], ARGV[1], ARGV[2])")) {
                $databases[$db]['zsets'][$keys[0]][(string) $argvEval[1]] = (float) $argvEval[0];
                $sortZset($databases[$db]['zsets'][$keys[0]]);
                return null;
            }

            // 4. LuaScripts::pop()
            if (str_contains($script, "local job = redis.call('lpop', KEYS[1])")
                && str_contains($script, "reserved['attempts'] = reserved['attempts'] + 1")
            ) {
                if (empty($databases[$db]['lists'][$keys[0]])) {
                    return [null, null];
                }
                $job = (string) array_shift($databases[$db]['lists'][$keys[0]]);
                if ($databases[$db]['lists'][$keys[0]] === []) {
                    unset($databases[$db]['lists'][$keys[0]]);
                }
                $decoded = json_decode($job, true);
                if (is_array($decoded)) {
                    $decoded['attempts'] = ((int) ($decoded['attempts'] ?? 0)) + 1;
                    $reserved = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $reserved = $job;
                }
                $databases[$db]['zsets'][$keys[1]][$reserved] = (float) $argvEval[0];
                $sortZset($databases[$db]['zsets'][$keys[1]]);
                if (!empty($databases[$db]['lists'][$keys[2]])) {
                    array_shift($databases[$db]['lists'][$keys[2]]);
                }
                return [$job, $reserved];
            }

            // 5. LuaScripts::release()
            if (str_contains($script, "redis.call('zrem', KEYS[2], ARGV[1])")
                && str_contains($script, "redis.call('zadd', KEYS[1], ARGV[2], ARGV[1])")
            ) {
                unset($databases[$db]['zsets'][$keys[1]][(string) $argvEval[0]]);
                $databases[$db]['zsets'][$keys[0]][(string) $argvEval[0]] = (float) $argvEval[1];
                $sortZset($databases[$db]['zsets'][$keys[0]]);
                return 1;
            }

            // 6. LuaScripts::migrateExpiredJobs()
            if (str_contains($script, "local val = redis.call('zrangebyscore', KEYS[1], '-inf', ARGV[1]")) {
                $maxScore = (float) $argvEval[0];
                $limit = isset($argvEval[1]) ? (int) $argvEval[1] : -1;
                $zset = $databases[$db]['zsets'][$keys[0]] ?? [];
                $val = [];
                foreach ($zset as $member => $score) {
                    if ($score <= $maxScore) {
                        $val[] = (string) $member;
                        if ($limit >= 0 && count($val) >= $limit) {
                            break;
                        }
                    }
                }
                if ($val !== []) {
                    foreach ($val as $member) {
                        unset($databases[$db]['zsets'][$keys[0]][$member]);
                        $databases[$db]['lists'][$keys[1]][] = $member;
                        $databases[$db]['lists'][$keys[2]][] = '1';
                    }
                }
                return $val;
            }

            // 7. LuaScripts::clear()
            if (str_contains($script, "redis.call('del', KEYS[1], KEYS[2], KEYS[3], KEYS[4])")) {
                $size = count($databases[$db]['lists'][$keys[0]] ?? [])
                    + count($databases[$db]['zsets'][$keys[1]] ?? [])
                    + count($databases[$db]['zsets'][$keys[2]] ?? []);
                foreach ($keys as $k) {
                    $deleteKeyAllTypes($db, (string) $k);
                }
                return $size;
            }

            return ['__error' => 'ERR unsupported eval script in loopback server'];

        default:
            return ['__error' => "ERR unknown command '{$cmd}'"];
    }
};

$running = true;
while ($running) {
    $read = array_merge([$server], $clients);
    $write = null;
    $except = null;

    $ready = @stream_select($read, $write, $except, 1, 0);
    if ($ready === false) {
        break;
    }

    foreach ($read as $sock) {
        if ($sock === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client !== false) {
                stream_set_blocking($client, false);
                $id = (int) $client;
                $clients[$id] = $client;
                $buffers[$id] = '';
                $states[$id] = [
                    'db' => 0,
                    'authed' => $requirePassword === '',
                    'multi' => false,
                    'queue' => [],
                ];
            }
            continue;
        }

        $id = (int) $sock;
        $chunk = @fread($sock, 65536);
        if ($chunk === '' || $chunk === false) {
            if (feof($sock) || $chunk === false) {
                @fclose($sock);
                unset($clients[$id], $buffers[$id], $states[$id]);
            }
            continue;
        }

        $buffers[$id] .= $chunk;

        while (($frame = $parseRespFrame($buffers[$id])) !== null) {
            $cmd = strtoupper($frame[0] ?? '');

            if (!in_array($cmd, ['XTEST_SET_FAULT', 'XTEST_CMDLOG_GET', 'XTEST_CMDLOG_RESET', 'XTEST_SHUTDOWN', 'AUTH', 'SELECT', 'PING'], true)) {
                if ($faultMode === 'DROP_BEFORE_WRITE') {
                    @fclose($sock);
                    unset($clients[$id], $buffers[$id], $states[$id]);
                    break;
                }
                if ($faultMode === 'BLACKHOLE') {
                    usleep(max(1, $faultSleepMs) * 1000);
                    @fclose($sock);
                    unset($clients[$id], $buffers[$id], $states[$id]);
                    break;
                }
            }

            if ($cmd === 'AUTH') {
                $givenPass = count($frame) >= 3 ? $frame[2] : ($frame[1] ?? '');
                if ($requirePassword === '' || hash_equals($requirePassword, $givenPass)) {
                    $states[$id]['authed'] = true;
                    @fwrite($sock, "+OK\r\n");
                } else {
                    @fwrite($sock, "-ERR invalid password\r\n");
                }
                continue;
            }

            if (!$states[$id]['authed'] && !str_starts_with($cmd, 'XTEST_')) {
                @fwrite($sock, "-NOAUTH Authentication required.\r\n");
                continue;
            }

            if ($cmd === 'SELECT') {
                $states[$id]['db'] = max(0, (int) ($frame[1] ?? 0));
                $ensureDb($states[$id]['db']);
                @fwrite($sock, "+OK\r\n");
                continue;
            }

            if ($cmd === 'MULTI') {
                $states[$id]['multi'] = true;
                $states[$id]['queue'] = [];
                @fwrite($sock, "+OK\r\n");
                continue;
            }

            if ($cmd === 'DISCARD') {
                $states[$id]['multi'] = false;
                $states[$id]['queue'] = [];
                @fwrite($sock, "+OK\r\n");
                continue;
            }

            if ($cmd === 'EXEC') {
                $queued = $states[$id]['queue'];
                $states[$id]['multi'] = false;
                $states[$id]['queue'] = [];
                $results = [];
                foreach ($queued as $qArgs) {
                    $results[] = $executeSingle($states[$id]['db'], $qArgs);
                }
                @fwrite($sock, $encodeResp($results));
                continue;
            }

            if ($states[$id]['multi']) {
                $states[$id]['queue'][] = $frame;
                @fwrite($sock, "+QUEUED\r\n");
                continue;
            }

            $reply = $executeSingle($states[$id]['db'], $frame);

            if ($faultMode === 'DROP_AFTER_EXEC' && !in_array($cmd, ['XTEST_SET_FAULT', 'XTEST_CMDLOG_GET', 'XTEST_CMDLOG_RESET', 'XTEST_SHUTDOWN', 'AUTH', 'SELECT', 'PING', 'CLIENT'], true)) {
                @fclose($sock);
                unset($clients[$id], $buffers[$id], $states[$id]);
                break;
            }

            @fwrite($sock, $encodeResp($reply));

            if ($cmd === 'XTEST_SHUTDOWN') {
                $running = false;
                break 2;
            }
        }
    }
}

foreach ($clients as $client) {
    @fclose($client);
}
@fclose($server);
