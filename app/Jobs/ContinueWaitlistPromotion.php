<?php

namespace App\Jobs;

use App\Actions\PromoteWaitlistAction;
use App\Models\Event;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ContinueWaitlistPromotion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('inventory');
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(PromoteWaitlistAction $promote): void
    {
        if (Event::query()->whereKey($this->eventId)->exists()) {
            $promote->execute($this->eventId);
        }
    }
}
