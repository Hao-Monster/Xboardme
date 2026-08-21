<?php

namespace App\Data;

final readonly class ServerMachineEnrollmentCode
{
    public function __construct(
        public string $plainTextCode,
        public int $expiresAt
    ) {
    }
}
