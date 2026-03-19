<?php
declare(strict_types=1);

/**
 * 根域 Cron 锁定：默认可建站子域（@、www 等）全部 site_ready 后置 cron_resolved，
 * 非证书类定时任务跳过该根域及其池子。
 */
namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;

final class DomainCronLockService
{
    public function shouldSkipNonCertificateWorkForParentDomainId(int $parentDomainId): bool
    {
        if ($parentDomainId <= 0) {
            return false;
        }
        $d = ObjectManager::getInstance(Domain::class, [], false);
        $d->load($parentDomainId);

        return $d->getDomainId() > 0 && $d->isCronResolved();
    }

    /**
     * 按根域 FQDN 判断（无账户信息时：任一根域行匹配即跳过，多账户同根域罕见）
     */
    public function shouldSkipNonCertificateWorkForRootFqdn(string $rootFqdn): bool
    {
        $rootFqdn = \strtolower(\trim($rootFqdn));
        if ($rootFqdn === '') {
            return false;
        }
        $d = ObjectManager::getInstance(Domain::class, [], false);
        $rows = $d->clearQuery()
            ->where(Domain::schema_fields_DOMAIN, $rootFqdn)
            ->where(Domain::schema_fields_CRON_RESOLVED, 1)
            ->limit(1)
            ->select()
            ->fetchArray();

        return $rows !== [];
    }

    /**
     * 池行：用父域 ID；若无父域则把 domain 当根域查 Domain 表
     */
    public function shouldSkipNonCertificateWorkForPoolRow(array $row): bool
    {
        $parentId = (int) ($row[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);
        if ($parentId > 0) {
            return $this->shouldSkipNonCertificateWorkForParentDomainId($parentId);
        }
        $fq = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
        $root = (string) ($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? '');
        if ($root !== '') {
            return $this->shouldSkipNonCertificateWorkForRootFqdn($root);
        }

        return $this->shouldSkipNonCertificateWorkForRootFqdn($fq);
    }

    /**
     * 当默认前缀对应池全部 site_ready=1 时置位 cron_resolved
     *
     * @return bool 本次是否新置位为已锁定
     */
    public function evaluateAndSetCronResolved(Domain $root): bool
    {
        if ($root->getDomainId() <= 0 || $root->isCronResolved()) {
            return false;
        }
        if (!$this->allDefaultPrefixPoolsSiteReady($root)) {
            return false;
        }
        $root->setCronResolved(1);
        $root->setCronResolvedAt(\date('Y-m-d H:i:s'));
        $root->forceCheck(false)->save();

        return true;
    }

    /**
     * 在池记录保存后尝试根据父域置位（轻量，可频繁调用）
     */
    public function evaluateParentAfterPoolChange(int $parentDomainId): void
    {
        if ($parentDomainId <= 0) {
            return;
        }
        $root = ObjectManager::getInstance(Domain::class, [], false);
        $root->load($parentDomainId);
        if ($root->getDomainId() <= 0) {
            return;
        }
        $this->evaluateAndSetCronResolved($root);
    }

    public function allDefaultPrefixPoolsSiteReady(Domain $root): bool
    {
        $rootName = \strtolower(\trim($root->getDomain()));
        if ($rootName === '') {
            return false;
        }
        $gen = ObjectManager::getInstance(SubdomainGeneratorService::class);
        $prefixes = $gen->getDefaultPrefixes();
        $pool = ObjectManager::getInstance(DomainPool::class, [], false);
        foreach ($prefixes as $prefix) {
            $prefix = \trim((string) $prefix);
            if ($prefix === '' || $prefix === '@') {
                $fq = $rootName;
            } else {
                $fq = $prefix . '.' . $rootName;
            }
            $pool->clearQuery()
                ->where(DomainPool::schema_fields_DOMAIN, $fq)
                ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $root->getDomainId())
                ->find()
                ->fetch();
            if ($pool->getPoolId() <= 0 || !$pool->isSiteReady()) {
                return false;
            }
        }

        return true;
    }
}
