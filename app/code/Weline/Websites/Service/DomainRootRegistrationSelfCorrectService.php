<?php

declare(strict_types=1);

/**
 * 根域「注册状态」与运营事实不一致时的自我纠正。
 *
 * 子域已在域名池可建站时，说明域名已注册且解析/证书链已通，根域仍停留在 pending/正在注册 等多为同步滞后，定时任务据此将根域标为 active。
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
