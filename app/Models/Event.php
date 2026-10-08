<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type_id', 'name', 'description', 'venue',
        'starts_at', 'ends_at', 'timezone', 'capacity',
    ];

    protected $attributes = ['status' => 'draft', 'confirmed_count' => 0];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'capacity' => 'integer',
            'confirmed_count' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function isPast(): bool
    {
        return $this->starts_at !== null && $this->starts_at->lessThanOrEqualTo(now('UTC'));
    }

    // This is schedule eligibility; allocation must recheck a locked event row.
    public function isBookable(): bool
    {
        return $this->status === EventStatus::Published
            && $this->starts_at !== null
            && $this->starts_at->greaterThan(now('UTC'));
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', EventStatus::Published)
            ->where('starts_at', '>', now('UTC')->format($this->getDateFormat()));
    }

    public function fromDateTime(mixed $value): mixed
    {
        // DATETIME has no offset; persist the UTC instant before dropping it.
        return empty($value) ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }

    protected function capacity(): Attribute
    {
        return Attribute::make(set: function (mixed $value): int {
            Validator::make(['capacity' => $value], [
                'capacity' => ['required', 'integer:strict', 'min:1', 'max:4294967295'],
            ])->validate();

            return $value;
        });
    }

    protected function timezone(): Attribute
    {
        return Attribute::make(set: function (mixed $value): string {
            Validator::make(['timezone' => $value], [
                'timezone' => ['required', 'string', 'max:64', 'timezone'],
            ])->validate();

            return $value;
        });
    }
}
