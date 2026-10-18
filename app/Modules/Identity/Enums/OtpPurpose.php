<?php

namespace App\Modules\Identity\Enums;

enum OtpPurpose: string
{
    case Registration = 'registration';
    case PasswordReset = 'password_reset';
    case EmailVerification = 'email_verification';
    case PhoneChange = 'phone_change';
}
