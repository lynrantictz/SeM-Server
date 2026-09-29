<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPaymentLink extends BaseModel
{
    protected $fillable = [
        'order_id', 'token_hash', 'expires_at', 'revoked_at', 'created_by_user_id', 'recipient_phone_e164', 'delivery_initiator',
        'whatsapp_status', 'whatsapp_message_id', 'whatsapp_sent_at', 'whatsapp_failure_reason',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'whatsapp_sent_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
