<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerMachineCredential extends Model
{
    protected $table = 'v2_server_machine_credential';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'machine_id' => 'integer',
        'last_used_at' => 'integer',
        'revoked_at' => 'integer',
    ];
}
