<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use RuntimeException;

final class DiagnosticDomainException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus = 400,
        private readonly array $context = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
