<?php

namespace App\Enums\Business;

enum BusinessRoles: string
{
    case OWNER = 'owner';
    case MANAGER = 'manager';
    case STAFF = 'staff';
}
