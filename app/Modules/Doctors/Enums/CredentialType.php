<?php

namespace App\Modules\Doctors\Enums;

enum CredentialType: string
{
    case ProfessionalLicense = 'professional_license';
    case Qualification = 'qualification';
    case Certification = 'certification';
    case IdentityDocument = 'identity_document';
    case Other = 'other';
}
