<?php
declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainPoolFlowLog;

final class DomainPoolFlowLogService
{
    public function append(int $poolId, string $eventKind, string $message = ''): void
    {
        if ($poolId <= 0) {
            return;
        }
        try {
            $log = ObjectManager::getInstance(DomainPoolFlowLog::class, [], false);
            $log->unsetData(DomainPoolFlowLog::schema_fields_ID);
            $log->setData(DomainPoolFlowLog::schema_fields_POOL_ID, $poolId);
            $log->setData(DomainPoolFlowLog::schema_fields_EVENT_KIND, $eventKind);
            $log->setData(DomainPoolFlowLog::schema_fields_MESSAGE, \mb_substr($message, 0, 2000));
            $log->save();
        } catch (\Throwable) {
        }
    }

    /**
     * @param list<int> $poolIds
     * @return array<int, list<array{kind: string, message: string, at: string}>>
     */
    public function getRecentByPoolIds(array $poolIds, int $perPool = 8): array
    {
        $poolIds = \array_values(\array_unique(\array_filter(\array_map('intval', $poolIds))));
        if ($poolIds === []) {
            return [];
        }
        $model = ObjectManager::getInstance(DomainPoolFlowLog::class);
        $rows = $model->clearQuery()
            ->where(DomainPoolFlowLog::schema_fields_POOL_ID, $poolIds, 'IN')
            ->order(DomainPoolFlowLog::schema_fields_ID, 'DESC')
            ->limit(\min(2000, \count($poolIds) * $perPool * 3))
            ->select()
            ->fetchArray();
        $out = [];
        foreach ($poolIds as $pid) {
            $out[$pid] = [];
        }
        foreach ($rows as $r) {
            $pid = (int) ($r[DomainPoolFlowLog::schema_fields_POOL_ID] ?? 0);
            if ($pid <= 0 || !isset($out[$pid]) || \count($out[$pid]) >= $perPool) {
                continue;
            }
            $out[$pid][] = [
                'kind' => (string) ($r[DomainPoolFlowLog::schema_fields_EVENT_KIND] ?? ''),
                'message' => (string) ($r[DomainPoolFlowLog::schema_fields_MESSAGE] ?? ''),
                'at' => (string) ($r[DomainPoolFlowLog::schema_fields_CREATED_AT] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * 生成列表用 HTML：绿=完成 灰=未到 红=失败（title 为原因）
     *
     * @param array<string, mixed> $poolRow
     * @param list<array{kind: string, message: string, at: string}> $recentLogs
     */
    public function buildFlowDisplayHtml(array $poolRow, array $recentLogs = []): string
    {
        $resolve = (string) ($poolRow[DomainPool::schema_fields_RESOLVE_STATUS] ?? '');
        $isLocal = !empty($poolRow[DomainPool::schema_fields_IS_LOCAL_SERVER]);
        $https = (string) ($poolRow[DomainPool::schema_fields_HTTPS_STATUS] ?? '');
        $siteReady = !empty($poolRow[DomainPool::schema_fields_SITE_READY]);
        $siteCreated = !empty($poolRow[DomainPool::schema_fields_SITE_CREATED]);
        $resolveErr = \trim((string) ($poolRow[DomainPool::schema_fields_RESOLVE_ERROR] ?? ''));
        $httpsErr = \trim((string) ($poolRow[DomainPool::schema_fields_HTTPS_ERROR] ?? ''));

        $certFailMsg = $httpsErr;
        foreach ($recentLogs as $lg) {
            if (($lg['kind'] ?? '') === DomainPoolFlowLog::KIND_CERT_FAIL && \trim((string) ($lg['message'] ?? '')) !== '') {
                $certFailMsg = \trim((string) $lg['message']);
                break;
            }
        }

        $arrow = ' <span class="text-muted small">→</span> ';
        $parts = [];

        $parts[] = '<span class="text-success fw-semibold" title="' . self::h(__('已入池')) . '">' . self::h(__('入池')) . ' ✓</span>';

        if ($resolve === DomainPool::RESOLVE_STATUS_ERROR) {
            $parts[] = '<span class="text-danger fw-semibold" title="' . self::h($resolveErr ?: __('解析异常')) . '">' . self::h(__('解析')) . ' ✗</span>';
        } elseif ($resolve === DomainPool::RESOLVE_STATUS_RESOLVED) {
            $parts[] = '<span class="text-success fw-semibold" title="' . self::h(__('已解析到 IP')) . '">' . self::h(__('解析')) . ' ✓</span>';
        } else {
            $parts[] = '<span class="text-secondary" title="' . self::h(__('等待解析检测')) . '">' . self::h(__('解析')) . ' …</span>';
        }

        if ($resolve === DomainPool::RESOLVE_STATUS_ERROR) {
            $parts[] = '<span class="text-secondary" title="">' . self::h(__('源站')) . ' …</span>';
        } elseif ($isLocal) {
            $parts[] = '<span class="text-success fw-semibold" title="' . self::h(__('记录指向本机源站')) . '">' . self::h(__('源站')) . ' ✓</span>';
        } elseif ($resolve === DomainPool::RESOLVE_STATUS_RESOLVED) {
            $parts[] = '<span class="text-secondary" title="' . self::h(__('未指向本机或 CDN 边缘')) . '">' . self::h(__('源站')) . ' …</span>';
        } else {
            $parts[] = '<span class="text-secondary" title="">' . self::h(__('源站')) . ' …</span>';
        }

        if ($https === DomainPool::HTTPS_STATUS_ERROR) {
            $parts[] = '<span class="text-danger fw-semibold" title="' . self::h($certFailMsg ?: __('证书申请失败')) . '">' . self::h(__('证书')) . ' ✗</span>';
        } elseif ($https === DomainPool::HTTPS_STATUS_VALID) {
            $parts[] = '<span class="text-success fw-semibold" title="' . self::h(__('证书有效')) . '">' . self::h(__('证书')) . ' ✓</span>';
        } elseif ($https === DomainPool::HTTPS_STATUS_PENDING) {
            $parts[] = '<span class="text-secondary" title="' . self::h(__('正在向 CA 申请')) . '">' . self::h(__('证书')) . ' …</span>';
        } else {
            $parts[] = '<span class="text-secondary" title="' . self::h(__('待申请')) . '">' . self::h(__('证书')) . ' …</span>';
        }

        if ($siteReady) {
            $parts[] = '<span class="text-success fw-semibold" title="' . self::h(__('可创建站点')) . '">' . self::h(__('可建站')) . ' ✓</span>';
        } elseif ($https === DomainPool::HTTPS_STATUS_ERROR || $resolve === DomainPool::RESOLVE_STATUS_ERROR) {
            $parts[] = '<span class="text-secondary" title="">' . self::h(__('可建站')) . ' …</span>';
        } else {
            $parts[] = '<span class="text-secondary" title="' . self::h(__('需证书有效等条件')) . '">' . self::h(__('可建站')) . ' …</span>';
        }

        if ($siteCreated) {
            $parts[] = '<span class="text-success fw-semibold" title="' . self::h(__('已绑定站点')) . '">' . self::h(__('已建站')) . ' ✓</span>';
        } else {
            $parts[] = '<span class="text-secondary" title="' . self::h(__('尚未绑定站点')) . '">' . self::h(__('已建站')) . ' …</span>';
        }

        $hintLines = [];
        foreach ($recentLogs as $lg) {
            $hintLines[] = ($lg['at'] ?? '') . ' [' . ($lg['kind'] ?? '') . '] ' . \mb_substr((string) ($lg['message'] ?? ''), 0, 200);
        }
        $titleAttr = $hintLines !== [] ? ' title="' . self::h(\implode("\n", \array_slice($hintLines, 0, 20))) . '"' : '';

        return '<div class="small text-break"' . $titleAttr . ' style="max-width:32rem;line-height:1.65;">' . \implode($arrow, $parts) . '</div>';
    }

    private static function h(string $s): string
    {
        return \htmlspecialchars($s, \ENT_QUOTES, 'UTF-8');
    }
}
