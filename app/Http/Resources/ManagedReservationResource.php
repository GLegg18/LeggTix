<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ManagedReservationResource extends ReservationResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'user' => ['id' => $this->user->id, 'name' => $this->user->name],
        ];
    }
}
