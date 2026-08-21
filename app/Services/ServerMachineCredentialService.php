<?php

namespace App\Services;

use App\Data\ServerMachineEnrollmentCode;
use App\Models\ServerMachine;
use App\Models\ServerMachineCredential;
use App\Models\ServerMachineEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServerMachineCredentialService
{
    public function createEnrollment(
        ServerMachine $machine,
        bool $revokeExisting,
        int $ttlMinutes = 15
    ): ServerMachineEnrollmentCode {
        $plainTextCode = Str::random(48);
        $expiresAt = now()->addMinutes(max(1, min($ttlMinutes, 60)))->timestamp;

        DB::transaction(function () use ($machine, $revokeExisting, $plainTextCode, $expiresAt): void {
            ServerMachineEnrollment::query()
                ->where('machine_id', $machine->id)
                ->whereNull('consumed_at')
                ->delete();

            ServerMachineEnrollment::create([
                'machine_id' => $machine->id,
                'code_hash' => hash('sha256', $plainTextCode),
                'revoke_existing' => $revokeExisting,
                'expires_at' => $expiresAt,
            ]);
        });

        return new ServerMachineEnrollmentCode($plainTextCode, $expiresAt);
    }

    public function exchangeEnrollment(int $machineId, string $plainTextCode): string
    {
        return DB::transaction(function () use ($machineId, $plainTextCode): string {
            $machine = ServerMachine::query()->lockForUpdate()->find($machineId);
            $enrollment = ServerMachineEnrollment::query()
                ->where('machine_id', $machineId)
                ->where('code_hash', hash('sha256', $plainTextCode))
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now()->timestamp)
                ->lockForUpdate()
                ->first();

            if (!$machine || !$machine->is_active || !$enrollment) {
                throw ValidationException::withMessages([
                    'enrollment_code' => ['The enrollment code is invalid or expired.'],
                ]);
            }

            $now = now()->timestamp;
            $enrollment->forceFill(['consumed_at' => $now])->save();

            if ($enrollment->revoke_existing) {
                ServerMachineCredential::query()
                    ->where('machine_id', $machineId)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now, 'updated_at' => now()]);
            }

            $plainTextToken = Str::random(64);
            // Keep the legacy column synchronized during the rollback window.
            // Authentication uses the hash table; a later release can erase
            // this compatibility copy after the old runtime is retired.
            $machine->forceFill(['token' => $plainTextToken])->saveQuietly();
            ServerMachineCredential::create([
                'machine_id' => $machineId,
                'token_hash' => hash('sha256', $plainTextToken),
                'token_prefix' => substr($plainTextToken, 0, 12),
            ]);

            if (!$enrollment->revoke_existing) {
                $staleCredentialIds = ServerMachineCredential::query()
                    ->where('machine_id', $machineId)
                    ->whereNull('revoked_at')
                    ->orderByDesc('id')
                    ->skip(3)
                    ->take(1000)
                    ->pluck('id');
                if ($staleCredentialIds->isNotEmpty()) {
                    ServerMachineCredential::query()
                        ->whereIn('id', $staleCredentialIds)
                        ->update(['revoked_at' => $now, 'updated_at' => now()]);
                }
            }

            return $plainTextToken;
        }, 3);
    }

    public function authenticate(int $machineId, string $plainTextToken): ?ServerMachine
    {
        if ($machineId <= 0 || $plainTextToken === '') {
            return null;
        }

        $machine = ServerMachine::query()->find($machineId);
        if (!$machine || !$machine->is_active) {
            return null;
        }

        $tokenHash = hash('sha256', $plainTextToken);
        $credential = ServerMachineCredential::query()
            ->where('machine_id', $machineId)
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();

        if (!$credential && !ServerMachineCredential::query()->where('machine_id', $machineId)->exists()) {
            if ($machine->token !== '' && hash_equals($machine->token, $plainTextToken)) {
                ServerMachineCredential::query()->insertOrIgnore([
                    'machine_id' => $machineId,
                    'token_hash' => $tokenHash,
                    'token_prefix' => substr($plainTextToken, 0, 12),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $credential = ServerMachineCredential::query()
                    ->where('machine_id', $machineId)
                    ->where('token_hash', $tokenHash)
                    ->whereNull('revoked_at')
                    ->first();
            }
        }

        if (!$credential) {
            return null;
        }

        if ($credential->last_used_at === null || $credential->last_used_at < now()->subMinutes(5)->timestamp) {
            $credential->forceFill(['last_used_at' => now()->timestamp])->saveQuietly();
        }

        return $machine;
    }
}
