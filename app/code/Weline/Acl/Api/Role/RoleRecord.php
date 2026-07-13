<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Role;

use Weline\Acl\Api\RoleIdentityInterface;

/** Immutable role data transferred across module boundaries. */
final readonly class RoleRecord implements RoleIdentityInterface
{
    public function __construct(
        private int $id,
        private string $name,
        private string $description,
    ) {
    }

    public function getId(mixed $default = 0): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
