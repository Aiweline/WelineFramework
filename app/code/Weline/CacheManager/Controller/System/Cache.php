<?php

declare(strict_types=1);

namespace Weline\CacheManager\Controller\System;

use Weline\CacheManager\Cron\CacheCleanup;
use Weline\CacheManager\Service\CacheAdminService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_CacheManager::system_cache', '缓存管理', 'mdi mdi-database-cog-outline', '系统缓存状态管理')]
class Cache extends \Weline\Framework\App\Controller\BackendPageController
{
    public function __construct(
        private readonly CacheAdminService $cacheAdminService
    ) {
    }

    #[Acl('Weline_CacheManager::system_cache_index', '缓存列表', 'mdi mdi-view-list', '查看缓存列表')]
    public function index()
    {
        $filters = [
            'search' => \trim((string)($this->request->getParam('search') ?? '')),
            'type' => $this->request->getParam('type'),
            'status' => $this->request->getParam('status'),
            'permanently' => $this->request->getParam('permanently'),
        ];

        $dashboard = $this->cacheAdminService->buildDashboardData($filters);

        $this->assign('cacheItems', $dashboard['items']);
        $this->assign('summary', $dashboard['summary']);
        $this->assign('runtime', $dashboard['runtime']);
        $this->assign('cronTasks', $dashboard['cron_tasks']);
        $this->assign('filterSearch', $filters['search']);
        $this->assign('filterType', (string)($filters['type'] ?? ''));
        $this->assign('filterStatus', (string)($filters['status'] ?? ''));
        $this->assign('filterPermanently', (string)($filters['permanently'] ?? ''));

        return $this->fetch();
    }

    #[Acl('Weline_CacheManager::system_cache_status', '更新缓存状态', 'mdi mdi-toggle-switch', '启用或禁用缓存')]
    public function postStatus()
    {
        $identity = (string)$this->request->getParam('identity', '');
        if ($identity === '') {
            return $this->fetchJson(['code' => 403, 'msg' => __('参数 identity 不能为空'), 'data' => null]);
        }

        $enabled = !$this->isFalseLike($this->request->getParam('cache', true));

        try {
            $result = $this->cacheAdminService->updateStatuses([$identity], $enabled);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('操作成功！'),
                'data' => $result['enabled'],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['code' => 500, 'msg' => $throwable->getMessage(), 'data' => null]);
        }
    }

    #[Acl('Weline_CacheManager::system_cache_status_batch', '批量更新缓存状态', 'mdi mdi-toggle-switch-off-outline', '批量启用或禁用缓存')]
    public function postStatusBatch()
    {
        $identities = $this->request->getBodyParams(true)['identities'] ?? $this->request->getParam('identities', []);
        if (\is_string($identities) && $identities !== '') {
            $identities = \array_filter(\array_map('trim', \explode(',', $identities)));
        }
        if (!\is_array($identities)) {
            $identities = [];
        }
        if (empty($identities)) {
            return $this->fetchJson(['code' => 403, 'msg' => __('请至少选择一个缓存项'), 'data' => null]);
        }

        $enabled = !$this->isFalseLike($this->request->getParam('cache', true));

        try {
            $result = $this->cacheAdminService->updateStatuses($identities, $enabled);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('批量缓存状态已更新'),
                'data' => $result,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['code' => 500, 'msg' => $throwable->getMessage(), 'data' => null]);
        }
    }

    #[Acl('Weline_CacheManager::system_cache_clear', '清理缓存', 'mdi mdi-delete', '清理指定缓存池')]
    public function postClear()
    {
        $identity = (string)$this->request->getParam('identity', '');
        if ($identity === '') {
            return $this->fetchJson(['code' => 403, 'msg' => __('参数 identity 不能为空'), 'data' => null]);
        }

        $force = $this->isTruthy($this->request->getParam('force', false));

        try {
            $result = $this->cacheAdminService->clearPool($identity, $force);
            $message = (string)__('缓存池 %{1} 已清理', $identity);
            if ($result['shared_namespace_cleared'] ?? false) {
                $message .= ' ' . (string)__('WLS 共享命名空间已同步清理');
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => $message,
                'data' => $result,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['code' => 500, 'msg' => $throwable->getMessage(), 'data' => null]);
        }
    }

    #[Acl('Weline_CacheManager::system_cache_clear_all', '清理所有缓存', 'mdi mdi-delete-sweep', '清理所有非持久缓存池')]
    public function postClearAll()
    {
        $force = $this->isTruthy($this->request->getParam('force', false));

        try {
            $result = $this->cacheAdminService->clearAll($force);

            if ($force) {
                $message = (string)__('已强制清理所有缓存池（包括持久缓存）');
            } else {
                $message = (string)__('已清理所有非持久缓存池');
            }

            if (($result['extra_shared_namespaces_cleared_count'] ?? 0) > 0) {
                $message .= ' ' . (string)__('并额外清理 %{1} 个 WLS 共享缓存命名空间', $result['extra_shared_namespaces_cleared_count']);
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => $message,
                'data' => $result,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['code' => 500, 'msg' => $throwable->getMessage(), 'data' => null]);
        }
    }

    #[Acl('Weline_CacheManager::system_cache_stats', '缓存统计', 'mdi mdi-chart-bar', '查看缓存统计信息')]
    public function getStats()
    {
        try {
            $dashboard = $this->cacheAdminService->buildDashboardData();

            return $this->fetchJson([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'summary' => $dashboard['summary'],
                    'runtime' => $dashboard['runtime'],
                    'items' => $dashboard['items'],
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['code' => 500, 'msg' => $throwable->getMessage(), 'data' => null]);
        }
    }

    #[Acl('Weline_CacheManager::system_cache_run_cron', '执行定时任务', 'mdi mdi-play', '手动执行缓存清理定时任务')]
    public function postRunCronTask()
    {
        try {
            /** @var CacheCleanup $cacheCleanup */
            $cacheCleanup = ObjectManager::getInstance(CacheCleanup::class);
            $cacheCleanup->execute();

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('定时任务执行完成！已清理过期缓存文件。'),
                'data' => null,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('定时任务执行失败: %{1}。请检查 Cron 配置和日志。', $throwable->getMessage()),
                'data' => null,
            ]);
        }
    }

    #[Acl('Weline_CacheManager::system_cache_pool_stats', '单池统计', 'mdi mdi-chart-pie', '查看单个缓存池统计')]
    public function getPoolStats()
    {
        $identity = (string)$this->request->getParam('identity', '');
        if ($identity === '') {
            return $this->fetchJson(['code' => 403, 'msg' => __('参数 identity 不能为空'), 'data' => null]);
        }

        try {
            $stats = $this->cacheAdminService->getPoolStats($identity);

            return $this->fetchJson([
                'code' => 200,
                'msg' => 'success',
                'data' => $stats,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['code' => 500, 'msg' => $throwable->getMessage(), 'data' => null]);
        }
    }

    private function isFalseLike(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value === false;
        }

        $normalized = \strtolower(\trim((string)$value));
        return $normalized === 'false' || $normalized === '0' || $normalized === 'off';
    }

    private function isTruthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        $normalized = \strtolower(\trim((string)$value));
        return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
