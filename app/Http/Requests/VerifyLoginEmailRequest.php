<?php

namespace App\Http\Requests;

class VerifyLoginEmailRequest extends JsonAuthenticationRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
            'remember_device' => ['sometimes', 'boolean:strict'],
        ];
    }
}
