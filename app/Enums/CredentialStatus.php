<?php

namespace App\Enums;

enum CredentialStatus: string
{
    case NotConfigured = 'not_configured';
    case Valid = 'valid';
    case PossiblyExpired = 'possibly_expired';
    case Rejected = 'rejected';
    case UnableToValidate = 'unable_to_validate';
}
