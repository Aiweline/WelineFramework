<?php

declare(strict_types=1);

namespace Weline\Tax\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Tax\Api\TaxEngineInterface;
use Weline\Tax\Model\TaxClass;
use Weline\Tax\Model\TaxRule;
use Weline\Tax\Model\TaxRuleSetLkg;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\Tax\Service\RateSync\TaxRateAggregateSyncService;
use Weline\Tax\Service\TaxConfigurationAdminService;
use Weline\Tax\Service\TaxRolloutGate;
use Weline\Tax\Service\TaxScopeConfig;

#[Acl(
    'Weline_Tax::commerce:tax-search:control-center',
    '税务管理',
    'check',
    '税务配置管理与迁移诊断',
    'Weline_Backend::commerce:tax-search:group'
)]
final class ControlCenter extends BackendController
{
    public function __construct(private readonly TaxConfigurationAdminService $adminService)
    {
    }

    #[Acl('Weline_Tax::commerce:tax-search:classes', '税类', 'circle', '管理税类')]
    public function classes(): string
    {
        return $this->renderWorkspace('classes', '税类', [
            '税类记录' => [TaxClass::class, ['tax_class_id', 'website_id', 'class_code', 'name', 'enabled', 'updated_at']],
        ], [], ['kind' => 'class', 'action' => 'tax/backend/control-center/save-class']);
    }

    #[Acl('Weline_Tax::commerce:tax-search:rates', '税率', 'circle', '管理税率')]
    public function rates(): string
    {
        return $this->renderWorkspace('rates', '税率', [
            '税率记录' => [TaxRule::class, ['tax_rule_id', 'website_id', 'class_code', 'jurisdiction_key', 'rate_bps', 'enabled', 'updated_at']],
        ], [], ['kind' => 'rate', 'action' => 'tax/backend/control-center/save-rate']);
    }

    #[Acl('Weline_Tax::commerce:tax-search:rules', '税务规则', 'check', '管理税务规则')]
    public function rules(): string
    {
        return $this->renderWorkspace('rules', '税务规则', [
            '规则记录' => [TaxRule::class, ['tax_rule_id', 'website_id', 'class_code', 'jurisdiction_key', 'rate_bps', 'rule_version', 'rounding', 'enabled', 'updated_at']],
        ], [], ['kind' => 'rule', 'action' => 'tax/backend/control-center/save-rule']);
    }

    #[Acl('Weline_Tax::commerce:tax-search:classes:save', '创建税类', 'save', '创建税类')]
    public function saveClass()
    {
        return $this->saveConfiguration('class', 'classes');
    }

    #[Acl('Weline_Tax::commerce:tax-search:rates:save', '创建税率', 'save', '创建税率')]
    public function saveRate()
    {
        return $this->saveConfiguration('rate', 'rates');
    }

    #[Acl('Weline_Tax::commerce:tax-search:rules:save', '创建税务规则', 'save', '创建税务规则')]
    public function saveRule()
    {
        return $this->saveConfiguration('rule', 'rules');
    }

    #[Acl('Weline_Tax::commerce:tax-search:engine', '税引擎状态', 'circle', '查看税引擎状态')]
    public function engine(): string
    {
        return $this->renderWorkspace('engine', '税引擎状态', [], $this->rolloutStatus() + [
            'schema_version' => TaxEngineInterface::SCHEMA_VERSION,
        ]);
    }

    #[Acl('Weline_Tax::commerce:tax-search:shadow', '影子验证', 'circle', '查看税务影子验证状态')]
    public function shadow(): string
    {
        return $this->renderWorkspace('shadow', '影子验证', [], $this->rolloutStatus());
    }

    #[Acl('Weline_Tax::commerce:tax-search:lkg', '已验证 LKG', 'check', '查看已验证税务 LKG')]
    public function lkg(): string
    {
        return $this->renderWorkspace('lkg', '已验证 LKG', [
            'LKG 记录' => [TaxRuleSetLkg::class, ['tax_rule_set_lkg_id', 'website_id', 'store_id', 'scope_key', 'schema_version', 'rule_set_hash', 'sample_count', 'verified', 'verified_at', 'updated_at']],
        ]);
    }

    #[Acl('Weline_Tax::commerce:tax-search:migration', '迁移状态', 'eye', '只读查看税务迁移状态')]
    public function migration(): string
    {
        return $this->renderWorkspace('migration', '迁移状态', [], $this->rolloutStatus() + [
            'execution_policy' => 'registered_postgresql_full_clone_cli_only',
            'production_actions_exposed' => false,
        ]);
    }

    #[Acl('Weline_Tax::commerce:tax-search:ratesync', '税率同步', 'sync', '多源聚合税率同步')]
    public function ratesync(): string
    {
        $status = $this->ratesyncStatus();

        return $this->renderWorkspace('ratesync', '税率同步', [
            '税率记录' => [TaxRule::class, ['tax_rule_id', 'website_id', 'class_code', 'jurisdiction_key', 'rate_bps', 'enabled', 'updated_at']],
        ], $status, [
            'kind' => 'ratesync',
            'action' => 'tax/backend/control-center/run-rate-sync',
            'config_deeplink' => $this->ratesyncConfigDeeplink(),
        ]);
    }

