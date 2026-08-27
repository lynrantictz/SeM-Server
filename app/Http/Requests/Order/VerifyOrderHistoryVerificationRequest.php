<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOrderHistoryVerificationRequest extends SendOrderHistoryVerificationRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'otp' => ['required', 'digits:4'],
        ];
    }
}
