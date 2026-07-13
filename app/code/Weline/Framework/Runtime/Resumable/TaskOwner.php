<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Immutable access scope captured when a task is created.
 */
final readonly class TaskOwner
{
    /**
     * @param list<string> $acl
     */
    public function __construct(
        public string $area,
        public string $principal,
        public ?string $sessionId = null,
        public ?int $websiteId = null,
        public ?string $tenantId = null,
        public array $acl = [],
    ) {
        if (\trim($this->area) === '' || \trim($this->principal) === '') {
            throw new \InvalidArgumentException('Task owner area and principal are required.');
        }
        if ($this->websiteId !== null && $this->websiteId < 0) {
            throw new \InvalidArgumentException('Task owner website id must be zero or greater.');
        }
        foreach ($this->acl as $index => $permission) {
            if (!\is_int($index) || !\is_string($permission) || \trim($permission) === '') {
                throw new \InvalidArgumentException('Task owner ACL must be a list of non-empty strings.');
            }
        }
    }

    /**
     * @return array{area:string, principal:string, session_id:?string, website_id:?int, tenant_id:?string, acl:list<string>}
     */
    public function toArray(): array
    {
        return [
            'area' => $this->area,
            'principal' => $this->principal,
            'session_id' => $this->sessionId,
            'website_id' => $this->websiteId,
            'tenant_id' => $this->tenantId,
            'acl' => $this->acl,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}
