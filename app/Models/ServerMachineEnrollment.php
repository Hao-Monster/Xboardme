<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerMachineEnrollment extends Model
{
    protected $table = 'v2_server_machine_enrollment';

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected $casts = [
        'machine_id' => 'integer',
        'revoke_existing' => 'boolean',
        'expires_at' => 'integer',
        'consumed_at' => 'integer',
    ];
}
