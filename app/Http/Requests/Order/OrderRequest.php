<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        switch ($this->method()) {
            case "POST":
                return [
                    'code' => ['required', 'string', 'max:255'],
                    'phone' => [
                        'nullable',
                        'required_without:guest_session',
                        function (string $attribute, mixed $value, \Closure $fail): void {
                            if ($value === null) {
                                return;
                            }

                            if (!is_string($value) && !is_int($value)) {
                                $fail('The phone field must be a string or integer.');
                            }
                        },
                    ],
                    'guest_session' => ['nullable', 'string', 'max:160'],
                    'items' => ['required', 'array', 'min:1'],
                    'channel' => ['sometimes', 'string', 'max:24'],
                    'items.*.uuid' => ['required', 'uuid'],
                    'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
                    'items.*.comment' => ['nullable', 'string', 'max:500'],
                    'items.*.options' => ['nullable', 'array'],
                    'items.*.options.*.uuid' => ['required', 'uuid'],
                ];
                break;
        }

        return [];
    }
}
