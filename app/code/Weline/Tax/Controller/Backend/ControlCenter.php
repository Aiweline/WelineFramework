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
use Weline\Tax\Service\TaxConfigurationAdminService;
use Weline\Tax\Service\TaxRolloutGate;

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
