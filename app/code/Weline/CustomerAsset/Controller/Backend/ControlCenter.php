<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Controller\Backend;

use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Model\AssetLedger;
use Weline\CustomerAsset\Model\AssetReservation;
use Weline\CustomerAsset\Service\CustomerAssetAdminService;
use Weline\CustomerAsset\Service\CustomerAssetMigrationService;
use Weline\CustomerAsset\Service\CustomerAssetRolloutGate;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_CustomerAsset::commerce:partner:control-center', '客户资产', 'mdi-wallet-outline', '客户资产账户、预留、结算、退回与一致性诊断', 'Weline_Backend::commerce:partner:group')]
final class ControlCenter extends BackendController
{
    public function __construct(private readonly CustomerAssetAdminService $adminService)
    {
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:accounts', '资产账户', 'mdi-account-cash-outline', '查看客户资产账户')]
    public function accounts(): string
    {
        return $this->renderWorkspace('accounts', '资产账户', ['资产账户' => [AssetAccount::class, ['account_id', 'customer_id', 'website_id', 'asset_code', 'namespace', 'available_minor', 'reserved_minor', 'version', 'created_at', 'updated_at']]], [], ['kind' => 'credit', 'action' => 'customer_asset/backend/controlcenter/credit']);
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:ledger', '资产账本', 'mdi-book-open-variant', '查看客户资产账本')]
    public function ledger(): string
    {
        return $this->renderWorkspace('ledger', '资产账本', ['账本记录' => [AssetLedger::class, ['entry_id', 'event_id', 'account_id', 'customer_id', 'website_id', 'asset_code', 'namespace', 'event_type', 'amount_minor', 'reservation_id', 'balance_after_available', 'balance_after_reserved', 'account_version', 'created_at']]]);
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:reservations', '资产预留', 'mdi-lock-clock', '查看客户资产预留')]
    public function reservations(): string
    {
        return $this->renderWorkspace('reservations', '资产预留', ['预留记录' => [AssetReservation::class, ['reservation_id', 'account_id', 'customer_id', 'website_id', 'asset_code', 'namespace', 'amount_minor', 'returned_amount_minor', 'status', 'version', 'terminal_event_id', 'created_at', 'updated_at', 'terminal_at']]], [], ['kind' => 'reserve', 'action' => 'customer_asset/backend/controlcenter/reserve']);
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:settlements', '资产结算', 'mdi-cash-check', '查看已结算客户资产')]
    public function settlements(): string
    {
        return $this->renderWorkspace('settlements', '资产结算', ['已结算预留' => [AssetReservation::class, ['reservation_id', 'account_id', 'customer_id', 'website_id', 'asset_code', 'namespace', 'amount_minor', 'returned_amount_minor', 'status', 'version', 'terminal_event_id', 'terminal_at'], ['status' => AssetReservation::STATUS_COMMITTED]]], [], ['kind' => 'commit', 'action' => 'customer_asset/backend/controlcenter/commit']);
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:returns', '资产退回', 'mdi-cash-refund', '查看已退回客户资产')]
    public function returns(): string
    {
        return $this->renderWorkspace('returns', '资产退回', ['退回账本' => [AssetLedger::class, ['entry_id', 'event_id', 'account_id', 'customer_id', 'website_id', 'asset_code', 'namespace', 'event_type', 'amount_minor', 'reservation_id', 'balance_after_available', 'balance_after_reserved', 'account_version', 'created_at'], ['event_type' => AssetLedger::TYPE_RETURN]]], [], ['kind' => 'return', 'action' => 'customer_asset/backend/control-center/return-committed']);
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:exceptions', '一致性异常', 'mdi-alert-decagram-outline', '只读诊断客户资产一致性异常')]
    public function exceptions(): string
    {
        $websiteId = max(0, (int)$this->request->getParam('website_id', 0));
        try {
            $status = ObjectManager::getInstance(CustomerAssetMigrationService::class)->diagnoseIntegrity($websiteId);
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'integrity-diagnostic');
            $status = [
                'website_id' => $websiteId,
                'diagnostic_error' => (string)__('诊断暂时无法执行。参考编号：%{1}', [$reference]),
                'read_only' => true,
            ];
        }
        return $this->renderWorkspace('exceptions', '一致性异常', [], $status);
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:migration', '迁移状态', 'mdi-database-eye-outline', '只读查看客户资产迁移状态')]
    public function migration(): string
    {
        return $this->renderWorkspace('migration', '迁移状态', [], $this->rolloutStatus() + [
            'execution_policy' => 'registered_postgresql_full_clone_cli_only',
            'production_actions_exposed' => false,
        ]);
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:accounts', '资产入账', 'mdi-cash-plus', '向隔离命名空间资产账户入账')]
    public function credit()
    {
        return $this->executeWrite('credit', 'accounts', '资产入账成功。');
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:reservations', '预留资产', 'mdi-lock-plus-outline', '创建客户资产预留')]
    public function reserve()
    {
        return $this->executeWrite('reserve', 'reservations', '资产预留成功。');
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:settlements', '结算资产', 'mdi-cash-check', '提交资产预留结算')]
    public function commit()
    {
        return $this->executeWrite('commit', 'settlements', '资产结算成功。');
    }

    #[Acl('Weline_CustomerAsset::commerce:partner:returns', '退回资产', 'mdi-cash-refund', '退回已结算资产')]
    public function returnCommitted()
    {
        return $this->executeWrite('returnCommitted', 'returns', '资产退回成功。');
    }

    /** @param array<string,array{0:class-string,1:list<string>,2?:array<string,mixed>}> $sources */
    private function renderWorkspace(string $code, string $title, array $sources, array $status = [], array $form = []): string
    {
        $datasets = [];
        foreach ($sources as $label => $definition) {
            $datasets[] = $this->loadRows($label, $definition[0], $definition[1], $definition[2] ?? []);
        }
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
        return $this->redirect('customer_asset/backend/controlcenter/' . $returnPage);
    }

    /** @param class-string $modelClass @param list<string> $fields @param array<string,mixed> $filters */
    private function loadRows(string $label, string $modelClass, array $fields, array $filters = []): array
    {
        try {
            /** @var Model $model */
            $model = ObjectManager::getInstance($modelClass)->reset();
            foreach ($filters as $field => $value) {
                $model->where($field, $value);
            }
            $rows = $model->limit(50)->select()->fetchArray();
            $safeRows = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $safeRows[] = array_intersect_key($row, array_flip($fields));
                }
            }
            return ['label' => __($label), 'rows' => $safeRows, 'error' => ''];
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'load:' . $modelClass);
            return ['label' => __($label), 'rows' => [], 'error' => (string)__('数据暂时无法加载。参考编号：%{1}', [$reference])];
        }
    }

    private function rolloutStatus(): array
    {
        try {
            $configuration = ObjectManager::getInstance(CustomerAssetRolloutGate::class)->configuration();
            return [
                'mode' => (string)($configuration['mode'] ?? 'off'),
                'allowlist_count' => count((array)($configuration['allowlist'] ?? [])),
                'env_locked' => !empty($configuration['env_locked']),
            ];
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
