<?php

namespace App\Models\Auth;

use App\Models\Auth\Trait\Relationship\EmailVerificationRelationship;
use Illuminate\Database\Eloquent\Model;

class EmailVerification extends Model
{
    use EmailVerificationRelationship;

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
