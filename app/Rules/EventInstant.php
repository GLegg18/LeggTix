<?php

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;

class EventInstant implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-3]):[0-5]\d|[+-]14:00)$/D', $value)) {
            $fail('The :attribute must be an ISO 8601 instant with seconds and an explicit Z or timezone offset.');

            return;
        }

        $normalized = preg_replace('/Z$/', '+00:00', $value);
        $format = str_contains($normalized, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $date = DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || (int) $date->setTimezone(new DateTimeZone('UTC'))->format('Y') < 1000
            || (int) $date->setTimezone(new DateTimeZone('UTC'))->format('Y') > 9999) {
            $fail('The :attribute must contain a valid calendar date and time.');
        }
    }
}
