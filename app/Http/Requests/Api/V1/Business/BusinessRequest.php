<?php

namespace App\Http\Requests\Api\V1\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
                    'latitude' => 'nullable|numeric|between:-90,90',
                    'longitude' => 'nullable|numeric|between:-180,180',
                    'tax_allowed' => 'required',
                    'contacts' => 'required|array|min:1',
                    'contacts.*.contact' => 'required|string|distinct|unique:business_contacts,contact',
                ];
            case 'PUT':
                return [
                    'district_id' => 'required|exists:districts,id',
                    'business_type_id' => 'required|exists:business_types,id',
                    'tin' => ['required', Rule::unique('businesses', 'tin')->ignore($this->business->id)],
                    'name' => 'required',
                    'location' => 'required',
                    'latitude' => 'nullable|numeric|between:-90,90',
                    'longitude' => 'nullable|numeric|between:-180,180',
                    'tax_allowed' => 'required|boolean',
                    'contacts' => 'required|array|min:1',
                    'contacts.*.contact' => 'required|string|distinct',
                ];
        }
    }
}
