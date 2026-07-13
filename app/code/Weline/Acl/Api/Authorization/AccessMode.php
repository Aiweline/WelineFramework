<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

/** Public scalar names for ACL access modes. */
final class AccessMode
{
    public const READ = 'read';
    public const EDIT = 'edit';

    private function __construct()
    {
    }
}
