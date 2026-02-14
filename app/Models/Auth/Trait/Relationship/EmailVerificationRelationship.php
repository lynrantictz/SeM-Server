<?php

namespace App\Models\Auth\Trait\Relationship;

use App\Models\Auth\User;

trait EmailVerificationRelationship
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
