<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrustedDevice extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['selector', 'validator_hash', 'credential_fingerprint'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }
}
