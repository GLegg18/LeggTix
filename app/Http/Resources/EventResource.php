<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'venue' => $this->venue,
            'starts_at' => $this->starts_at->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'timezone' => $this->timezone,
            'capacity' => $this->capacity,
            'remaining_capacity' => $this->capacity - $this->confirmed_count,
            'status' => $this->status->value,
            'event_type' => [
                'id' => $this->eventType->id,
                'slug' => $this->eventType->slug,
                'name' => $this->eventType->name,
            ],
        ];
    }
}
