<?php

namespace App\Exceptions;

use App\Support\SubscriptionTiming;
use RuntimeException;
use Throwable;

class HwidObservationPersistenceException extends RuntimeException
{
    public const CATEGORY_DB_BUSY = 'db_busy';
    public const CATEGORY_DB_UNAVAILABLE = 'db_unavailable';
    public const CATEGORY_DB_WRITE_FAILED = 'db_write_failed';

    public const ALLOWED_CATEGORIES = [
        self::CATEGORY_DB_BUSY,
        self::CATEGORY_DB_UNAVAILABLE,
        self::CATEGORY_DB_WRITE_FAILED,
    ];

    private string $category;

    public function __construct(string $category)
    {
        $normalized = in_array($category, self::ALLOWED_CATEGORIES, true)
            ? $category
            : self::CATEGORY_DB_WRITE_FAILED;

        $this->category = $normalized;

        parent::__construct("HWID observation persistence failed: {$normalized}", 0, null);
    }

    public static function fromThrowable(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        $classified = SubscriptionTiming::classifyDatabaseException($exception);

        return match ($classified) {
            'db_busy' => new self(self::CATEGORY_DB_BUSY),
            'db_unavailable' => new self(self::CATEGORY_DB_UNAVAILABLE),
            default => new self(self::CATEGORY_DB_WRITE_FAILED),
        };
    }

    public function category(): string
    {
        return $this->category;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'error_category' => $this->category,
        ];
    }
}
