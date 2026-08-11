<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class ChangePhoneNumberRequest extends FormRequest
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
        return [
            'phone' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_string($value) && !is_int($value)) {
                        $fail('The phone field must be a string or integer.');
                        return;
                    }

                    if (is_string($value) && strlen($value) > 30) {
                        $fail('The phone field must not be greater than 30 characters.');
                    }
                },
            ]
        ];
    }
}
