<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

class CancelManagedEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cancel', $this->route('event'));
    }

    public function rules(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        if (($this->headers->has('Content-Type') && ! $this->isJson())
            || ($this->getContent() !== '' && ! $this->isJson())) {
            throw new UnsupportedMediaTypeHttpException('Use no body or an empty JSON object.');
        }

        if ($this->getContent() === '') {
            return;
        }

        try {
            $body = json_decode($this->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('The request body must contain valid JSON.');
        }

        if (! is_object($body)) {
            throw ValidationException::withMessages(['body' => ['The JSON request body must be an object.']]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $field) {
                $validator->errors()->add((string) $field, 'This field is not accepted.');
            }
        }];
    }
}
