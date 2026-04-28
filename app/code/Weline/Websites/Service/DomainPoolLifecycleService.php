<?php
declare(strict_types=1);

/**
 * 域名池生命周期阶段：解析任务只推进 registered/awaiting_origin → origin_ready；
 * 证书任务只处理 origin_ready/cert_pending → cert_valid。
 */

namespace Weline\Websites\Service;

use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainPoolFlowLog;

final class DomainPoolLifecycleService
{
    public function __construct(
        private DomainPoolFlowLogService $flowLog,
    ) {
    }

    public const STAGE_REGISTERED = 'registered';
    public const STAGE_AWAITING_ORIGIN = 'awaiting_origin';
    public const STAGE_ORIGIN_READY = 'origin_ready';
    public const STAGE_CERT_PENDING = 'cert_pending';
    public const STAGE_CERT_VALID = 'cert_valid';
    public const STAGE_SITE_LIVE = 'site_live';
    public const STAGE_BLOCKED = 'blocked';

    /** @return list<string> */
    public static function resolveCronStages(): array
    {
        return [self::STAGE_REGISTERED, self::STAGE_AWAITING_ORIGIN];
    }

    /** @return list<string> */
    public static function certificateCronStages(): array
    {
        return [self::STAGE_ORIGIN_READY, self::STAGE_CERT_PENDING];
    }

    /**
     * 解析定时任务单次运行结束时：仅根据本次检测结果推进「解析相关阶段」，不写 HTTPS。
     *
     * @param array{resolved: bool, is_local: bool} $checkResult
     */
    public function applyAfterResolvePass(DomainPool $pool, array $checkResult): void
    {
        $pid = $pool->getPoolId();
        if ($pool->isSiteCreated()) {
            $prev = $pool->getPoolLifecycleStage();
            $pool->setPoolLifecycleStage(self::STAGE_SITE_LIVE);
            $pool->save();
            if ($prev !== self::STAGE_SITE_LIVE && $pid > 0) {
                $this->flowLog->append($pid, DomainPoolFlowLog::KIND_STAGE_CHANGE, (string) __('阶段：已建站 (site_live)'));
            }

            return;
        }
        $prev = $pool->getPoolLifecycleStage();
        if (!$checkResult['resolved']) {
            $next = self::STAGE_REGISTERED;
        } elseif (!$checkResult['is_local']) {
            $next = self::STAGE_AWAITING_ORIGIN;
        } else {
            $next = self::STAGE_ORIGIN_READY;
        }
        if ($prev === $next) {
            return;
        }
        $pool->setPoolLifecycleStage($next);
        $pool->save();
        if ($pid > 0) {
            $this->flowLog->append($pid, DomainPoolFlowLog::KIND_STAGE_CHANGE, $prev . ' → ' . $next);
            if (!$checkResult['resolved'] && $pool->getResolveError() !== '') {
                $this->flowLog->append($pid, DomainPoolFlowLog::KIND_RESOLVE_CHECK, $pool->getResolveError());
            }
        }
    }

    public function markCertPending(DomainPool $pool): void
    {
        $pool->setPoolLifecycleStage(self::STAGE_CERT_PENDING);
        $pool->save();
    }

    public function markCertValid(DomainPool $pool): void
    {
        $pool->setPoolLifecycleStage(self::STAGE_CERT_VALID);
        $pool->save();
    }

    public function markOriginReadyAfterCertFailure(DomainPool $pool): void
    {
        $pool->setPoolLifecycleStage(self::STAGE_ORIGIN_READY);
        $pool->save();
    }

    /**
     * 升级回填：按当前字段推导阶段
     *
     * @param array<string, mixed> $row
     */
    public function deriveStageFromRow(array $row): string
    {
        if (!empty($row[DomainPool::schema_fields_SITE_CREATED])) {
            return self::STAGE_SITE_LIVE;
        }
        $https = (string) ($row[DomainPool::schema_fields_HTTPS_STATUS] ?? '');
        $resolve = (string) ($row[DomainPool::schema_fields_RESOLVE_STATUS] ?? '');
        $local = !empty($row[DomainPool::schema_fields_IS_LOCAL_SERVER]);
        if ($https === DomainPool::HTTPS_STATUS_VALID) {
            return self::STAGE_CERT_VALID;
        }
        if ($https === DomainPool::HTTPS_STATUS_PENDING) {
            return self::STAGE_CERT_PENDING;
        }
        if ($resolve === DomainPool::RESOLVE_STATUS_RESOLVED && $local) {
            return self::STAGE_ORIGIN_READY;
        }
        if ($resolve === DomainPool::RESOLVE_STATUS_RESOLVED && !$local) {
            return self::STAGE_AWAITING_ORIGIN;
        }

        return self::STAGE_REGISTERED;
    }

    public function backfillAllPoolStages(int $batchSize = 400): int
    {
        $pool = \Weline\Framework\Manager\ObjectManager::getInstance(DomainPool::class);
        $updated = 0;
        $offset = 0;
        do {
            $rows = $pool->clearQuery()
                ->order(DomainPool::schema_fields_ID, 'ASC')
                ->limit($batchSize, $offset)
                ->select()
                ->fetchArray();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $stage = $this->deriveStageFromRow($row);
                $current = (string) ($row[DomainPool::schema_fields_POOL_LIFECYCLE_STAGE] ?? '');
                if ($current === $stage) {
                    continue;
                }
                $m = \Weline\Framework\Manager\ObjectManager::getInstance(DomainPool::class, [], false);
                $m->setData($row);
                $m->setPoolLifecycleStage($stage);
                $m->save();
                $updated++;
            }
            $offset += $batchSize;
        } while (\count($rows) >= $batchSize);

        return $updated;
    }
}
