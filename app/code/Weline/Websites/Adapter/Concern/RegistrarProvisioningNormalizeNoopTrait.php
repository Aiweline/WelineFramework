<?php

declare(strict_types=1);

namespace Weline\Websites\Adapter\Concern;

/**
 * 一站式配置 DNS/CDN 账户字段：默认不特殊处理。
 *
 * @see DomainRegistrarInterface::normalizeProvisioningDnsCdnAccounts()
 */
trait RegistrarProvisioningNormalizeNoopTrait
{
    public function normalizeProvisioningDnsCdnAccounts(array $context): array
    {
        return [];
    }
}
