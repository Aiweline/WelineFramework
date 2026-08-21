<?php

declare(strict_types=1);

namespace Weline\B2B\Controller\Backend;

use Weline\B2B\Model\B2BOrderPriceSnapshotRecord;
use Weline\B2B\Model\B2BQuoteTokenRecord;
use Weline\B2B\Model\CustomerGroupMembershipRecord;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\PriceListItemRecord;
use Weline\B2B\Model\PriceListRecord;
use Weline\B2B\Service\B2BAdminService;
use Weline\B2B\Service\B2BRolloutGate;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_B2B::commerce:partner:control-center', 'B2B 管理', 'globe', 'B2B 客户组、价目表与报价审批管理', 'Weline_Backend::commerce:partner:group')]
final class ControlCenter extends BackendController
{
    public function __construct(private readonly B2BAdminService $adminService)
    {
    }

    #[Acl('Weline_B2B::commerce:partner:groups', '客户组', 'users', '查看 B2B 客户组')]
    public function groups(): string
    {
        return $this->renderWorkspace('groups', '客户组', [
            '客户组' => [CustomerGroupRecord::class, ['group_id', 'website_id', 'code', 'status', 'group_version', 'created_at', 'updated_at']],
            '客户组成员' => [CustomerGroupMembershipRecord::class, ['customer_id', 'website_id', 'group_id', 'membership_version', 'updated_at']],
        ], [], ['kind' => 'group', 'action' => 'b2b/backend/control-center/save-group']);
    }

    #[Acl('Weline_B2B::commerce:partner:price-lists', '价目表', 'tag', '查看 B2B 价目表')]
    public function priceLists(): string
    {
        return $this->renderWorkspace('price-lists', '价目表', [
            '价目表' => [PriceListRecord::class, ['list_id', 'group_id', 'website_id', 'version', 'channel_id', 'active', 'created_at']],
            '价目表项目' => [PriceListItemRecord::class, ['list_id', 'list_version', 'sku', 'amount_minor']],
        ], [], ['kind' => 'price-list', 'action' => 'b2b/backend/control-center/save-price-list']);
    }

    #[Acl('Weline_B2B::commerce:partner:quotes', '报价令牌', 'file', '查看 B2B 报价令牌')]
    public function quotes(): string
    {
        return $this->renderWorkspace('quotes', '报价审批', ['报价令牌' => [B2BQuoteTokenRecord::class, ['token_id', 'customer_id', 'website_id', 'sku', 'retail_amount_minor', 'amount_minor', 'source', 'group_id', 'price_list_id', 'list_version', 'channel_id', 'issued_at_epoch', 'expires_at_epoch', 'status', 'consumed_order_ref', 'consumed_at_epoch', 'created_at']]], [], ['kind' => 'quote', 'action' => 'b2b/backend/control-center/approve-quote']);
    }

    #[Acl('Weline_B2B::commerce:partner:snapshots', '订单价格快照', 'camera', '查看 B2B 订单价格快照')]
    public function snapshots(): string
    {
        return $this->renderWorkspace('snapshots', '订单价格快照', ['订单价格快照' => [B2BOrderPriceSnapshotRecord::class, ['order_ref', 'token_id', 'customer_id', 'website_id', 'sku', 'retail_amount_minor', 'amount_minor', 'source', 'group_id', 'price_list_id', 'list_version', 'channel_id', 'created_at_epoch', 'created_at']]]);
    }

    #[Acl('Weline_B2B::commerce:partner:migration', '迁移状态', 'eye', '只读查看 B2B 迁移状态')]
    public function migration(): string
    {
        return $this->renderWorkspace('migration', '迁移状态', [], $this->rolloutStatus() + [
            'execution_policy' => 'registered_postgresql_full_clone_cli_only',
            'production_actions_exposed' => false,
        ]);
    }

    #[Acl('Weline_B2B::commerce:partner:groups', '创建客户组', 'users', '创建 B2B 客户组')]
    public function saveGroup()
    {
        return $this->executeWrite('createGroup', 'groups', '客户组已创建。');
    }

    #[Acl('Weline_B2B::commerce:partner:price-lists', '创建价目表', 'plus', '创建 B2B 价目表')]
    public function savePriceList()
    {
        return $this->executeWrite('createPriceList', 'price-lists', '价目表已创建。');
    }

    #[Acl('Weline_B2B::commerce:partner:quotes', '审批报价', 'check', '签发报价、重新校验并生成不可变订单价格快照')]
    public function approveQuote()
    {
        return $this->executeWrite('approveQuote', 'quotes', '报价已审批并生成订单价格快照。');
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
        return $this->redirect('b2b/backend/control-center/' . $returnPage);
    }

    /** @param class-string $modelClass @param list<string> $fields */
    private function loadRows(string $label, string $modelClass, array $fields): array
    {
        try {
            $rows = ObjectManager::getInstance($modelClass)->reset()->limit(50)->select()->fetchArray();
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
            $configuration = ObjectManager::getInstance(B2BRolloutGate::class)->configuration();
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
