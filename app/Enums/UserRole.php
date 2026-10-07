<?php

namespace App\Enums;

enum UserRole: string
{
    case RegularUser = 'regular_user';
    case Organiser = 'organiser';
    case Admin = 'admin';
}
