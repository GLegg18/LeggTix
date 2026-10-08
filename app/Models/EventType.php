<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventType extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    protected $attributes = ['is_active' => true];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function fromDateTime(mixed $value): mixed
    {
        // DATETIME has no offset; persist the UTC instant before dropping it.
        return empty($value) ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }
}
