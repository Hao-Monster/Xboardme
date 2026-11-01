<?php

declare(strict_types=1);

/**
 * Raw TCP fault-injection proxy / blackhole daemon for testing real C PhpRedis
 * against a real redis-server without any custom RESP client or server emulation.
 *
 * Usage:
 *   php tcp-fault-proxy-daemon.php BLACKHOLE <bindAddr> <sleepMs>
 *   php tcp-fault-proxy-daemon.php DROP_AFTER_UPSTREAM <bindAddr> <upstreamAddr> <matchToken>
 */
$mode = strtoupper((string) ($argv[1] ?? 'BLACKHOLE'));
$bindAddr = (string) ($argv[2] ?? '127.0.0.1:0');

$listener = @stream_socket_server("tcp://{$bindAddr}", $errno, $errstr);
if ($listener === false) {
    fwrite(STDERR, "Failed to bind TCP fault proxy: [{$errno}] {$errstr}\n");
    exit(1);
}
stream_set_blocking($listener, false);

$bound = stream_socket_get_name($listener, false);
fwrite(STDOUT, "READY {$bound}\n");
fflush(STDOUT);

if ($mode === 'BLACKHOLE') {
    $sleepMs = max(50, (int) ($argv[3] ?? 1500));
    /** @var array<int, array{sock: resource, releaseAt: float}> $held */
    $held = [];

    $deadline = microtime(true) + 30.0;
    while (microtime(true) < $deadline) {
        $read = [$listener];
        $write = null;
        $except = null;
        $changed = @stream_select($read, $write, $except, 0, 20000);
        if ($changed !== false && in_array($listener, $read, true)) {
            $client = @stream_socket_accept($listener, 0);
            if (is_resource($client)) {
                stream_set_blocking($client, false);
                $held[(int) $client] = [
                    'sock' => $client,
                    'releaseAt' => microtime(true) + ($sleepMs / 1000.0),
                ];
            }
        }

        $now = microtime(true);
        foreach ($held as $id => $entry) {
            // Drain incoming request bytes silently without replying
            @fread($entry['sock'], 8192);
            if ($now >= $entry['releaseAt']) {
                @fclose($entry['sock']);
                unset($held[$id]);
            }
        }
    }

    foreach ($held as $entry) {
        @fclose($entry['sock']);
    }
    @fclose($listener);
    exit(0);
}

if ($mode === 'DROP_AFTER_UPSTREAM') {
    $upstreamAddr = (string) ($argv[3] ?? '127.0.0.1:6379');
    $matchToken = strtolower((string) ($argv[4] ?? 'rpush'));

    /** @var array<int, array{client: resource, upstream: resource}> $sessions */
    $sessions = [];

    $deadline = microtime(true) + 30.0;
    while (microtime(true) < $deadline) {
        $read = [$listener];
        foreach ($sessions as $s) {
            $read[] = $s['client'];
            $read[] = $s['upstream'];
        }
        $write = null;
        $except = null;
        $changed = @stream_select($read, $write, $except, 0, 20000);
        if ($changed === false || $changed === 0) {
            continue;
        }

        if (in_array($listener, $read, true)) {
            $client = @stream_socket_accept($listener, 0);
            if (is_resource($client)) {
                $upstream = @stream_socket_client("tcp://{$upstreamAddr}", $uErr, $uStr, 1.0);
                if (is_resource($upstream)) {
                    stream_set_blocking($client, false);
                    stream_set_blocking($upstream, false);
                    $sessions[(int) $client] = [
                        'client' => $client,
                        'upstream' => $upstream,
                    ];
                } else {
                    @fclose($client);
                }
            }
        }

        foreach ($sessions as $id => $s) {
            if (in_array($s['client'], $read, true)) {
                $data = @fread($s['client'], 65536);
                if ($data === '' || $data === false) {
                    if (@feof($s['client'])) {
                        @fclose($s['client']);
                        @fclose($s['upstream']);
                        unset($sessions[$id]);
                        continue;
                    }
                } else {
                    stream_set_blocking($s['upstream'], true);
                    @fwrite($s['upstream'], $data);
                    @fflush($s['upstream']);

                    if (str_contains(strtolower($data), $matchToken)) {
                        // Wait briefly so real redis-server processes and replies to the command,
                        // then drop the client socket WITHOUT forwarding the ACK!
                        stream_set_timeout($s['upstream'], 0, 500000);
                        @fread($s['upstream'], 4096);
                        @fclose($s['client']);
                        @fclose($s['upstream']);
                        unset($sessions[$id]);
                        continue;
                    }

                    stream_set_blocking($s['upstream'], false);
                }
            }

            if (isset($sessions[$id]) && in_array($s['upstream'], $read, true)) {
                $reply = @fread($s['upstream'], 65536);
                if ($reply === '' || $reply === false) {
                    if (@feof($s['upstream'])) {
                        @fclose($s['client']);
                        @fclose($s['upstream']);
                        unset($sessions[$id]);
                        continue;
                    }
                } else {
                    stream_set_blocking($s['client'], true);
                    @fwrite($s['client'], $reply);
                    @fflush($s['client']);
                    stream_set_blocking($s['client'], false);
                }
            }
        }
    }

    foreach ($sessions as $s) {
        @fclose($s['client']);
        @fclose($s['upstream']);
    }
    @fclose($listener);
    exit(0);
}

@fclose($listener);
exit(1);
