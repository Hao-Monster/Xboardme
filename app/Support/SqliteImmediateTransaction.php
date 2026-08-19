<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

final class SqliteImmediateTransaction
{
    /**
     * Acquire SQLite's write reservation before any reads in the batch.
     *
     * Laravel uses PDO::beginTransaction() on PHP 8.3, which always starts a
     * deferred SQLite transaction. A later read-to-write upgrade can fail
     * immediately even when busy_timeout is configured, so queue batches use
     * an explicit IMMEDIATE transaction instead.
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

        $pdo = $connection->getPdo();
        $pdo->exec('BEGIN IMMEDIATE TRANSACTION');

        try {
            $result = $callback();
            $pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $exception) {
            try {
                // PHP 8.3's pdo_sqlite does not reliably report manual
                // BEGIN IMMEDIATE transactions through inTransaction().
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                throw new RuntimeException(
                    'The SQLite immediate transaction failed and could not be rolled back.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }
}
