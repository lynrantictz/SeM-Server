<?php

namespace App\Http\Requests\Api\V1\Inquiry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
            'phone' => is_string($phone) ? preg_replace('/[\s()-]/', '', trim($phone)) : $phone,
            'message' => is_string($this->input('message')) ? trim($this->input('message')) : $this->input('message'),
        ]);
    }

    public function rules(): array
    {
        return [
            'package_code' => ['nullable', 'string', Rule::in(['start', 'business', 'premium', 'custom', 'general'])],
            'package_name' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:120'],
            'business_name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email:rfc,dns', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/', 'required_without:email'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required_without' => 'Provide an email address or a phone number so we can contact you.',
            'phone.required_without' => 'Provide a phone number or an email address so we can contact you.',
            'phone.regex' => 'Enter the phone number with its country code, for example +255 700 000 000.',
        ];
    }
}
