<?php

declare(strict_types=1);

namespace Weline\Vendor\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Vendor\Model\VendorPayoutRecord;
use Weline\Vendor\Model\VendorProductBindingRecord;
use Weline\Vendor\Model\VendorRecord;
use Weline\Vendor\Model\VendorRefundReversalRecord;
use Weline\Vendor\Model\VendorSplitRuleRecord;
use Weline\Vendor\Model\VendorWebsiteAuthorizationRecord;
use Weline\Vendor\Service\VendorAdminService;
use Weline\Vendor\Service\VendorRolloutGate;

#[Acl('Weline_Vendor::commerce:partner:control-center', '商家管理', 'store', '商家、授权、商品绑定与结算管理', 'Weline_Backend::commerce:partner:group')]
final class ControlCenter extends BackendController
{
    public function __construct(private readonly VendorAdminService $adminService)
    {
    }

    #[Acl('Weline_Vendor::commerce:partner:vendors', '商家档案', 'store', '查看商家档案')]
    public function vendors(): string
    {
        return $this->renderWorkspace('vendors', '商家档案', ['商家档案' => [VendorRecord::class, ['identity_id', 'vendor_id', 'code', 'legal_name', 'environment', 'status', 'created_at', 'updated_at']]], [], ['kind' => 'vendor', 'action' => 'vendor/backend/control-center/save-vendor']);
    }

    #[Acl('Weline_Vendor::commerce:partner:authorizations', '站点授权', 'user', '查看商家站点授权')]
    public function authorizations(): string
    {
        return $this->renderWorkspace('authorizations', '站点授权', ['授权记录' => [VendorWebsiteAuthorizationRecord::class, ['authorization_id', 'vendor_id', 'website_id', 'status', 'grant_version', 'authorized_at', 'revoked_at']]], [], ['kind' => 'authorization', 'action' => 'vendor/backend/control-center/save-authorization']);
    }

    #[Acl('Weline_Vendor::commerce:partner:product-bindings', '商品绑定', 'link', '查看商家商品绑定')]
    public function productBindings(): string
    {
        return $this->renderWorkspace('product-bindings', '商品绑定', ['商品绑定' => [VendorProductBindingRecord::class, ['binding_id', 'vendor_id', 'website_id', 'store_id', 'product_registry_id', 'product_sku', 'global_product_uuid', 'environment', 'status', 'binding_version', 'bound_at', 'unbound_at']]], [], ['kind' => 'product-binding', 'action' => 'vendor/backend/control-center/save-product-binding']);
    }

    #[Acl('Weline_Vendor::commerce:partner:split-rules', '拆分规则', 'circle', '查看商家拆分规则')]
    public function splitRules(): string
    {
        return $this->renderWorkspace('split-rules', '拆分规则', ['拆分规则' => [VendorSplitRuleRecord::class, ['rule_id', 'vendor_id', 'website_id', 'commission_bps', 'currency', 'rule_version', 'updated_at']]], [], ['kind' => 'split-rule', 'action' => 'vendor/backend/control-center/save-split-rule']);
    }

    #[Acl('Weline_Vendor::commerce:partner:payouts', '结算账本', 'cash', '查看商家结算账本')]
    public function payouts(): string
    {
        return $this->renderWorkspace('payouts', '结算账本', ['结算记录' => [VendorPayoutRecord::class, ['payout_row_id', 'payout_id', 'snapshot_id', 'vendor_id', 'website_id', 'store_id', 'environment', 'currency', 'amount_minor', 'reversed_minor', 'net_minor', 'status', 'ledger_version', 'created_at', 'updated_at']]], [], ['kind' => 'payout', 'action' => 'vendor/backend/control-center/schedule-payout']);
    }

    #[Acl('Weline_Vendor::commerce:partner:reversals', '退款冲正', 'cash', '查看商家退款冲正')]
    public function reversals(): string
    {
        return $this->renderWorkspace('reversals', '退款冲正', ['冲正记录' => [VendorRefundReversalRecord::class, ['reversal_row_id', 'reversal_id', 'payout_id', 'snapshot_id', 'vendor_id', 'website_id', 'store_id', 'environment', 'refund_ref', 'amount_minor', 'currency', 'reason', 'payout_net_after_minor', 'created_at']]]);
    }

