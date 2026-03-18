<?php

declare(strict_types=1);

/**
 * 域名池维护：误同步清理、根域错误 A/AAAA 清理并移除池内相关子域
 */

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain as DomainModel;
use Weline\Websites\Model\DomainPool;

class DomainPoolMaintenanceService
{
    public function __construct(
        private readonly DomainPool $poolModel,
        private readonly DomainModel $domainModel,
        private readonly DomainResolveService $resolveService,
        private readonly ServerIpService $serverIpService,
    ) {
    }

    /**
     * 清理域名池中因同步错误产生的非法域名（如含 [] 的域名）
     *
     * @return array{deleted: int, domains: list<string>, dry_run: bool}
     */
    public function cleanInvalidPoolDomains(bool $dryRun): array
    {
        $all = $this->poolModel->clearQuery()
            ->fields(DomainPool::schema_fields_ID . ',' . DomainPool::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();
        $toDelete = [];
        foreach ($all as $row) {
            $domain = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
            if (\str_contains($domain, '[') || \str_contains($domain, ']')) {
                $toDelete[] = $row;
            }
        }
        $names = \array_map(static fn ($r) => (string) ($r[DomainPool::schema_fields_DOMAIN] ?? ''), $toDelete);
        if (!$dryRun && $toDelete !== []) {
            foreach ($toDelete as $r) {
                $pool = ObjectManager::getInstance(DomainPool::class, [], false);
                $pool->load((int) ($r[DomainPool::schema_fields_ID] ?? 0));
                if ($pool->getPoolId()) {
                    $pool->delete();
                }
            }
        }
        return [
            'deleted' => \count($toDelete),
            'domains' => $names,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * 根域 @ 的 A/AAAA 若未指向本机公网（且非 Cloudflare 橙云代理），则删除该记录；
     * 若对该根域执行了任意删除，则一并删除域名池中该根下所有条目（含子域）。
     *
     * @return array{roots_processed: int, dns_deleted: list<array>, pool_removed: int, errors: list<string>, dry_run: bool}
     */
    public function cleanMispointedApexDnsAndPool(bool $dryRun): array
    {
        $serverV4 = $this->serverIpService->getPublicIpv4();
        if ($serverV4 === '') {
            return [
                'roots_processed' => 0,
                'dns_deleted' => [],
                'pool_removed' => 0,
                'errors' => [__('无法获取本机公网 IPv4，请在 env 中配置 server.public_ip 或确保网络可访问外网 IP 检测接口')],
                'dry_run' => $dryRun,
            ];
        }
        $serverV6 = $this->serverIpService->getPublicIpv6();

        $rows = $this->poolModel->clearQuery()
            ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
            ->fields(DomainPool::schema_fields_PARENT_DOMAIN_ID)
            ->select()
            ->fetchArray();
        $parentIds = \array_values(\array_unique(\array_filter(\array_map(
            static fn ($r) => (int) ($r[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0),
            $rows
        ), static fn ($id) => $id > 0)));

        $dnsDeleted = [];
        $errors = [];
        $poolRemoved = 0;
        $rootsProcessed = 0;

        foreach ($parentIds as $domainId) {
            $domain = ObjectManager::getInstance(DomainModel::class, [], false);
            $domain->clearQuery()->load($domainId);
            if (!$domain->getDomainId()) {
                continue;
            }
            $rootsProcessed++;
            $rootName = \strtolower(\trim($domain->getDomain()));
            $dnsResult = $this->resolveService->getDnsManagementAccount($domain);
            if ($dnsResult['error'] !== '') {
                $errors[] = $rootName . ': ' . $dnsResult['error'];
                continue;
            }
            $account = $dnsResult['account'];
            $adapter = $dnsResult['adapter'];
            try {
                $records = $adapter->getDnsRecords($domain->getDomain(), $account->getCredentials());
            } catch (\Throwable $e) {
                $errors[] = $rootName . ': ' . $e->getMessage();
                continue;
            }

            $deletedForRoot = false;
            foreach ($records as $r) {
                $type = \strtoupper((string) ($r['type'] ?? ''));
                if (!\in_array($type, ['A', 'AAAA'], true)) {
                    continue;
                }
                if (!$this->isApexHost((string) ($r['host'] ?? '@'), $rootName)) {
                    continue;
                }
                if (!empty($r['proxied'])) {
                    continue;
                }
                $value = \trim((string) ($r['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                if ($this->serverIpService->isLocalServer($value)) {
                    continue;
                }
                if ($type === 'AAAA' && $serverV6 === '') {
                    continue;
                }

                $rid = (string) ($r['record_id'] ?? '');
                if ($rid === '') {
                    continue;
                }
                $dnsDeleted[] = [
                    'root' => $rootName,
                    'type' => $type,
                    'host' => $r['host'] ?? '@',
                    'value' => $value,
                    'record_id' => $rid,
                ];
                if (!$dryRun) {
                    $adapter->deleteDnsRecord($domain->getDomain(), $rid, $account->getCredentials());
                    $deletedForRoot = true;
                } else {
                    $deletedForRoot = true;
                }
            }

            if ($deletedForRoot) {
                if (!$dryRun) {
                    try {
                        $this->resolveService->syncDnsRecords($domain);
                    } catch (\Throwable) {
                    }
                }
                $poolRemoved += $this->removePoolByRootDomain($rootName, $dryRun);
            }
        }

        return [
            'roots_processed' => $rootsProcessed,
            'dns_deleted' => $dnsDeleted,
            'pool_removed' => $poolRemoved,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ];
    }

    private function isApexHost(string $host, string $rootDomain): bool
    {
        $h = \strtolower(\trim($host));
        $r = \strtolower(\trim($rootDomain));
        return $h === '@' || $h === '' || $h === $r;
    }

    private function removePoolByRootDomain(string $rootDomain, bool $dryRun): int
    {
        $rootDomain = \strtolower(\trim($rootDomain));
        $rows = $this->poolModel->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, $rootDomain)
            ->fields(DomainPool::schema_fields_ID)
            ->select()
            ->fetchArray();
        $n = \count($rows);
        if ($dryRun || $n === 0) {
            return $n;
        }
        foreach ($rows as $row) {
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->load((int) ($row[DomainPool::schema_fields_ID] ?? 0));
            if ($pool->getPoolId()) {
                $pool->delete();
            }
        }
        return $n;
    }
}
