<?php

namespace App\Enums\User;

enum UserType: string
{
    case AGENT = 'agent';
    case OWNER = 'owner';
    case VENDOR = 'vendor';
    case BUSINESS = 'business';
    case PAPERSTIC = 'paperstic';
}