    #[Acl('Weline_Tax::commerce:tax-search:ratesync:run', '立即同步税率', 'save', '立即执行多源税率同步')]
    public function runRateSync()
    {
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $websiteId = filter_var($this->request->getPost('website_id', 0), FILTER_VALIDATE_INT);
            if ($websiteId === false || $websiteId < 0) {
                $websiteId = 0;
            }
            /** @var TaxRateAggregateSyncService $sync */
            $sync = ObjectManager::getInstance(TaxRateAggregateSyncService::class);
            $result = $sync->sync((int)$websiteId, true);
            if (!empty($result['ok'])) {
                $this->getMessageManager()->addSuccess(__(
                    '税率同步完成：合并 %{1} 条，新建 %{2}，更新 %{3}，冲突取高 %{4}，专业覆盖 %{5}。',
                    [
                        (int)($result['merged_count'] ?? 0),
                        (int)($result['rules_created'] ?? 0),
                        (int)($result['rules_updated'] ?? 0),
                        (int)($result['conflict_max_count'] ?? 0),
                        (int)($result['professional_overlay_count'] ?? 0),
                    ],
                ));
            } else {
                $this->getMessageManager()->addError((string)__('税率同步失败。'));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('tax/backend/controlcenter/ratesync');
    }

    /** @return array<string,mixed> */
    private function ratesyncStatus(): array
    {
        try {
            /** @var TaxRateAggregateSyncService $sync */
            $sync = ObjectManager::getInstance(TaxRateAggregateSyncService::class);
            $last = $sync->lastResult();
            if ($last === []) {
                return [
                    'last_sync' => __('尚未同步'),
                    'cron_enabled' => $sync->cronEnabled() ? __('是') : __('否'),
                ];
            }

            return [
                'last_sync' => (string)($last['finished_at'] ?? $last['started_at'] ?? ''),
                'merged_count' => (int)($last['merged_count'] ?? 0),
                'rules_created' => (int)($last['rules_created'] ?? 0),
                'rules_updated' => (int)($last['rules_updated'] ?? 0),
                'conflict_max_count' => (int)($last['conflict_max_count'] ?? 0),
                'professional_overlay_count' => (int)($last['professional_overlay_count'] ?? 0),
                'failed_sources' => (array)($last['failed_sources'] ?? []),
                'source_stats' => (array)($last['source_stats'] ?? []),
                'cron_enabled' => $sync->cronEnabled() ? __('是') : __('否'),
            ];
        } catch (\Throwable $throwable) {
            return ['status_error' => $throwable->getMessage()];
        }
    }

    /**
     * SystemConfig deep link that auto-locates tax/ratesync fields (guide_key + guide_locate).
     */
    private function ratesyncConfigDeeplink(): string
    {
        $guideKey = TaxRateAggregateSyncService::KEY_MODE;
        try {
            return (string)$this->request->getUrlBuilder()->getBackendUrl(
                'weline_systemconfig/backend/config',
                [
                    'module' => TaxScopeConfig::MODULE,
                    'area' => TaxScopeConfig::AREA !== '' ? TaxScopeConfig::AREA : ConfigReader::area_BACKEND,
                    'scope' => ConfigReader::SCOPE_GLOBAL,
                    'target_scope' => ConfigReader::SCOPE_GLOBAL,
                    'search' => 'tax/ratesync',
                    'q' => 'tax/ratesync',
                    'guide_key' => $guideKey,
                    'guide_locate' => $guideKey,
                    'guide_title' => (string)__('税率多源同步'),
                    'guide_summary' => (string)__('免费并集取高 + 可选专业覆盖；密钥只保存在统一配置中心。'),
                    'guide_return' => 'tax/backend/controlcenter/ratesync',
                ],
                false,
            );
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<string,array{0:class-string,1:list<string>}> $sources */
    private function renderWorkspace(string $code, string $title, array $sources, array $status = [], array $form = []): string
    {
        $datasets = [];
        foreach ($sources as $label => [$modelClass, $fields]) {
            $datasets[] = $this->loadRows($label, $modelClass, $fields);
        }
        $this->assign('workspace_code', $code);
        $this->assign('workspace_title', __($title));
        $this->assign('workspace_status', $status);
        $this->assign('workspace_datasets', $datasets);
        $this->assign('workspace_read_only', $form === []);
        $this->assign('workspace_form', $form);

        return $this->fetch('index');
    }

    /** @param class-string $modelClass @param list<string> $fields */
    private function loadRows(string $label, string $modelClass, array $fields): array
    {
        try {
            $model = ObjectManager::getInstance($modelClass);
            $rows = $model->reset()->order($model->getIdFieldName(), 'DESC')->limit(50)->select()->fetchArray();
            $safeRows = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $safeRows[] = array_intersect_key($row, array_flip($fields));
                }
            }
            return ['label' => __($label), 'rows' => $safeRows, 'error' => '', 'limit' => 50];
        } catch (\Throwable $throwable) {
            return ['label' => __($label), 'rows' => [], 'error' => $throwable->getMessage(), 'limit' => 50];
        }
    }

    private function rolloutStatus(): array
    {
        try {
            $configuration = ObjectManager::getInstance(TaxRolloutGate::class)->configuration();
            return [
                'mode' => (string)($configuration['mode'] ?? 'off'),
                'allowlist_count' => count((array)($configuration['allowlist'] ?? [])),
                'shadow_sample_bp' => (int)($configuration['shadow_sample_bp'] ?? 0),
                'env_locked' => !empty($configuration['env_locked']),
            ];
        } catch (\Throwable $throwable) {
            return ['status_error' => $throwable->getMessage()];
        }
    }

    private function saveConfiguration(string $kind, string $returnPage)
    {
        try {
            if (!$this->request->isPost()) throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            $input = (array)$this->request->getPost();
            match ($kind) {
                'class' => $this->adminService->createClass($input),
                'rate' => $this->adminService->createRate($input),
                'rule' => $this->adminService->createRule($input),
                default => throw new \InvalidArgumentException((string)__('未知税务配置类型。')),
            };
            $this->getMessageManager()->addSuccess(__('税务配置创建成功。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }
        return $this->redirect('tax/backend/controlcenter/' . $returnPage);
    }
}
