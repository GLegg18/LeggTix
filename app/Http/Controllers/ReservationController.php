<?php

namespace App\Http\Controllers;

use App\Actions\ReserveEventAction;
use App\Exceptions\ReservationRejectedException;
use App\Http\Requests\ReserveEventRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Event;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class ReservationController
{
    public function __construct(
        private readonly ReserveEventAction $reserve,
        private readonly ConcurrencyErrorDetector $concurrencyErrors,
    ) {}

    public function store(ReserveEventRequest $request, Event $event): JsonResponse
    {
        try {
            $reservation = $this->reserve->execute($request->user(), $event->id);
        } catch (ReservationRejectedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->reason->value,
            ], 409)->header('Cache-Control', 'no-store');
        } catch (QueryException|DeadlockException $exception) {
            if (! $this->concurrencyErrors->causedByConcurrencyError($exception)) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Reservations are busy. Please retry shortly.',
                'code' => 'reservation_busy',
            ], 503)->header('Retry-After', '1')->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'data' => new ReservationResource($reservation),
        ], 201)->header('Cache-Control', 'no-store');
    }
}
