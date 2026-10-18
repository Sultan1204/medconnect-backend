<?php

namespace App\Modules\Identity\Enums;

enum RoleName: string
{
    case Patient = 'patient';
    case Doctor = 'doctor';
    case PharmacyStaff = 'pharmacy_staff';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    /**
     * Roles that may self-register through the public API.
     *
     * @return list<string>
     */
    public static function selfRegistrable(): array
    {
        return [self::Patient->value, self::Doctor->value];
    }

    public function isPrivileged(): bool
    {
        return in_array($this, [self::Admin, self::SuperAdmin], true);
    }
}
