<?php
declare(strict_types=1);

/**
 * 为根域名绑定 DNS/CDN 管理账户，并同步域名池上的 DNS/CDN 就绪标记。
 */

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainRegistrarAccount;

class DomainDnsCdnBindingService
{
    public function __construct(
        private DomainRegistrarAccount $accountModel,
    ) {
    }

    /**
     * 拉取导入后为根域写入 DNS/CDN 账户（至少一项须大于 0）。
     */
    public function applyBindingToRootDomain(Domain $domain, int $dnsAccountId, int $cdnAccountId): void
    {
        if ($dnsAccountId <= 0 && $cdnAccountId <= 0) {
            return;
        }

        if ($dnsAccountId > 0) {
            $domain->setDnsAccountId($dnsAccountId);
            $domain->setDnsProvider($this->registrarCodeForAccount($dnsAccountId));
        }
        if ($cdnAccountId > 0) {
            $domain->setCdnAccountId($cdnAccountId);
            $domain->setCdnProvider($this->registrarCodeForAccount($cdnAccountId));
        }
        $domain->forceCheck(false)->save();

        $root = \strtolower(\trim($domain->getDomain()));
        $poolUpdate = ObjectManager::getInstance(DomainPool::class, [], false);
        $poolUpdate->clearQuery()->where(DomainPool::schema_fields_ROOT_DOMAIN, $root);

        if ($dnsAccountId > 0) {
            $poolUpdate->setData(DomainPool::schema_fields_DNS_STATUS, DomainPool::INFRA_STATUS_PENDING);
            $poolUpdate->setData(DomainPool::schema_fields_DNS_PROVIDER, $domain->getDnsProvider());
        }
        if ($cdnAccountId > 0) {
            $poolUpdate->setData(DomainPool::schema_fields_CDN_STATUS, DomainPool::INFRA_STATUS_PENDING);
        }
        $poolUpdate->update()->fetch();
    }

    private function registrarCodeForAccount(int $accountId): string
    {
        if ($accountId <= 0) {
            return '';
        }
        $acc = clone $this->accountModel;
        $acc->load($accountId);

        return $acc->getAccountId() > 0 ? (string) ($acc->getRegistrarCode() ?? '') : '';
    }
}
