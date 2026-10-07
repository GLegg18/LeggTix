<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BcryptPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Bcrypt truncates after 72 bytes, including bytes in multibyte characters.
        if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
            $fail('The :attribute must be at most 72 bytes and must not contain null bytes.');
        }
    }
}
