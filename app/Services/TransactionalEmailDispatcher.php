<?php

namespace App\Services;

use App\Jobs\SendReservationConfirmationEmail;
use App\Jobs\SendRegistrationVerificationEmail;
use App\Jobs\SendLoginVerificationEmail;
use App\Jobs\SendWelcomeEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class TransactionalEmailDispatcher
{
    public function loginVerification(int $verificationId, string $challengeId): void
    {
        DB::afterCommit(function () use ($verificationId, $challengeId): void {
            try {
                Queue::connection()->push(new SendLoginVerificationEmail($verificationId, $challengeId), '', 'emails');
            } catch (Throwable $exception) {
                $this->logFailure('login_verification', $verificationId, $exception);

                throw new ServiceUnavailableHttpException(1, 'Login email could not be queued. Please request a new login code.');
            }
        });
    }

    public function registrationVerification(int $verificationId, string $challengeId): void
    {
        DB::afterCommit(function () use ($verificationId, $challengeId): void {
            try {
                Queue::connection()->push(new SendRegistrationVerificationEmail($verificationId, $challengeId), '', 'emails');
            } catch (Throwable $exception) {
                $this->logFailure('registration_verification', $verificationId, $exception);

                throw new ServiceUnavailableHttpException(1, 'Verification email could not be queued. Please request a new code.');
            }
        });
    }

    public function welcome(int $userId): void
    {
        $this->afterCommit(new SendWelcomeEmail($userId), 'welcome', $userId);
    }

    public function reservationConfirmed(int $reservationId): void
    {
        $this->afterCommit(new SendReservationConfirmationEmail($reservationId), 'reservation_confirmation', $reservationId);
    }

    private function afterCommit(ShouldQueue $job, string $type, int $referenceId): void
    {
        try {
            DB::afterCommit(function () use ($job, $type, $referenceId): void {
                try {
                    Queue::connection()->push($job, '', 'emails');
                } catch (Throwable $exception) {
                    $this->logFailure($type, $referenceId, $exception);
                }
            });
        } catch (Throwable $exception) {
            $this->logFailure($type, $referenceId, $exception);
        }
    }

    private function logFailure(string $type, int $referenceId, Throwable $exception): void
    {
        try {
            // Exception messages may contain transport credentials or serialized payloads.
            Log::error('Transactional email could not be queued.', [
                'email_type' => $type,
                'reference_id' => $referenceId,
                'exception_type' => $exception::class,
            ]);
        } catch (Throwable) {
            // A broken log destination must not fail an already committed account or booking.
        }
    }
}
