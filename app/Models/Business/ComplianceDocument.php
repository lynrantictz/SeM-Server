<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceDocument extends BaseModel
{
    protected $fillable = [
        'vendor_id',
        'business_id',
        'compliance_document_type_id',
        'document_type',
        'document_type_name',
        'document_number',
        'original_filename',
        'disk',
        'storage_path',
        'mime_type',
        'file_size',
        'status',
        'issued_at',
        'expires_at',
        'uploaded_by',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(ComplianceDocumentReminder::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(ComplianceDocumentType::class, 'compliance_document_type_id');
    }
}
