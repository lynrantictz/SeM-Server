<?php

namespace App\Http\Requests\Api\V1\User;

use App\Models\Location\Country;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterVendorUser extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $countryCode = strtoupper(trim((string) $this->input('countryCode')));
        $phone = preg_replace('/\D+/', '', (string) $this->input('phone'));
        $country = Country::query()->where('iso2', $countryCode)->first();

        if ($country && $phone !== '') {
            $callingCode = preg_replace('/\D+/', '', (string) $country->phone_code);

            if ($callingCode !== '' && str_starts_with($phone, $callingCode)) {
                $phone = substr($phone, strlen($callingCode));
            }

            $phone = $callingCode . ltrim($phone, '0');
        }

        $this->merge([
            'countryCode' => $countryCode,
            'phone' => $phone,
        ]);
    }

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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            //password must be at least 8 characters long and contain only letters, numbers and special characters
            'password' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->numbers()
                    ->symbols(),
            ],
            'countryCode' => ['required', 'string', 'size:2', 'exists:countries,iso2'],
            'phone' => ['required', 'string', 'regex:/^\d{10,15}$/', 'unique:users,phone'],

        ];
    }
}
