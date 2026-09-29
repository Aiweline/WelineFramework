<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Domain as DomainModel;
use Weline\Cdn\Model\WarmupUrl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Log;

/**
 * Backend CDN admin mutations exposed via CdnQueryProvider (bin-query).
 */
class CdnAdminQueryService
{
    public function __construct(private readonly Log $log)
    {
    }

    public function toggleDomainEnable(array $params): array
    {
        $id = (int)($params['domain_id'] ?? $params['id'] ?? 0);
        $enabled = (int)($params['enabled'] ?? 1);
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('域名ID不能为空')];
        }
        try {
            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class, [], false);
            $domain->reset()->load($id);
            if (!$domain->getId()) {
                return ['success' => false, 'message' => (string)__('域名不存在')];
            }
            $before = $this->domainSyncState($domain);
            $domain->setData(DomainModel::schema_fields_ENABLED, $enabled ? 1 : 0)->save();
            $sync = $this->fpcManagementCall('notifyDomainChange', [
                'domain_id' => $id, 'before' => $before, 'after' => $this->domainSyncState($domain),
            ]);
            if (!($sync['success'] ?? false)) {
                return $sync + ['data' => ['domain_id' => $id, 'saved' => true]];
            }
            return [
                'success' => true,
                'message' => $enabled ? (string)__('域名已启用') : (string)__('域名已禁用'),
                'data' => ['domain_id' => $id, 'sync' => $sync],
            ];
        } catch (\Throwable $e) {
            return $this->failure('toggleDomainEnable', $e, '操作失败，请稍后重试');
        }
    }

    public function clearDomainCache(array $params): array
    {
        $id = (int)($params['domain_id'] ?? $params['id'] ?? 0);
        $mode = trim((string)($params['mode'] ?? 'everything'));
        $data = is_array($params['data'] ?? null) ? $params['data'] : [];
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('域名ID不能为空')];
        }
        try {
            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class, [], false);
            $domain->reset()->load($id);
            if (!$domain->getId()) {
                return ['success' => false, 'message' => (string)__('域名不存在')];
            }
            /** @var CachePurger $purger */
            $purger = ObjectManager::getInstance(CachePurger::class);
            $result = $purger->purge($id, $mode, $data);
            return [
                'success' => (bool)($result['success'] ?? false),
                'message' => (string)($result['message'] ?? (($result['success'] ?? false) ? __('缓存清理成功') : __('缓存清理失败'))),
            ];
        } catch (\Throwable $e) {
            return $this->failure('clearDomainCache', $e, '清理失败，请稍后重试');
        }
    }

    public function saveDomain(array $params): array
    {
        $id = (int)($params['id'] ?? $params['domain_id'] ?? 0);
        try {
            $normalized = self::normalizeSaveDomainParams($params);
            if (($normalized['success'] ?? true) === false) {
                return [
                    'success' => false,
                    'message' => (string)($normalized['message'] ?? __('保存失败，请稍后重试')),
                ];
            }

            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class, [], false);
            $domain->reset();
            if ($id > 0) {
                $domain->load($id);
                if (!$domain->getId()) {
                    return ['success' => false, 'message' => (string)__('域名不存在')];
                }
            }

            $before = $domain->getId() ? $this->domainSyncState($domain) : [];
            $siteId = (int)$normalized['site_id'];
            $adapter = (string)$normalized['adapter'];
            $domainName = (string)$normalized['domain_name'];

            // 同一网站、适配器内保持映射唯一（与 Domain::save 控制器路径对齐）
            $existingByName = ObjectManager::getInstance(DomainModel::class, [], false)
                ->reset()
                ->where(DomainModel::schema_fields_DOMAIN_NAME, $domainName)
                ->where(DomainModel::schema_fields_SITE_ID, $siteId)
                ->where(DomainModel::schema_fields_ADAPTER, $adapter);
            if ($id > 0) {
                $existingByName->where(DomainModel::schema_fields_DOMAIN_ID, $id, '!=');
            }
            $existingByName = $existingByName->find()->fetch();
            if ($existingByName->getId()) {
                return [
                    'success' => false,
                    'message' => (string)__('域名 "%{1}" 已存在，请使用不同的域名', $domainName),
                ];
            }

            $domain->setData(DomainModel::schema_fields_SITE_ID, $siteId);
            $domain->setData(DomainModel::schema_fields_ADAPTER, $adapter);
            $domain->setData(DomainModel::schema_fields_DOMAIN_NAME, $domainName);
            $domain->setData(DomainModel::schema_fields_ZONE_ID, (string)$normalized['zone_id']);
            $domain->setData(DomainModel::schema_fields_ACCOUNT_ID, $normalized['account_id']);
            $domain->setData(DomainModel::schema_fields_INHERIT_DEFAULT, (int)$normalized['inherit_default']);
            $domain->setData(DomainModel::schema_fields_WARMUP_INTERVAL_SECONDS, (int)$normalized['warmup_interval_seconds']);
            $domain->setData(DomainModel::schema_fields_ENABLED, (int)$normalized['enabled']);
            $domain->save();
            $sync = $this->fpcManagementCall('notifyDomainChange', [
                'domain_id' => (int)$domain->getId(), 'before' => $before, 'after' => $this->domainSyncState($domain),
            ]);
            if (!($sync['success'] ?? false)) {
                return $sync + ['data' => ['domain_id' => (int)$domain->getId(), 'saved' => true]];
            }
            return [
                'success' => true,
                'message' => (string)__('域名保存成功'),
                'data' => ['domain_id' => (int)$domain->getId(), 'sync' => $sync],
            ];
        } catch (\Throwable $e) {
            return $this->failure('saveDomain', $e, '保存失败，请稍后重试');
        }
    }

    /** 只传同步目标配置，不把凭据或预热调度状态交给同步通知。 */
    private function domainSyncState(DomainModel $domain): array
    {
        return [
            'domain_name' => (string)$domain->getData(DomainModel::schema_fields_DOMAIN_NAME),
            'site_id' => (int)$domain->getData(DomainModel::schema_fields_SITE_ID),
            'adapter' => (string)$domain->getData(DomainModel::schema_fields_ADAPTER),
            'zone_id' => (string)$domain->getData(DomainModel::schema_fields_ZONE_ID),
            'account_id' => $domain->getData(DomainModel::schema_fields_ACCOUNT_ID) === null
                ? null : (int)$domain->getData(DomainModel::schema_fields_ACCOUNT_ID),
            'inherit_default' => (int)$domain->getData(DomainModel::schema_fields_INHERIT_DEFAULT),
            'enabled' => (int)$domain->getData(DomainModel::schema_fields_ENABLED),
        ];
    }

    /**
     * 规范化 saveDomain 入参：布尔旗标落库为 0/1（PostgreSQL integer 列不能绑 boolean / (string)false 空串）。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function normalizeSaveDomainParams(array $params): array
    {
        if (!\array_key_exists('site_id', $params) || $params['site_id'] === '' || $params['site_id'] === null) {
            return ['success' => false, 'message' => (string)__('网站不能为空')];
        }
        foreach (['adapter' => __('适配器不能为空'), 'domain_name' => __('域名名称不能为空'), 'zone_id' => __('Zone ID不能为空')] as $key => $msg) {
            if (!\array_key_exists($key, $params) || $params[$key] === '' || $params[$key] === null) {
                return ['success' => false, 'message' => (string)$msg];
            }
        }

        $accountId = $params['account_id'] ?? null;
        if ($accountId === '' || $accountId === '0' || $accountId === 0 || $accountId === false) {
            $accountId = null;
        } else {
            $accountId = (int)$accountId;
            if ($accountId <= 0) {
                $accountId = null;
            }
        }

        return [
            'success' => true,
            'site_id' => (int)$params['site_id'],
            'adapter' => trim((string)$params['adapter']),
            'domain_name' => strtolower(rtrim(trim((string)$params['domain_name']), '.')),
            'zone_id' => trim((string)$params['zone_id']),
            'account_id' => $accountId,
            'inherit_default' => self::normalizeFlagInt($params['inherit_default'] ?? 0),
            'warmup_interval_seconds' => max(60, (int)($params['warmup_interval_seconds'] ?? 300)),
            'enabled' => self::normalizeFlagInt($params['enabled'] ?? 1),
        ];
    }

    /**
     * @param mixed $value
     */
    private static function normalizeFlagInt(mixed $value): int
    {
        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (\is_int($value) || \is_float($value)) {
            return ((float)$value === 0.0) ? 0 : 1;
        }
        $normalized = strtolower(trim((string)$value));
        if ($normalized === '' || \in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        return 1;
    }

    public function executeWarmup(array $params): array
    {
        $limit = max(1, min(1000, (int)($params['limit'] ?? 50)));
        $filter = $this->resolveWarmupScopeFilter($params);
        $provider = trim((string)($params['provider'] ?? $params['provider_fqcn'] ?? ''));
        try {
            /** @var WarmupRunner $runner */
            $runner = ObjectManager::getInstance(WarmupRunner::class);
            $result = $runner->run(
                $limit,
                $filter['domain_id'],
                $provider !== '' ? $provider : null,
                $filter['site_id'],
            );
            return [
                'success' => true,
                'message' => (string)__('预热任务执行完成'),
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            return $this->failure('executeWarmup', $e, '执行失败，请稍后重试');
        }
    }

    public function listWarmupProviders(array $params): array
    {
        $filter = $this->resolveWarmupScopeFilter($params);
        try {
            /** @var WarmupCollectService $collect */
            $collect = ObjectManager::getInstance(WarmupCollectService::class);
            /** @var WarmupUrl $model */
            $model = ObjectManager::getInstance(WarmupUrl::class);
            $providers = [];
            foreach ($collect->listProvidersMeta() as $meta) {
                $fqcn = $meta['fqcn'];
                $q = $model->reset()->where(WarmupUrl::schema_fields_PROVIDER, $fqcn);
                $this->applyWarmupScopeFilter($q, $filter);
                $items = $q->select()->fetch()->getItems();
                $total = 0;
                $pending = 0;
                $success = 0;
                $fail = 0;
                foreach ($items as $row) {
                    $total++;
                    $status = (string)$row->getData(WarmupUrl::schema_fields_STATUS);
                    if ($status === WarmupUrl::STATUS_PENDING) {
                        $pending++;
                    } elseif ($status === WarmupUrl::STATUS_SUCCESS) {
                        $success++;
                    } elseif ($status === WarmupUrl::STATUS_FAIL) {
                        $fail++;
                    }
                }
                $providers[] = array_merge($meta, [
                    'url_count' => $total,
                    'pending' => $pending,
                    'success' => $success,
                    'fail' => $fail,
                ]);
            }
            $scopeStats = $this->warmupScopeStats($filter);
            return [
                'success' => true,
                'data' => [
                    'providers' => $providers,
                    'scope' => $scopeStats,
                ],
            ];
        } catch (\Throwable $e) {
            return $this->failure('listWarmupProviders', $e, '加载 Provider 失败');
        }
    }

    public function listWarmupUrls(array $params): array
    {
        $provider = trim((string)($params['provider'] ?? $params['provider_fqcn'] ?? ''));
        if ($provider === '') {
            return ['success' => false, 'message' => (string)__('provider 不能为空')];
        }
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 20)));
        $filter = $this->resolveWarmupScopeFilter($params);
        $status = trim((string)($params['status'] ?? ''));
        $search = trim((string)($params['search'] ?? ''));
        try {
            /** @var WarmupUrl $model */
            $model = ObjectManager::getInstance(WarmupUrl::class);
            $query = $model->reset()->where(WarmupUrl::schema_fields_PROVIDER, $provider);
            $this->applyWarmupScopeFilter($query, $filter);
            if ($status !== '') {
                $query->where(WarmupUrl::schema_fields_STATUS, $status);
            }
            if ($search !== '') {
                $query->where(WarmupUrl::schema_fields_URL, '%' . $search . '%', 'LIKE');
            }
            $countQuery = clone $query;
            $total = (int)$countQuery->count();
            $items = $query
                ->order(WarmupUrl::schema_fields_WARMUP_URL_ID, 'DESC')
                ->limit($pageSize, ($page - 1) * $pageSize)
                ->select()
                ->fetch()
                ->getItems();
            $rows = [];
            foreach ($items as $item) {
                $rows[] = [
                    'warmup_url_id' => (int)$item->getData(WarmupUrl::schema_fields_WARMUP_URL_ID),
                    'url' => (string)$item->getData(WarmupUrl::schema_fields_URL),
                    'status' => (string)$item->getData(WarmupUrl::schema_fields_STATUS),
                    'enabled' => (int)$item->getData(WarmupUrl::schema_fields_ENABLED),
                    'processed_count' => (int)$item->getData(WarmupUrl::schema_fields_PROCESSED_COUNT),
                    'target_count' => (int)$item->getData(WarmupUrl::schema_fields_TARGET_COUNT),
                    'success_count' => (int)$item->getData(WarmupUrl::schema_fields_SUCCESS_COUNT),
                    'fail_count' => (int)$item->getData(WarmupUrl::schema_fields_FAIL_COUNT),
                    'retries' => (int)$item->getData(WarmupUrl::schema_fields_RETRIES),
                    'last_warmed_at' => (int)$item->getData(WarmupUrl::schema_fields_LAST_WARMED_AT),
                    'domain_id' => (int)$item->getData(WarmupUrl::schema_fields_DOMAIN_ID),
                    'site_id' => (int)$item->getData(WarmupUrl::schema_fields_SITE_ID),
                    'module' => (string)$item->getData(WarmupUrl::schema_fields_MODULE),
                    'provider' => (string)$item->getData(WarmupUrl::schema_fields_PROVIDER),
                ];
            }
            return [
                'success' => true,
                'data' => [
                    'items' => $rows,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'has_more' => ($page * $pageSize) < $total,
                ],
            ];
        } catch (\Throwable $e) {
            return $this->failure('listWarmupUrls', $e, '加载 URL 失败');
        }
    }

    public function collectWarmup(array $params): array
    {
        $provider = trim((string)($params['provider'] ?? $params['provider_fqcn'] ?? ''));
        if ($provider === '') {
            return ['success' => false, 'message' => (string)__('provider 不能为空')];
        }
        $filter = $this->resolveWarmupScopeFilter($params);
        try {
            /** @var WarmupCollectService $collect */
            $collect = ObjectManager::getInstance(WarmupCollectService::class);
            $result = $collect->collectProvider($provider, $filter['domain_id'], $filter['site_id']);
            return [
                'success' => true,
                'message' => (string)__('收集完成'),
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            return $this->failure('collectWarmup', $e, '收集失败，请稍后重试');
        }
    }

    /**
     * @param array{domain_id:?int,site_id:?int} $filter
     * @return array{total:int,pending:int,success:int,fail:int,interval_seconds:?int,site_id:?int,domain_id:?int}
     */
    private function warmupScopeStats(array $filter): array
    {
        /** @var WarmupUrl $model */
        $model = ObjectManager::getInstance(WarmupUrl::class);
        $q = $model->reset();
        $this->applyWarmupScopeFilter($q, $filter);
        $items = $q->select()->fetch()->getItems();
        $total = 0;
        $pending = 0;
        $success = 0;
        $fail = 0;
        foreach ($items as $row) {
            $total++;
            $status = (string)$row->getData(WarmupUrl::schema_fields_STATUS);
            if ($status === WarmupUrl::STATUS_PENDING) {
                $pending++;
            } elseif ($status === WarmupUrl::STATUS_SUCCESS) {
                $success++;
            } elseif ($status === WarmupUrl::STATUS_FAIL) {
                $fail++;
            }
        }
        $interval = null;
        if ($filter['domain_id'] !== null) {
            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class);
            $domain->reset()->load($filter['domain_id']);
            if ($domain->getId()) {
                $interval = (int)($domain->getData(DomainModel::schema_fields_WARMUP_INTERVAL_SECONDS) ?: 300);
            }
        }

        return [
            'total' => $total,
            'pending' => $pending,
            'success' => $success,
            'fail' => $fail,
            'interval_seconds' => $interval,
            'site_id' => $filter['site_id'],
            'domain_id' => $filter['domain_id'],
        ];
    }

    /**
     * @return array{domain_id:?int,site_id:?int}
     */
    private function resolveWarmupScopeFilter(array $params): array
    {
        $domainId = isset($params['domain_id']) && $params['domain_id'] !== '' && $params['domain_id'] !== null
            ? (int)$params['domain_id']
            : null;
        if ($domainId !== null && $domainId <= 0) {
            $domainId = null;
        }
        $siteId = null;
        if (isset($params['site_id']) && $params['site_id'] !== '' && $params['site_id'] !== null) {
            $siteId = (int)$params['site_id'];
            if ($siteId < 0) {
                $siteId = null;
            }
        }
        $targetScope = trim((string)($params['target_scope'] ?? $params['scope'] ?? ''));
        if ($siteId === null && $targetScope !== '') {
            try {
                /** @var \Weline\SystemConfig\Service\SystemConfigTargetScopeService $scopeService */
                $scopeService = ObjectManager::getInstance(\Weline\SystemConfig\Service\SystemConfigTargetScopeService::class);
                $resolved = $scopeService->resolveFromInput(['target_scope' => $targetScope], false);
                $identity = $resolved['identity'] ?? null;
                if ($identity instanceof \Weline\Framework\Runtime\ScopeIdentity && !$identity->isGlobal()) {
                    $siteId = $identity->websiteId;
                }
            } catch (\Throwable $e) {
                $this->log->error('CDN warmup scope resolve failed: ' . $e->getMessage());
            }
        }

        return [
            'domain_id' => $domainId,
            'site_id' => $siteId,
        ];
    }

    /**
     * @param array{domain_id:?int,site_id:?int} $filter
     */
    private function applyWarmupScopeFilter(object $query, array $filter): void
    {
        if ($filter['domain_id'] !== null) {
            $query->where(WarmupUrl::schema_fields_DOMAIN_ID, $filter['domain_id']);
            return;
        }
        if ($filter['site_id'] !== null) {
            $query->where(WarmupUrl::schema_fields_SITE_ID, $filter['site_id']);
        }
    }

    public function toggleWarmupEnable(array $params): array
    {
        $id = (int)($params['id'] ?? $params['warmup_url_id'] ?? 0);
        $enabled = (int)($params['enabled'] ?? 1);
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('URL ID不能为空')];
        }
        try {
            /** @var WarmupUrl $warmupUrl */
            $warmupUrl = ObjectManager::getInstance(WarmupUrl::class, [], false);
            $warmupUrl->reset()->load($id);
            if (!$warmupUrl->getData(WarmupUrl::schema_fields_WARMUP_URL_ID)) {
                return ['success' => false, 'message' => (string)__('URL不存在')];
            }
            $warmupUrl->setData(WarmupUrl::schema_fields_ENABLED, $enabled ? 1 : 0)->save();
            return [
                'success' => true,
                'message' => $enabled ? (string)__('URL已启用') : (string)__('URL已禁用'),
            ];
        } catch (\Throwable $e) {
            return $this->failure('toggleWarmupEnable', $e, '操作失败，请稍后重试');
        }
    }

    public function deleteWarmupUrl(array $params): array
    {
        $id = (int)($params['id'] ?? $params['warmup_url_id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('URL ID不能为空')];
        }
        try {
            /** @var WarmupUrl $warmupUrl */
            $warmupUrl = ObjectManager::getInstance(WarmupUrl::class);
            $warmupUrl->reset()->load($id);
            if (!$warmupUrl->getData(WarmupUrl::schema_fields_WARMUP_URL_ID)) {
                return ['success' => false, 'message' => (string)__('URL不存在')];
            }
            $warmupUrl->delete();
            return ['success' => true, 'message' => (string)__('URL删除成功')];
        } catch (\Throwable $e) {
            return $this->failure('deleteWarmupUrl', $e, '删除失败，请稍后重试');
        }
    }

    public function deleteAttackLog(array $params): array
    {
        $id = (int)($params['id'] ?? $params['log_id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('日志ID不能为空')];
        }
        try {
            $model = ObjectManager::getInstance(\Weline\Cdn\Model\AttackLog::class);
            $model->reset()->load($id);
            if (!$model->getId()) {
                return ['success' => false, 'message' => (string)__('日志不存在')];
            }
            $model->delete();
            return ['success' => true, 'message' => (string)__('删除成功')];
        } catch (\Throwable $e) {
            return $this->failure('deleteAttackLog', $e, '删除失败，请稍后重试');
        }
    }

    public function batchDeleteAttackLogs(array $params): array
    {
        $ids = $params['ids'] ?? [];
        if (!\is_array($ids) || $ids === []) {
            return ['success' => false, 'message' => (string)__('请选择要删除的日志')];
        }
        $deleted = 0;
        foreach ($ids as $id) {
            $result = $this->deleteAttackLog(['id' => (int)$id]);
            if ($result['success'] ?? false) {
                $deleted++;
            }
        }
        return ['success' => true, 'message' => (string)__('已删除 %{1} 条', $deleted), 'data' => ['deleted' => $deleted]];
    }

    public function cleanupAttackLogs(array $params): array
    {
        $days = (int)($params['days'] ?? 30);
        try {
            $model = ObjectManager::getInstance(\Weline\Cdn\Model\AttackLog::class);
            if (method_exists($model, 'cleanupOlderThanDays')) {
                $count = $model->cleanupOlderThanDays($days);
            } else {
                $cutoff = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
                $count = 0;
                $rows = $model->reset()->where('created_at', $cutoff, '<')->select()->fetch()->getItems();
                foreach ($rows as $row) {
                    $row->delete();
                    $count++;
                }
            }
            return ['success' => true, 'message' => (string)__('清理完成'), 'data' => ['deleted' => $count]];
        } catch (\Throwable $e) {
            return $this->failure('cleanupAttackLogs', $e, '清理失败，请稍后重试');
        }
    }

    public function listFpcPolicies(array $params): array
    {
        return $this->fpcManagementCall('listPolicies', $params);
    }

    public function saveFpcPolicyOverride(array $params): array
    {
        return $this->fpcManagementCall('saveOverride', $params);
    }

    public function restoreFpcPolicyInheritance(array $params): array
    {
        return $this->fpcManagementCall('restoreInheritance', $params);
    }

    public function collectFpcPolicies(array $params): array
    {
        return $this->fpcManagementCall('collectDeclarations', $params);
    }

    public function listFpcSyncRecords(array $params): array
    {
        return $this->fpcManagementCall('listSyncRecords', $params);
    }

    public function retryFpcSync(array $params): array
    {
        return $this->fpcManagementCall('retrySync', $params);
    }

    public function notifyScopeBindingChange(array $params): array
    {
        return $this->fpcManagementCall('notifyScopeBindingChange', $params);
    }

    /** 保留未传字段和显式 null；业务语义由声明/覆盖的归属服务执行。 */
    private function fpcManagementCall(string $method, array $params): array
    {
        try {
            /** @var FpcPolicyManagementService $management */
            $management = ObjectManager::getInstance(FpcPolicyManagementService::class);
            return $management->{$method}($params);
        } catch (\Throwable $e) {
            return $this->failure($method, $e, '操作失败，请稍后重试') + ['error_code' => 'fpc_management_failed'];
        }
    }

    public function collectApiRules(array $params): array
    {
        try {
            /** @var CdnRuleCollector $collector */
            $collector = ObjectManager::getInstance(CdnRuleCollector::class);
            $result = $collector->collectAll();
            $fpc = $this->collectFpcPolicies($params);
            if (!($fpc['success'] ?? false)) {
                return $fpc;
            }
            return [
                'success' => true,
                'message' => (string)__('收集完成'),
                'data' => ['rules' => $result, 'fpc_policy' => $fpc['data'] ?? []],
            ];
        } catch (\Throwable $e) {
            return $this->failure('collectApiRules', $e, '收集失败，请稍后重试');
        }
    }

    public function toggleApiRule(array $params): array
    {
        $id = (int)($params['id'] ?? $params['rule_id'] ?? 0);
        $enabled = (int)($params['enabled'] ?? 1);
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('规则ID不能为空')];
        }
        try {
            $model = ObjectManager::getInstance(\Weline\Cdn\Model\ApiRule::class);
            $model->reset()->load($id);
            if (!$model->getId()) {
                return ['success' => false, 'message' => (string)__('规则不存在')];
            }
            if (method_exists($model, 'setEnabled')) {
                $model->setEnabled($enabled ? 1 : 0);
            } else {
                $model->setData('enabled', $enabled ? 1 : 0);
            }
            $model->save();
            return ['success' => true, 'message' => (string)__('已更新')];
        } catch (\Throwable $e) {
            return $this->failure('toggleApiRule', $e, '操作失败，请稍后重试');
        }
    }

    public function deleteApiRule(array $params): array
    {
        $id = (int)($params['id'] ?? $params['rule_id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('规则ID不能为空')];
        }
        try {
            $model = ObjectManager::getInstance(\Weline\Cdn\Model\ApiRule::class);
            $model->reset()->load($id);
            if (!$model->getId()) {
                return ['success' => false, 'message' => (string)__('规则不存在')];
            }
            $model->delete();
            return ['success' => true, 'message' => (string)__('删除成功')];
        } catch (\Throwable $e) {
            return $this->failure('deleteApiRule', $e, '删除失败，请稍后重试');
        }
    }

    public function getGlobalRules(array $params): array
    {
        try {
            $manager = ObjectManager::getInstance(RuleManager::class);
            // RuleManager 权威方法名为 getDefaultRules（读 etc/default-rules.json）
            $rules = $manager->getDefaultRules();
            return ['success' => true, 'data' => $rules];
        } catch (\Throwable $e) {
            return $this->failure('getGlobalRules', $e, '规则读取失败，请稍后重试');
        }
    }

    public function getDomainRules(array $params): array
    {
        $domainId = (int)($params['domain_id'] ?? $params['id'] ?? 0);
        if ($domainId <= 0) {
            return ['success' => false, 'message' => (string)__('域名ID不能为空')];
        }
        try {
            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class);
            $domain->reset()->load($domainId);
            if (!$domain->getId()) {
                return ['success' => false, 'message' => (string)__('域名不存在')];
            }
            $rules = method_exists($domain, 'getRulesOverrideArray')
                ? $domain->getRulesOverrideArray()
                : [];
            return ['success' => true, 'data' => $rules];
        } catch (\Throwable $e) {
            return $this->failure('getDomainRules', $e, '规则读取失败，请稍后重试');
        }
    }

    public function saveGlobalRules(array $params): array
    {
        try {
            $manager = ObjectManager::getInstance(RuleManager::class);
            $rules = $params['rules'] ?? [];
            if (\is_string($rules)) {
                $decoded = json_decode($rules, true);
                $rules = \is_array($decoded) ? $decoded : [];
            }
            if (!\is_array($rules)) {
                return ['success' => false, 'message' => (string)__('规则格式错误')];
            }
            // RuleManager 权威方法名为 saveDefaultRules（写 etc/default-rules.json）
            if (!$manager->saveDefaultRules($rules)) {
                return ['success' => false, 'message' => (string)__('保存失败')];
            }
            return ['success' => true, 'message' => (string)__('保存成功')];
        } catch (\Throwable $e) {
            return $this->failure('saveGlobalRules', $e, '保存失败，请稍后重试');
        }
    }

    public function saveDomainRules(array $params): array
    {
        $domainId = (int)($params['domain_id'] ?? $params['id'] ?? 0);
        if ($domainId <= 0) {
            return ['success' => false, 'message' => (string)__('域名ID不能为空')];
        }
        try {
            $rules = $params['rules'] ?? [];
            if (\is_string($rules)) {
                $decoded = json_decode($rules, true);
                $rules = \is_array($decoded) ? $decoded : [];
            }
            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class);
            $domain->reset()->load($domainId);
            if (!$domain->getId()) {
                return ['success' => false, 'message' => (string)__('域名不存在')];
            }
            $domain->setRulesOverrideArray($rules);
            $domain->save();
            return ['success' => true, 'message' => (string)__('保存成功')];
        } catch (\Throwable $e) {
            return $this->failure('saveDomainRules', $e, '保存失败，请稍后重试');
        }
    }

    public function importDomainRules(array $params): array
    {
        $domainId = (int)($params['domain_id'] ?? $params['id'] ?? 0);
        if ($domainId <= 0) {
            return ['success' => false, 'message' => (string)__('域名ID不能为空')];
        }
        try {
            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class);
            $domain->reset()->load($domainId);
            if (!$domain->getId()) {
                return ['success' => false, 'message' => (string)__('域名不存在')];
            }
            $manager = ObjectManager::getInstance(RuleManager::class);
            $result = $manager->importRules($domain);
            if (!($result['success'] ?? false)) {
                return ['success' => false, 'message' => (string)($result['message'] ?? __('导入失败'))];
            }
            $domain->setRulesOverrideArray($result['rules'] ?? []);
            $domain->save();
            return ['success' => true, 'message' => (string)__('规则导入成功'), 'data' => ['rules' => $result['rules'] ?? []]];
        } catch (\Throwable $e) {
            return $this->failure('importDomainRules', $e, '导入失败，请稍后重试');
        }
    }

    public function pushDomainRules(array $params): array
    {
        $domainId = (int)($params['domain_id'] ?? $params['id'] ?? 0);
        if ($domainId <= 0) {
            return ['success' => false, 'message' => (string)__('域名ID不能为空')];
        }
        return $this->fpcManagementCall('requestManualSync', ['domain_id' => $domainId]);
    }

    public function listEnabledDomains(array $params): array
    {
        try {
            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class);
            $items = $domain->reset()
                ->where(DomainModel::schema_fields_ENABLED, 1)
                ->select()
                ->fetch()
                ->getItems();
            $list = [];
            foreach ($items as $item) {
                $list[] = $item->getData();
            }
            return ['success' => true, 'data' => ['domains' => $list]];
        } catch (\Throwable $e) {
            return $this->failure('listEnabledDomains', $e, '域名读取失败，请稍后重试');
        }
    }

    /** @return array{success:false,message:string} */
    private function failure(string $operation, \Throwable $error, string $publicMessage): array
    {
        $this->log->error(\sprintf(
            '[CDN Admin] %s failed: %s (%s:%d)',
            $operation,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
        ));

        return ['success' => false, 'message' => (string)__($publicMessage)];
    }
}
