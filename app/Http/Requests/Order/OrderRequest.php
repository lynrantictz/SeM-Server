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
                    // Code and item validation is handled by the existing order flow.
                    'phone' => [
                        'required',
                        function (string $attribute, mixed $value, \Closure $fail): void {
                            if (!is_string($value) && !is_int($value)) {
                                $fail('The phone field must be a string or integer.');
                            }
                        },
                    ],
                    'items' => ['required', 'array', 'min:1'],
                    'channel' => ['sometimes', 'string', 'max:24'],
                    'items.*.uuid' => ['required', 'uuid'],
                    'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
                    'items.*.comment' => ['nullable', 'string', 'max:500'],
                ];
                break;
        }

        return [];
    }
}
