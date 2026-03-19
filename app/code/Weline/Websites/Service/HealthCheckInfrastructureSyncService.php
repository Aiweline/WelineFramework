<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\WebsiteDomain;

/**
 * 将站点域名（WebsiteDomain）健康检查结果同步到域名池与根域（Domain / DomainPool），与 DomainConnectivityCheck 字段对齐。
 *
 * @see HealthCheckService::checkAllDomains
 */
final class HealthCheckInfrastructureSyncService
{
    private const DETAIL_MAX = 900;

    /**
     * @param array{
     *   status?: string,
     *   code?: int|null,
     *   message?: string,
     * } $checkResult
     */
    public function syncFromHealthProbe(int $websiteDomainId, array $checkResult): bool
    {
        try {
            $wd = ObjectManager::getInstance(WebsiteDomain::class, [], false);
            $wd->clearQuery()
                ->where(WebsiteDomain::schema_fields_ID, $websiteDomainId)
                ->find()
                ->fetch();
            if ($wd->getDomainId() <= 0) {
                return false;
            }

            $host = \strtolower(\trim($wd->getDomain()));
            $rootName = \strtolower(\trim($wd->getRootDomain() ?: ''));
            if ($rootName === '') {
                $rootName = $host;
            }

            $connectivityOk = ($checkResult['status'] ?? '') === WebsiteDomain::HEALTH_HEALTHY;
            $connStatus = $connectivityOk ? DomainPool::CONNECTIVITY_OK : DomainPool::CONNECTIVITY_ERROR;
            $checkedAt = \date('Y-m-d H:i:s');
            $detail = $this->buildDetail($checkResult);

            $poolModel = null;
            $didWrite = false;
            $poolId = (int) ($wd->getPoolId() ?? 0);
            if ($poolId > 0) {
                $pool = ObjectManager::getInstance(DomainPool::class, [], false);
                $pool->load($poolId);
                if ($pool->getPoolId() > 0) {
                    $poolModel = $pool;
                    $pool->setConnectivityStatus($connStatus);
                    $pool->setConnectivityCheckedAt($checkedAt);
                    $pool->setConnectivityDetail($detail);
                    $pool->setHttpsStatus($this->mapWebsiteDomainToHttpsStatus($wd));
                    $this->syncPoolCertIdFromWebsiteDomain($pool, $wd);
                    $pool->calculateSiteReady();
                    $pool->save();
                    $didWrite = true;
                }
            }

            $rootDomain = $this->resolveRootDomain($wd, $poolModel);
            if ($rootDomain !== null && $rootDomain->getDomainId() > 0) {
                $rootDomain->setConnectivityStatus($connStatus);
                $rootDomain->setConnectivityCheckedAt($checkedAt);
                $rootDomain->setConnectivityDetail($detail);
                if ($host === $rootName) {
                    $rootDomain->setHttpsStatus($this->mapWebsiteDomainToDomainHttpsStatus($wd));
                }
                $rootDomain->save();
                $didWrite = true;
            }

            return $didWrite;
        } catch (\Throwable $e) {
            w_log_warning(
                __('健康检查同步根域/池失败 domain_id=%{1}：%{2}', [(string) $websiteDomainId, $e->getMessage()]),
                [],
                'health_check'
            );

            return false;
        }
    }

    /**
     * @param array{status?: string, code?: int|null, message?: string} $checkResult
     */
    private function buildDetail(array $checkResult): string
    {
        $code = $checkResult['code'] ?? null;
        $msg = (string) ($checkResult['message'] ?? '');
        $line = '[HealthCheck]';
        if ($code !== null && $code > 0) {
            $line .= ' HTTP ' . (string) $code;
        }
        if ($msg !== '') {
            $line .= ' | ' . $msg;
        }
        if (\strlen($line) > self::DETAIL_MAX) {
            return \substr($line, 0, self::DETAIL_MAX) . '…';
        }

        return $line;
    }

    private function mapWebsiteDomainToHttpsStatus(WebsiteDomain $wd): string
    {
        if (!$wd->isHttpsEnabled()) {
            return DomainPool::HTTPS_STATUS_NONE;
        }

        return $wd->hasValidCertificate() ? DomainPool::HTTPS_STATUS_VALID : DomainPool::HTTPS_STATUS_ERROR;
    }

    private function mapWebsiteDomainToDomainHttpsStatus(WebsiteDomain $wd): string
    {
        if (!$wd->isHttpsEnabled()) {
            return Domain::HTTPS_STATUS_NONE;
        }

        return $wd->hasValidCertificate() ? Domain::HTTPS_STATUS_VALID : Domain::HTTPS_STATUS_ERROR;
    }

    private function syncPoolCertIdFromWebsiteDomain(DomainPool $pool, WebsiteDomain $wd): void
    {
        if ($wd->isHttpsEnabled()) {
            $cid = $wd->getCertId();
            if ($cid !== null && $cid > 0) {
                $pool->setCertId($cid);
            }
        } else {
            $pool->setCertId(null);
        }
    }

    private function resolveRootDomain(WebsiteDomain $wd, ?DomainPool $pool): ?Domain
    {
        if ($pool !== null && $pool->getParentDomainId() > 0) {
            $byParent = ObjectManager::getInstance(Domain::class, [], false);
            $byParent->load($pool->getParentDomainId());
            if ($byParent->getDomainId() > 0) {
                return $byParent;
            }
        }
        $root = \strtolower(\trim($wd->getRootDomain() ?: ''));
        if ($root === '') {
            $root = \strtolower(\trim($wd->getDomain()));
        }
        $byName = ObjectManager::getInstance(Domain::class, [], false);
        $byName->clearQuery()
            ->where(Domain::schema_fields_DOMAIN, $root)
            ->find()
            ->fetch();

        return $byName->getDomainId() > 0 ? $byName : null;
    }
}
