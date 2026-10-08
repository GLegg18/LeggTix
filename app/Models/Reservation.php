<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['active_user_id'];

    protected $attributes = ['status' => 'confirmed'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromDateTime(mixed $value): mixed
    {
        // DATETIME has no offset; preserve the UTC instant before dropping it.
        return empty($value) ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }
}
