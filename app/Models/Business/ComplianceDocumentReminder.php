<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceDocumentReminder extends Model
{
    protected $fillable = [
        'compliance_document_id',
        'user_id',
        'reminder_key',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ComplianceDocument::class, 'compliance_document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
