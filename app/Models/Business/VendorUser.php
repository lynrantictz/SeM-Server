<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;

class VendorUser extends Model
{
    protected $table = 'vendor_user';

    protected $fillable = [
        'vendor_id',
        'user_id',
        'is_primary',
        'role',
        'access_scope',
        'is_active',
        'invited_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
