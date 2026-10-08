<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

class ReserveEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Reservation::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        // PHP may omit raw multipart input, so validate declared media types first.
        if (($this->headers->has('content-type') || $this->getContent() !== '') && ! $this->isJson()) {
            throw new UnsupportedMediaTypeHttpException('Request bodies must use a JSON content type.');
        }

        if ($this->getContent() === '') {
            return;
        }

        // Laravel's parsed input treats malformed JSON as empty; validate the raw body first.
        try {
            $body = json_decode($this->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('The request body must contain valid JSON.');
        }

        if (! is_object($body)) {
            throw ValidationException::withMessages([
                'body' => ['The JSON request body must be an object.'],
            ]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            // Every booking field is server-owned; even empty overrides are rejected.
            foreach (array_keys($this->all()) as $field) {
                $validator->errors()->add((string) $field, 'This field is not accepted when reserving a place.');
            }
        }];
    }
}
