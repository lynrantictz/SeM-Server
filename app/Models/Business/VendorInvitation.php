<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;

class VendorInvitation extends Model
{
    protected $fillable = ['vendor_id', 'user_id', 'token', 'requires_password_setup', 'expires_at', 'accepted_at'];

    protected $casts = ['requires_password_setup' => 'boolean', 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
