<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessPromotion extends BaseModel
{
    protected $guarded = ['uuid'];
    protected $casts = [
        'discount_percentage' => 'float',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by_user_id'); }
    /**
     * Legacy promotion-specific history records.
     *
     * This must not be named "audits", because BaseModel supplies the
     * Laravel Auditing polymorphic audits() relationship.
     */
    public function promotionAudits(): HasMany { return $this->hasMany(BusinessPromotionAudit::class, 'promotion_id'); }
}
