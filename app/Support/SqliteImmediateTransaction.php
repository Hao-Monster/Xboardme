<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

final class SqliteImmediateTransaction
{
    /**
     * Execute a write transaction, using BEGIN IMMEDIATE on top-level SQLite
     * connections and delegating nested or non-SQLite transactions to Laravel.
     * Match Laravel's single-attempt default; retry-safe callers must opt in.
     *
     * @template TResult
     *
     * @param  Closure(Connection): TResult  $callback
     * @return TResult
     */
    public static function write(Closure $callback, int $attempts = 1): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite' && $connection->transactionLevel() === 0) {
            return self::run(static fn () => $callback($connection));
        }

        return $connection->transaction($callback, $attempts);
    }

    /**
     * Acquire SQLite's write reservation before any reads in the batch.
     *
     * Laravel uses PDO::beginTransaction() on PHP 8.3, which always starts a
     * deferred SQLite transaction. A later read-to-write upgrade can fail
     * immediately even when busy_timeout is configured, so queue batches and
     * write transactions use an explicit IMMEDIATE transaction instead while
     * keeping Laravel's connection transaction depth synchronized for nested
     * savepoints.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function run(Closure $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new LogicException('SQLite immediate transactions require the SQLite connection.');
        }

        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('SQLite immediate transactions cannot be nested.');
        }

        self::beginImmediate($connection);
        $committed = false;

        try {
            $result = $callback();
            self::commitImmediate($connection);
            $committed = true;
            self::finishCommit($connection);

            return $result;
        } catch (Throwable $exception) {
            if (! $committed) {
                self::rollBackImmediate($connection, $exception);
            }

            throw $exception;
        }
    }

    private static function beginImmediate(Connection $connection): void
    {
        (Closure::bind(function (): void {
            foreach ($this->beforeStartingTransaction as $callback) {
                $callback($this);
            }

            $this->reconnectIfMissingConnection();
            $this->getPdo()->exec('BEGIN IMMEDIATE TRANSACTION');
            $this->transactions = 1;
            $this->transactionsManager?->begin($this->getName(), $this->transactions);
            $this->fireConnectionEvent('beganTransaction');
        }, $connection, Connection::class))();
    }

    private static function commitImmediate(Connection $connection): void
    {
        (Closure::bind(function (): void {
            $this->fireConnectionEvent('committing');
            $this->getPdo()->exec('COMMIT');
            $this->transactions = 0;
        }, $connection, Connection::class))();
    }

    private static function finishCommit(Connection $connection): void
    {
        (Closure::bind(function (): void {
            $this->transactionsManager?->commit($this->getName(), 1, 0);
            $this->fireConnectionEvent('committed');
        }, $connection, Connection::class))();
    }

    private static function rollBackImmediate(Connection $connection, Throwable $exception): void
    {
        try {
            // PHP 8.3's pdo_sqlite does not reliably report manual
            // BEGIN IMMEDIATE transactions through inTransaction().
            $connection->getPdo()->exec('ROLLBACK');
        } catch (Throwable) {
            throw new RuntimeException(
                'The SQLite immediate transaction failed and could not be rolled back.',
                0,
                $exception
            );
        } finally {
            (Closure::bind(function (): void {
                $this->transactions = 0;
                $this->transactionsManager?->rollback($this->getName(), 0);
                $this->fireConnectionEvent('rollingBack');
            }, $connection, Connection::class))();
        }
    }
}
