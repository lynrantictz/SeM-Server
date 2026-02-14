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
