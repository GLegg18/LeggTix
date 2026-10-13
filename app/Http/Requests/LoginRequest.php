<?php

namespace App\Http\Requests;

use App\Rules\BcryptPassword;

class LoginRequest extends JsonAuthenticationRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', new BcryptPassword],
        ];
    }
}
