<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ManagedEventResource extends EventResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $data['event_type']['is_active'] = $this->eventType->is_active;

        return $data + [
            'owner_id' => $this->owner_id,
            'confirmed_count' => $this->confirmed_count,
            'has_history' => (bool) $this->has_reservations || (bool) $this->has_waitlist,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
