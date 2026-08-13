<?php

namespace App\Http\Requests\Api\V1\Busines;

use App\Models\Location\Country;
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
     * Store vendor mobile numbers in canonical international digits-only form.
     * Example: Tanzania mobile 0712 345 678 becomes 255712345678.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('phone')) {
            return;
        }

        $vendor = $this->route('vendor');
        $countryId = $this->input('country_id') ?? $vendor?->country_id;
        $country = $countryId ? Country::find($countryId) : null;

        if (!$country) {
            return;
        }

        $phone = preg_replace('/\D+/', '', (string) $this->input('phone'));
        $countryCode = (string) $country->phone_code;

        if ($countryCode === '') {
            return;
        }

        $nationalNumber = $phone;
        while (str_starts_with($nationalNumber, $countryCode)) {
            $nationalNumber = substr($nationalNumber, strlen($countryCode));
        }

        $phone = $countryCode . ltrim($nationalNumber, '0');

        $this->merge(['phone' => $phone]);
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
                    'country_id' => 'required|exists:countries,id',
                    'tin' => 'required|unique:vendors,tin',
                    'name' => 'required',
                    'email' => 'required|string|email|max:255|unique:users,email',
                    'name' => 'required',
                    'phone' => ['required', 'string', 'regex:/^\d{8,15}$/', 'unique:vendors,phone'],
                    'address' => 'required',
                ];
                break;
            case "PUT":
                return [
                    'country_id' => 'sometimes|exists:countries,id',

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
                        'string',
                        'regex:/^\d{8,15}$/',
                        Rule::unique('vendors', 'phone')->ignore($this->vendor->id),
                    ],

                    'address' => 'sometimes',
                ];
        }

        return [];
    }
}
