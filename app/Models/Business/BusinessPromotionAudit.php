<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessPromotionAudit extends Model
{
    protected $guarded = ['uuid'];
    protected $casts = ['previous_values' => 'array', 'new_values' => 'array'];
    public function promotion(): BelongsTo { return $this->belongsTo(BusinessPromotion::class, 'promotion_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
