<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

abstract class RegistrationVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            throw new UnsupportedMediaTypeHttpException('Request bodies must use a JSON content type.');
        }

        try {
            $body = json_decode($this->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('The request body must contain valid JSON.');
        }

        if (! is_object($body)) {
            throw ValidationException::withMessages(['body' => ['The JSON request body must be an object.']]);
        }

        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add((string) $field, 'This field is not accepted.');
            }

            foreach (array_keys($this->query->all()) as $field) {
                $validator->errors()->add((string) $field, 'Use the JSON body, not the query string.');
            }
        }];
    }
}
