<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;

class BusinessUser extends Model
{
    protected $table = 'business_user';

    protected $fillable = [
        'business_id',
        'user_id',
        'title',
        'business_role',
        'business_staff_role_id',
        'is_active',
        'activated_at',
        'deactivated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function businessStaffRole()
    {
        return $this->belongsTo(BusinessStaffRole::class);
    }
}
