<?php

namespace App\Support;

use App\Models\User;

final class SubscriptionCredentials
{
    public static function isCurrent(User $subscriber, bool $lock = false): bool
    {
        $query = User::query()->useWritePdo()
            ->whereKey($subscriber->id)
            ->where('token', (string) $subscriber->token);

        // Callers requesting a lock must already hold the delivery lock. Never
        // refresh the authenticated model: that would give an old token a new UUID.
        return $lock
            ? $query->select('id')->lockForUpdate()->first() !== null
            : $query->exists();
    }
}
