<?php

declare(strict_types=1);

namespace Weline\Acl\Api;

interface RoleIdentityInterface
{
    public function getId(mixed $default = 0);
}
