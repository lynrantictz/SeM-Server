<?php

namespace App\Enums\User;

enum UserType: string
{
    case OWNER = 'owner';
    case VENDOR = 'vendor';
    case BUSINESS = 'business';
}
