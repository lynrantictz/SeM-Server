<?php

namespace App\Http\Requests\Api\V1\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterVendorUser extends FormRequest
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
            //country code must be found in countries table
            // check country codes table if exists
            'country_code' => ['required', 'string', 'max:5'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],

        ];
    }
}
