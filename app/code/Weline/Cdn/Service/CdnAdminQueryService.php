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
            $domain->setData(DomainModel::schema_fields_ENABLED, $enabled ? 1 : 0)->save();
            return [
                'success' => true,
                'message' => $enabled ? (string)__('域名已启用') : (string)__('域名已禁用'),
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
            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class, [], false);
            $domain->reset();
            if ($id > 0) {
                $domain->load($id);
                if (!$domain->getId()) {
                    return ['success' => false, 'message' => (string)__('域名不存在')];
                }
            }
            if (!\array_key_exists('site_id', $params) || $params['site_id'] === '' || $params['site_id'] === null) {
                return ['success' => false, 'message' => (string)__('网站不能为空')];
            }
            foreach (['adapter' => __('适配器不能为空'), 'domain_name' => __('域名名称不能为空'), 'zone_id' => __('Zone ID不能为空')] as $key => $msg) {
                if (empty($params[$key])) {
                    return ['success' => false, 'message' => (string)$msg];
                }
            }
            foreach ([
                DomainModel::schema_fields_SITE_ID => 'site_id',
                DomainModel::schema_fields_ADAPTER => 'adapter',
                DomainModel::schema_fields_DOMAIN_NAME => 'domain_name',
                DomainModel::schema_fields_ZONE_ID => 'zone_id',
                DomainModel::schema_fields_ACCOUNT_ID => 'account_id',
                DomainModel::schema_fields_INHERIT_DEFAULT => 'inherit_default',
                DomainModel::schema_fields_WARMUP_INTERVAL_SECONDS => 'warmup_interval_seconds',
                DomainModel::schema_fields_ENABLED => 'enabled',
            ] as $field => $key) {
                if (\array_key_exists($key, $params)) {
                    $domain->setData($field, $params[$key]);
                }
            }
            $domain->save();
            return [
                'success' => true,
                'message' => (string)__('域名保存成功'),
                'data' => ['domain_id' => (int)$domain->getId()],
            ];
        } catch (\Throwable $e) {
            return $this->failure('saveDomain', $e, '保存失败，请稍后重试');
        }
    }

    public function executeWarmup(array $params): array
    {
        $limit = (int)($params['limit'] ?? 50);
        try {
            /** @var WarmupRunner $runner */
            $runner = ObjectManager::getInstance(WarmupRunner::class);
            $result = $runner->run($limit);
            return [
                'success' => true,
                'message' => (string)__('预热任务执行完成'),
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            return $this->failure('executeWarmup', $e, '执行失败，请稍后重试');
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

    public function collectApiRules(array $params): array
    {
        try {
            /** @var CdnRuleCollector $collector */
            $collector = ObjectManager::getInstance(CdnRuleCollector::class);
            $result = $collector->collect();
            return [
                'success' => true,
                'message' => (string)__('收集完成'),
                'data' => \is_array($result) ? $result : ['result' => $result],
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
            $rules = method_exists($manager, 'getGlobalRules') ? $manager->getGlobalRules() : [];
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
            if (method_exists($manager, 'saveGlobalRules')) {
                $manager->saveGlobalRules($rules);
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
        try {
            /** @var DomainModel $domain */
            $domain = ObjectManager::getInstance(DomainModel::class);
            $domain->reset()->load($domainId);
            if (!$domain->getId()) {
                return ['success' => false, 'message' => (string)__('域名不存在')];
            }
            $manager = ObjectManager::getInstance(RuleManager::class);
            $result = method_exists($manager, 'pushRules') ? $manager->pushRules($domain) : ['success' => false, 'message' => 'pushRules missing'];
            return [
                'success' => (bool)($result['success'] ?? false),
                'message' => (string)($result['message'] ?? (($result['success'] ?? false) ? __('推送成功') : __('推送失败'))),
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            return $this->failure('pushDomainRules', $e, '推送失败，请稍后重试');
        }
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
