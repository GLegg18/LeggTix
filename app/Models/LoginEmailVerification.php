<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginEmailVerification extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['challenge_id', 'credential_fingerprint', 'code_hash', 'encrypted_code'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'attempts' => 'integer', 'issued_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
