<?php

namespace App\Http\Requests\Api\V1\Busines;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorStoreRequest extends FormRequest
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
                    'country_id' => 'required',
                    'tin' => 'required|unique:vendors,tin',
                    'name' => 'required',
                    'email' => 'required|string|email|max:255|unique:users,email',
                    'name' => 'required',
                    'phone' => 'required|unique:vendors,phone',
                    'address' => 'required',
                ];
                break;
            case "PUT":
                return [
                    'country_id' => 'sometimes',

                    'tin' => [
                        'sometimes',
                        Rule::unique('vendors', 'tin')->ignore($this->vendor->id),
                    ],

                    'name' => 'sometimes',

                    'email' => [
                        'sometimes',
                        'string',
                        'email',
                        'max:255',
                        Rule::unique('users', 'email')->ignore($this->vendor->user_id),
                    ],

                    'phone' => [
                        'sometimes',
                        Rule::unique('vendors', 'phone')->ignore($this->vendor->id),
                    ],

                    'address' => 'sometimes',
                ];
        }

        return [];
    }
}