    #[Acl('Weline_Vendor::commerce:partner:migration', '迁移状态', 'eye', '只读查看商家迁移状态')]
    public function migration(): string
    {
        return $this->renderWorkspace('migration', '迁移状态', [], $this->rolloutStatus() + ['execution_policy' => 'registered_postgresql_full_clone_cli_only', 'production_actions_exposed' => false]);
    }

    #[Acl('Weline_Vendor::commerce:partner:vendors', '创建商家档案', 'save', '创建商家档案')]
    public function saveVendor()
    {
        return $this->executeWrite('registerVendor', 'vendors', '商家档案已创建。');
    }

    #[Acl('Weline_Vendor::commerce:partner:authorizations', '授权商家站点', 'check', '授权商家访问站点')]
    public function saveAuthorization()
    {
        return $this->executeWrite('authorizeWebsite', 'authorizations', '站点授权已保存。');
    }

    #[Acl('Weline_Vendor::commerce:partner:product-bindings', '绑定商家商品', 'plus', '绑定商家与商品')]
    public function saveProductBinding()
    {
        return $this->executeWrite('bindProduct', 'product-bindings', '商品绑定已保存。');
    }

    #[Acl('Weline_Vendor::commerce:partner:split-rules', '保存拆分规则', 'save', '保存商家拆分规则')]
    public function saveSplitRule()
    {
        return $this->executeWrite('upsertSplitRule', 'split-rules', '拆分规则已保存。');
    }

    #[Acl('Weline_Vendor::commerce:partner:payouts', '调度结算', 'clock', '从不可变快照调度结算')]
    public function schedulePayout()
    {
        return $this->executeWrite('schedulePayout', 'payouts', '结算已调度。');
    }

    /** @param array<string,array{0:class-string,1:list<string>}> $sources */
    private function renderWorkspace(string $code, string $title, array $sources, array $status = [], array $form = []): string
    {
        $datasets = [];
        foreach ($sources as $label => [$modelClass, $fields]) $datasets[] = $this->loadRows($label, $modelClass, $fields);
        $this->assign('workspace_code', $code);
        $this->assign('workspace_title', __($title));
        $this->assign('workspace_status', $status);
        $this->assign('workspace_datasets', $datasets);
        $this->assign('workspace_form', $form);
        return $this->fetch('index');
    }

    private function executeWrite(string $command, string $returnPage, string $successMessage)
    {
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $this->adminService->{$command}((array)$this->request->getPost());
            MessageManager::success((string)__($successMessage));
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'write:' . $command);
            MessageManager::error((string)__('操作失败，请稍后重试。参考编号：%{1}', [$reference]));
        }
        return $this->redirect('vendor/backend/control-center/' . $returnPage);
    }

    /** @param class-string $modelClass @param list<string> $fields */
    private function loadRows(string $label, string $modelClass, array $fields): array
    {
        try {
            $rows = ObjectManager::getInstance($modelClass)->reset()->limit(50)->select()->fetchArray();
            $safeRows = [];
            foreach ($rows as $row) if (is_array($row)) $safeRows[] = array_intersect_key($row, array_flip($fields));
            return ['label' => __($label), 'rows' => $safeRows, 'error' => ''];
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'load:' . $modelClass);
            return ['label' => __($label), 'rows' => [], 'error' => (string)__('数据暂时无法加载。参考编号：%{1}', [$reference])];
        }
    }

    private function rolloutStatus(): array
    {
        try {
            $configuration = ObjectManager::getInstance(VendorRolloutGate::class)->configuration();
            return ['mode' => (string)($configuration['mode'] ?? 'off'), 'allowlist_count' => count((array)($configuration['allowlist'] ?? [])), 'env_locked' => !empty($configuration['env_locked'])];
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'rollout-status');
            return ['status_error' => (string)__('状态暂时无法读取。参考编号：%{1}', [$reference])];
        }
    }

    private function reportFailure(\Throwable $throwable, string $operation): string
    {
        $reference = strtoupper(substr(hash('sha256', self::class . '|' . $operation . '|' . uniqid('', true)), 0, 12));
        try {
            w_log_error('Commerce backend operation failed', [
                'reference' => $reference,
                'controller' => self::class,
                'operation' => $operation,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ], 'commerce_backend');
        } catch (\Throwable) {
        }
        return $reference;
    }
}
