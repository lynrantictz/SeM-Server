<?php

namespace App\Http\Requests\Api\V1\Busines;

use Illuminate\Foundation\Http\FormRequest;

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
        return [
            'country_id' => 'required',
            'tin' => 'required|unique:vendors,tin',
            'name' => 'required',
            'email' => 'required|string|email|max:255|unique:users,email',
            'name' => 'required',
            'phone' => 'required|unique:vendors,phone',
            'address' => 'required',
        ];
    }
}
