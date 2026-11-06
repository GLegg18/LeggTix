<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

class ListManagedEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        if ($event !== null) {
            return $this->user()->can(str_ends_with($this->path(), '/waitlist') ? 'viewWaitlist' : 'viewReservations', $event);
        }

        return $this->user()->can('viewAny', Event::class);
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
                $validator->errors()->add((string) $field, 'This query parameter is not accepted.');
            }

            if (! $this->query->has('cursor') || $validator->errors()->has('cursor')) {
                return;
            }

            $parameters = Cursor::fromEncoded($this->query('cursor'))?->toArray() ?? [];
            $rules = [
                'id' => ['required', 'integer:strict', 'min:1', 'max:'.PHP_INT_MAX],
                '_pointsToNextItems' => ['required', 'boolean:strict'],
            ];
            if (str_ends_with($this->path(), '/waitlist')) {
                $rules['cursor_joined_at'] = ['required', 'string', 'date_format:Y-m-d H:i:s.u'];
            }

            if (count($parameters) !== count($rules) || ValidatorFacade::make($parameters, $rules)->fails()) {
                $validator->errors()->add('cursor', 'Copy the cursor unchanged from this list response.');
            }
        }];
    }
}
