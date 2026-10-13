<?php

namespace App\Http\Requests;

use App\Rules\BcryptPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8), 'confirmed', new BcryptPassword],
            'registration_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'role' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->query->has('registration_token')) {
                $validator->errors()->add('registration_token', 'Use the request body, not the query string.');
            }
        }];
    }
}
