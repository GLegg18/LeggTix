<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManagedWaitlistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'user' => ['id' => $this->user_id, 'name' => $this->user_name],
        ];
        foreach (['joined_at', 'promoted_at', 'cancelled_at', 'created_at', 'updated_at'] as $field) {
            $data[$field] = $this->$field === null ? null : CarbonImmutable::parse($this->$field, 'UTC')->toISOString();
        }

        return $data;
    }
}
