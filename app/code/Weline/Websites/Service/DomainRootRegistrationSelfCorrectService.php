<?php

declare(strict_types=1);

/**
 * 根域「注册状态」与运营事实不一致时的自我纠正。
 *
 * 1) 子域已在域名池可建站时，说明域名已注册且解析/证书链已通，根域仍停留在 pending 等多为同步滞后，将根域标为 active（correctBatch / correctRootIfOperationallyReady）。
 * 2) 定时扫描「未建站就绪」的根域，若该根域下所有子域都可建站，则用子域数据回填根域各字段（解析/HTTPS/可建站等），避免根域长期滞后。
 */

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;

class DomainRootRegistrationSelfCorrectService
{
    public function __construct(
        private readonly Domain $domainModel,
        private readonly DomainPool $poolModel,
    ) {
    }

    /**
     * 是否存在「运营上已就绪」的池子记录（可建站），可佐证根域已非注册中。
     */
    public function hasReadyPoolEvidence(Domain $root): bool
    {
        $id = $root->getDomainId();
        $rd = \strtolower(\trim($root->getDomain()));
        if ($rd === '') {
            return false;
        }
        if ($id > 0) {
            $n = (clone $this->poolModel)->clearQuery()
                ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $id)
                ->where(DomainPool::schema_fields_SITE_READY, 1)
                ->limit(1)
                ->select()
                ->fetchArray();
            if ($n !== []) {
                return true;
            }
        }
        $n2 = (clone $this->poolModel)->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, $rd)
            ->where(DomainPool::schema_fields_SITE_READY, 1)
            ->limit(1)
            ->select()
            ->fetchArray();

        return $n2 !== [];
    }

    public function isEligibleForPromotion(Domain $root): bool
    {
        $s = \strtolower(\trim($root->getStatus()));
        if ($s === Domain::STATUS_ACTIVE) {
            return false;
        }
        if (\in_array($s, [Domain::STATUS_SUSPENDED, Domain::STATUS_EXPIRED], true)) {
            return false;
        }

        return true;
    }

    /**
     * 若池子侧已可建站而根域仍非 active，将根域标为 active 并清理明显过期的注册商标记。
     */
    public function correctRootIfOperationallyReady(Domain $root): bool
    {
        if (!$this->isEligibleForPromotion($root)) {
            return false;
        }
        if (!$this->hasReadyPoolEvidence($root)) {
            return false;
        }
        $root->setStatus(Domain::STATUS_ACTIVE);
        if ($this->registrarStatusLooksLikeRegistering($root->getRegistrarStatus())) {
            $root->setRegistrarStatus('');
        }
        $root->save();
        w_log_info(
            __('根域状态自我纠正：%{1} 已标为正常（存在可建站子域）', [$root->getDomain()]),
            [],
            'domain_root_status_self_correct'
        );

        return true;
    }

    /**
     * 批量纠正：按「存在 site_ready=1 子域」的父根域 ID 与按 root_domain 关联的池记录扫描。
     *
     * @return int 实际写入库的根域条数
     */
    public function correctBatch(int $limit = 200): int
    {
        $done = 0;
        $seen = [];
        $parentRows = (clone $this->poolModel)->clearQuery()
            ->where(DomainPool::schema_fields_SITE_READY, 1)
            ->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, 0, '>')
            ->fields(DomainPool::schema_fields_PARENT_DOMAIN_ID)
            ->select()
            ->fetchArray();
        $parentIds = [];
        foreach ($parentRows as $row) {
            $pid = (int) ($row[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);
            if ($pid > 0) {
                $parentIds[$pid] = true;
            }
        }
        foreach (\array_keys($parentIds) as $domainId) {
            if ($done >= $limit) {
                break;
            }
            if (isset($seen[$domainId])) {
                continue;
            }
            $seen[$domainId] = true;
            $d = ObjectManager::getInstance(Domain::class, [], false);
            $d->clearQuery()->where(Domain::schema_fields_ID, $domainId)->find()->fetch();
            if (!$d->getDomainId()) {
                continue;
            }
            if ($this->correctRootIfOperationallyReady($d)) {
                $done++;
            }
        }
        $rootRows = (clone $this->poolModel)->clearQuery()
            ->where(DomainPool::schema_fields_SITE_READY, 1)
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, '', '!=')
            ->fields(DomainPool::schema_fields_ROOT_DOMAIN)
            ->select()
            ->fetchArray();
        $rootNames = [];
        foreach ($rootRows as $row) {
            $rn = \strtolower(\trim((string) ($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? '')));
            if ($rn !== '') {
                $rootNames[$rn] = true;
            }
        }
        foreach (\array_keys($rootNames) as $domainName) {
            if ($done >= $limit) {
                break;
            }
            $candidates = (clone $this->domainModel)->clearQuery()
                ->where(Domain::schema_fields_DOMAIN, $domainName)
                ->select()
                ->fetchArray();
            foreach ($candidates as $row) {
                if ($done >= $limit) {
                    break 2;
                }
                $id = (int) ($row[Domain::schema_fields_ID] ?? 0);
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $d = ObjectManager::getInstance(Domain::class, [], false);
                $d->setData($row);
                if ($this->correctRootIfOperationallyReady($d)) {
                    $done++;
                }
            }
        }

        return $done;
    }

    /**
     * 获取该根域下所有子域（池记录），按 parent_domain_id 或 root_domain 关联
     *
     * @return list<array<string, mixed>>
     */
    public function getSubdomainsForRoot(Domain $root): array
    {
        $id = $root->getDomainId();
        $rd = \strtolower(\trim($root->getDomain()));
        if ($rd === '') {
            return [];
        }
        $query = (clone $this->poolModel)->clearQuery()
            ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE);
        if ($id > 0) {
            $query->where(DomainPool::schema_fields_PARENT_DOMAIN_ID, $id);
        } else {
            $query->where(DomainPool::schema_fields_ROOT_DOMAIN, $rd);
        }
        return $query->select()->fetchArray();
    }

    /**
     * 该根域下是否至少有一个子域，且全部子域都可建站（site_ready=1）
     */
    public function hasAllSubdomainsSiteReady(Domain $root): bool
    {
        $subdomains = $this->getSubdomainsForRoot($root);
        if ($subdomains === []) {
            return false;
        }
        foreach ($subdomains as $row) {
            if ((int) ($row[DomainPool::schema_fields_SITE_READY] ?? 0) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * 当该根域下所有子域都可建站时，用子域数据回填根域各字段（解析、HTTPS、可建站等）并保存
     *
     * @return bool 是否执行了更新
     */
    public function syncRootFieldsFromPoolWhenAllReady(Domain $root): bool
    {
        if (!$this->hasAllSubdomainsSiteReady($root)) {
            return false;
        }
        $subdomains = $this->getSubdomainsForRoot($root);
        if ($subdomains === []) {
            return false;
        }
        // 选一条代表子域（优先根域同名即 apex，或 www，否则第一条）
        $rep = null;
        foreach ($subdomains as $row) {
            $d = \strtolower(\trim((string) ($row[DomainPool::schema_fields_DOMAIN] ?? '')));
            $r = \strtolower(\trim((string) ($row[DomainPool::schema_fields_ROOT_DOMAIN] ?? '')));
            if ($d === $r || $d === 'www.' . $r) {
                $rep = $row;
                break;
            }
        }
        if ($rep === null) {
            $rep = $subdomains[0];
        }
        $now = \date('Y-m-d H:i:s');
        $root->setStatus(Domain::STATUS_ACTIVE);
        if ($this->registrarStatusLooksLikeRegistering($root->getRegistrarStatus())) {
            $root->setRegistrarStatus('');
        }
        $root->setResolveStatus(Domain::RESOLVE_STATUS_RESOLVED);
        $root->setResolvedIp((string) ($rep[DomainPool::schema_fields_RESOLVED_IP] ?? ''));
        $root->setResolvedIpv6((string) ($rep[DomainPool::schema_fields_RESOLVED_IPV6] ?? ''));
        $root->setIsLocalServer(true);
        $root->setResolveCheckedAt((string) ($rep[DomainPool::schema_fields_RESOLVE_CHECKED_AT] ?? $now));
        $root->setResolveError('');
        $root->setHttpsStatus(Domain::HTTPS_STATUS_VALID);
        $root->setHttpsExpiresAt($this->earliestHttpsExpiresAt($subdomains));
        $root->setHttpsError('');
        $root->setHttpsRequestedAt($now);
        $root->setSiteReady(true);
        $root->save();
        w_log_info(
            __('根域字段已从子域回填：%{1}（子域均可建站）', [$root->getDomain()]),
            [],
            'domain_root_status_self_correct'
        );
        return true;
    }

    /**
     * 从多条池记录中取最早的有效 https_expires_at（非空）
     */
    private function earliestHttpsExpiresAt(array $poolRows): ?string
    {
        $min = null;
        foreach ($poolRows as $row) {
            $v = \trim((string) ($row[DomainPool::schema_fields_HTTPS_EXPIRES_AT] ?? ''));
            if ($v === '') {
                continue;
            }
            if ($min === null || \strcmp($v, $min) < 0) {
                $min = $v;
            }
        }
        return $min;
    }

    /**
     * 定时扫描「未建站就绪」的根域，若某根域下所有子域都可建站则回填根域各字段
     *
     * @param int $limit 最多处理根域数量
     * @return int 实际更新的根域条数
     */
    public function syncRootFieldsFromPoolBatch(int $limit = 200): int
    {
        $done = 0;
        $rows = (clone $this->domainModel)->clearQuery()
            ->where(Domain::schema_fields_SITE_READY, 0)
            ->where(Domain::schema_fields_STATUS, Domain::STATUS_SUSPENDED, '!=')
            ->where(Domain::schema_fields_STATUS, Domain::STATUS_EXPIRED, '!=')
            ->order(Domain::schema_fields_DOMAIN, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        foreach ($rows as $row) {
            if ($done >= $limit) {
                break;
            }
            $d = ObjectManager::getInstance(Domain::class, [], false);
            $d->setData($row);
            if ($this->syncRootFieldsFromPoolWhenAllReady($d)) {
                $done++;
            }
        }
        return $done;
    }

    private function registrarStatusLooksLikeRegistering(string $raw): bool
    {
        $t = \strtolower(\trim($raw));
        if ($t === '') {
            return false;
        }
        if (\str_contains($t, 'pending') || \str_contains($t, 'register')) {
            return true;
        }
        if (\str_contains($raw, '注册')) {
            return true;
        }

        return false;
    }
}
