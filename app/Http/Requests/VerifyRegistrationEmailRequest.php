<?php

namespace App\Http\Requests;

class VerifyRegistrationEmailRequest extends JsonAuthenticationRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']];
    }
}
