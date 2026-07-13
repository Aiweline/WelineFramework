<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

/** Immutable, ORM-free ACL resource projection for route auditing. */
final readonly class RouteResource
{
    public function __construct(
        private int $aclId,
        private string $sourceName,
    ) {
    }

    public function getAclId(): int
    {
        return $this->aclId;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }
}
