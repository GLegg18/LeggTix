<?php

namespace App\Http\Requests;

class StartRegistrationEmailVerificationRequest extends JsonAuthenticationRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255', 'unique:users,email']];
    }
}
