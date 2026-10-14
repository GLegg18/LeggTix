<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationEmailVerification extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['email', 'challenge_id', 'code_hash', 'encrypted_code', 'token_hash'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'token_expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
