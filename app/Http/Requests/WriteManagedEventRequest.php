<?php

namespace App\Http\Requests;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Rules\EventInstant;
use Illuminate\Validation\Rule;

class WriteManagedEventRequest extends JsonAuthenticationRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST')
            ? $this->user()->can('create', Event::class)
            : $this->user()->can('update', $this->route('event'));
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'event_type_id' => [$presence, 'required', 'integer:strict', 'min:1', 'max:'.PHP_INT_MAX],
            'name' => [$presence, 'required', 'string', 'max:255'],
            'description' => [$presence, 'required', 'string', 'max:65535', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('The description must not exceed 65535 UTF-8 bytes.');
                }
            }],
            'venue' => [$presence, 'required', 'string', 'max:255'],
            'starts_at' => [$presence, 'required', new EventInstant],
            'ends_at' => ['sometimes', 'nullable', new EventInstant],
            'timezone' => [$presence, 'required', 'string', 'max:64', 'timezone'],
            'capacity' => [$presence, 'required', 'integer:strict', 'min:1', 'max:4294967295'],
            'status' => ['sometimes', 'required', Rule::enum(EventStatus::class)],
        ];
    }
}
