<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiTenant;

class AiTenantService
{
    private AiTenant $tenant;

    public function __construct(AiTenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function getById(int $id): AiTenant
    {
        $tenant = clone $this->tenant;
        $tenant->load($id);
        if (!$tenant->getId()) {
            throw new \RuntimeException("Tenant not found");
        }
        return $tenant;
    }

    public function getByDomain(string $domain): AiTenant
    {
        $tenant = clone $this->tenant;
        $tenant->load($domain, 'domain');
        if (!$tenant->getId()) {
            throw new \RuntimeException("Tenant not found");
        }
        return $tenant;
    }
}

