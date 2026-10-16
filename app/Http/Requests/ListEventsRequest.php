<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

class ListEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'required', 'string', 'max:512', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), ['per_page', 'cursor']) as $field) {
                $validator->errors()->add((string) $field, 'This query parameter is not accepted when listing events.');
            }

            if (! $this->query->has('cursor') || $validator->errors()->has('cursor')) {
                return;
            }

            $cursor = Cursor::fromEncoded($this->query('cursor'));
            $parameters = $cursor?->toArray() ?? [];
            $valid = ValidatorFacade::make($parameters, [
                'cursor_starts_at' => ['required', 'string', 'date_format:Y-m-d H:i:s.u'],
                'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                '_pointsToNextItems' => ['required', 'boolean:strict'],
            ])->passes();

            if (! $valid || count($parameters) !== 3) {
                $validator->errors()->add('cursor', 'The cursor must be copied from an event pagination response.');
            }
        }];
    }
}
