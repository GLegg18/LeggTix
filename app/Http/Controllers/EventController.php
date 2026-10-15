<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Http\Requests\ListEventsRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class EventController
{
    public function index(ListEventsRequest $request): JsonResponse
    {
        $events = Event::query()->upcoming()
            ->with('eventType:id,slug,name')
            // Carbon's string conversion loses microseconds; the raw alias preserves cursor boundaries.
            ->select('events.*')->addSelect('starts_at as cursor_starts_at')
            ->orderBy('cursor_starts_at')->orderBy('id')
            ->cursorPaginate((int) $request->validated('per_page', 20), cursor: $request->validated('cursor', ''))
            ->appends($request->safe()->only('per_page'));

        return EventResource::collection($events)->response()->header('Cache-Control', 'no-store');
    }

    public function show(string $event): JsonResponse
    {
        $event = Event::query()->where('status', EventStatus::Published)
            ->with('eventType:id,slug,name')->find($event);
        abort_if($event === null, 404);

        return (new EventResource($event))->response()->header('Cache-Control', 'no-store');
    }
}
