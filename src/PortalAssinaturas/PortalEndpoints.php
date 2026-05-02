<?php

declare(strict_types=1);

namespace App\PortalAssinaturas;

final class PortalEndpoints
{
    public const UPLOAD = '/document/upload';
    public const CREATE_BATCH = '/document/createBatch';
    public const VALIDATE_SIGNATURES = '/document/ValidateSignatures';
    public const PACKAGE = '/document/package';
    public const DELETE_DOCUMENT = '/document/delete';
}
