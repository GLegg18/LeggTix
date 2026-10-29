<?php

namespace App\Http\Controllers;

use App\Actions\ManageEventAction;
use App\Enums\UserRole;
use App\Http\Requests\CancelManagedEventRequest;
use App\Http\Requests\ListManagedEventsRequest;
use App\Http\Requests\WriteManagedEventRequest;
use App\Http\Resources\ManagedEventResource;
use App\Http\Resources\ManagedEventTypeResource;
use App\Http\Resources\ManagedReservationResource;
use App\Http\Resources\ManagedWaitlistResource;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ManagedEventController
{
    public function __construct(
        private readonly ManageEventAction $manage,
        private readonly ConcurrencyErrorDetector $concurrencyErrors,
    ) {}

    public function index(ListManagedEventsRequest $request): JsonResponse
    {
        $query = $this->events()->when($request->user()->role !== UserRole::Admin,
            fn (Builder $query) => $query->where('owner_id', $request->user()->id));

        return ManagedEventResource::collection($query->orderByDesc('id')->cursorPaginate(
            (int) $request->validated('per_page', 20), cursor: $request->validated('cursor', ''),
        )->appends($request->safe()->only('per_page')))->response()->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('view', $event);

        return (new ManagedEventResource($this->events()->findOrFail($event->id)))
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function store(WriteManagedEventRequest $request): JsonResponse
    {
        return $this->mutation(fn () => $this->manage->create($request->user(), $request->validated()), 201);
    }

    public function update(WriteManagedEventRequest $request, Event $event): JsonResponse
    {
        return $this->mutation(fn () => $this->manage->update($request->user(), $event->id, $request->validated()));
    }

    public function destroy(CancelManagedEventRequest $request, Event $event): JsonResponse
    {
        return $this->mutation(fn () => $this->manage->cancel($request->user(), $event->id));
    }

    public function reservations(ListManagedEventsRequest $request, Event $event): JsonResponse
    {
        Gate::authorize('viewReservations', $event);
        $page = $event->reservations()->with('user:id,name')->orderBy('id')->cursorPaginate(
            (int) $request->validated('per_page', 20), cursor: $request->validated('cursor', ''),
        )->appends($request->safe()->only('per_page'));

        return ManagedReservationResource::collection($page)->response()->header('Cache-Control', 'no-store, private');
    }

    public function waitlist(ListManagedEventsRequest $request, Event $event): JsonResponse
    {
        Gate::authorize('viewWaitlist', $event);
        $page = DB::table('waitlist_entries')->where('event_id', $event->id)->select('waitlist_entries.*')
            ->selectRaw('joined_at as cursor_joined_at')->orderBy('cursor_joined_at')->orderBy('id')
            ->cursorPaginate((int) $request->validated('per_page', 20), cursor: $request->validated('cursor', ''))
            ->appends($request->safe()->only('per_page'));
        $names = User::query()->whereIn('id', $page->getCollection()->pluck('user_id'))->pluck('name', 'id');
        $page->getCollection()->each(function (object $entry) use ($names): void {
            $entry->user_name = $names[$entry->user_id];
        });

        return ManagedWaitlistResource::collection($page)->response()->header('Cache-Control', 'no-store, private');
    }

    public function types(ListManagedEventsRequest $request): JsonResponse
    {
        return ManagedEventTypeResource::collection(EventType::query()->where('is_active', true)->orderBy('id')
            ->cursorPaginate((int) $request->validated('per_page', 20), cursor: $request->validated('cursor', ''))
            ->appends($request->safe()->only('per_page')))->response()->header('Cache-Control', 'no-store, private');
    }

    private function events(): Builder
    {
        return Event::query()->with('eventType:id,slug,name,is_active')
            ->withExists('reservations as has_reservations')
            ->selectRaw('EXISTS(SELECT 1 FROM waitlist_entries WHERE waitlist_entries.event_id = events.id) AS has_waitlist');
    }

    private function mutation(Closure $operation, int $status = 200): JsonResponse
    {
        try {
            $event = $operation();
        } catch (QueryException|DeadlockException $exception) {
            if (! $this->concurrencyErrors->causedByConcurrencyError($exception)) {
                throw $exception;
            }

            return response()->json(['message' => 'Event management is busy. Please retry shortly.', 'code' => 'event_busy'], 503)
                ->header('Retry-After', '1')->header('Cache-Control', 'no-store, private');
        }

        return (new ManagedEventResource($this->events()->findOrFail($event->id)))
            ->response()->setStatusCode($status)->header('Cache-Control', 'no-store, private');
    }
}
