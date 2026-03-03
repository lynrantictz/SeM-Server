<?php

namespace App\Http\Requests\Api\V1\Business;

use Illuminate\Foundation\Http\FormRequest;

class BusinessRequest extends FormRequest
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
        switch($this->method()){
            CASE 'POST':
                return [
                    'district_id' => 'required|exists:districts,id',
                    'business_type_id' => 'required|exists:business_types,id',
                    'tin' => 'required|unique:businesses,tin',
                    'name' => 'required',
                    'location' => 'required',
                    'tax_allowed' => 'required',
                    'contacts' => 'required',
                    'contacts.*.contact' => 'required|unique:contacts,contact',
                ];
        }
    }
}
