<?php

declare(strict_types=1);

namespace Weline\B2B\Controller\Backend;

use Weline\B2B\Model\B2BOrderHangRecord;
use Weline\B2B\Model\B2BOrderPriceSnapshotRecord;
use Weline\B2B\Model\B2BQuoteTokenRecord;
use Weline\B2B\Model\CustomerGroupMembershipRecord;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\MembershipApplicationRecord;
use Weline\B2B\Model\PriceListItemRecord;
use Weline\B2B\Model\PriceListRecord;
use Weline\B2B\Service\B2BAdminService;
use Weline\B2B\Service\B2BHangAdminListPresenter;
use Weline\B2B\Service\B2BRolloutGate;
use Weline\B2B\Service\B2BService;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\Backend\Api\Runtime\CurrentWebsiteStorefrontUrlProviderInterface;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_B2B::commerce:partner:control-center', 'B2B 管理', 'globe', 'B2B 客户组、价目表与报价审批管理', 'Weline_Backend::commerce:partner:group')]
final class ControlCenter extends BackendController
{
    use B2BBackendScopeTrait;

    public function __construct(private readonly B2BAdminService $adminService)
    {
    }

    #[Acl('Weline_B2B::commerce:partner:groups', '客户组', 'users', '查看 B2B 客户组')]
    public function groups(): string
    {
        return $this->renderWorkspace('groups', '客户组', [], [], [
            'kind' => 'group',
            'action' => 'b2b/backend/control-center/save-group',
            'rename_action' => 'b2b/backend/control-center/rename-group',
            'delete_action' => 'b2b/backend/control-center/delete-group',
            'capabilities_action' => 'b2b/backend/control-center/update-group-capabilities',
            'members_action' => 'b2b/backend/control-center/group-members',
            'assign_member_action' => 'b2b/backend/control-center/assign-group-member',
            'remove_member_action' => 'b2b/backend/control-center/remove-group-member',
            'reorder_action' => 'b2b/backend/control-center/reorder-groups',
        ], [
            'group_options' => $this->adminService->listActiveGroupOptions(),
            'admin_groups' => $this->adminService->listAdminGroups(),
        ]);
    }

    #[Acl('Weline_B2B::commerce:partner:price-lists', '价目表', 'tag', '查看 B2B 价目表')]
    public function priceLists(): string
    {
        return $this->renderWorkspace('price-lists', '价目表', [
            '价目表' => [PriceListRecord::class, ['list_id', 'group_id', 'website_id', 'version', 'channel_id', 'active', 'created_at']],
            '价目表项目' => [PriceListItemRecord::class, ['list_id', 'list_version', 'sku', 'min_qty', 'amount_minor']],
        ], [], [
            'kind' => 'price-list',
            'action' => 'b2b/backend/control-center/save-price-list',
        ], [
            'group_options' => $this->adminService->listActiveGroupOptions(),
        ]);
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

    #[Acl('Weline_B2B::commerce:partner:applications', '身份申请', 'user-check', '查看与审核 B2B 身份申请')]
    public function applications(): string
    {
        return $this->renderWorkspace('applications', '身份申请', [
            '身份申请' => [MembershipApplicationRecord::class, [
                'application_id',
                'customer_id',
                'website_id',
                'company_name',
                'contact_phone',
                'status',
                'assigned_group_id',
                'notes',
                'created_at',
                'updated_at',
            ]],
        ], $this->applicationsWorkspaceStatus(), [
            'kind' => 'membership-application',
            'action' => 'b2b/backend/control-center/approve-membership-application',
            'reject_action' => 'b2b/backend/control-center/reject-membership-application',
            'revoke_action' => 'b2b/backend/control-center/revoke-membership',
            'reauthorize_action' => 'b2b/backend/control-center/reauthorize-membership-application',
            'delete_application_action' => 'b2b/backend/control-center/remove-membership-application',
        ], [
            'group_options' => $this->adminService->listActiveGroupOptions(),
            'rollout_blocks_writes' => $this->rolloutBlocksWrites(),
        ]);
    }

    #[Acl('Weline_B2B::commerce:partner:hang-orders', '定金挂单', 'clock', '查看与审批 B2B 定金挂单')]
    public function hangOrders(): string
    {
        return $this->renderWorkspace('hang-orders', '定金挂单', [
            '定金挂单' => [B2BOrderHangRecord::class, [
                'hang_id',
                'order_ref',
                'customer_id',
                'website_id',
                'hang_status',
                'goods_subtotal_taxed_minor',
                'deposit_amount_minor',
                'balance_amount_minor',
                'shipping_amount_minor',
                'deposit_ratio_bps',
                'deposit_intent_code',
                'balance_intent_code',
                'created_at_epoch',
                'updated_at_epoch',
            ]],
        ], [], [
            'kind' => 'hang-order',
            'action' => 'b2b/backend/control-center/approve-hang-order',
            'reject_action' => 'b2b/backend/control-center/reject-hang-order',
        ]);
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

    #[Acl('Weline_B2B::commerce:partner:groups', '重命名客户组', 'edit', '修改 B2B 客户组显示名称')]
    public function renameGroup()
    {
        return $this->executeWrite('renameGroup', 'groups', '客户组名称已更新。');
    }

    #[Acl('Weline_B2B::commerce:partner:groups', '删除客户组', 'trash', '删除非系统 B2B 客户组')]
    public function deleteGroup()
    {
        return $this->executeWrite('deleteGroup', 'groups', '客户组已删除。');
    }

    #[Acl('Weline_B2B::commerce:partner:groups', '更新客户组能力', 'edit', '更新 B2B 客户组说明与额度门槛')]
    public function updateGroupCapabilities()
    {
        return $this->executeWrite('updateGroupCapabilities', 'groups', '客户组能力已更新。');
    }

    #[Acl('Weline_B2B::commerce:partner:groups', '排序客户组', 'sort', '拖拽或批量更新 B2B 客户组显示排序')]
    public function reorderGroups()
    {
        return $this->executeWrite('reorderGroups', 'groups', '客户组排序已保存。');
    }

    #[Acl('Weline_B2B::commerce:partner:groups', '列出客户组成员', 'users', '异步列出 B2B 客户组成员')]
    public function groupMembers(): string
    {
        try {
            $payload = $this->adminService->listGroupMembers((array)$this->request->getGet());
            return $this->jsonOk($payload);
        } catch (\Throwable $throwable) {
            return $this->jsonError($throwable, 'group-members');
        }
    }

    #[Acl('Weline_B2B::commerce:partner:groups', '添加客户组成员', 'user-plus', '将客户加入 B2B 客户组')]
    public function assignGroupMember()
    {
        return $this->executeWrite('assignGroupMember', 'groups', '客户已加入该组；若批发信用已开启将按档补齐额度。');
    }

    #[Acl('Weline_B2B::commerce:partner:groups', '移除客户组成员', 'user-minus', '将客户移出 B2B 客户组')]
    public function removeGroupMember()
    {
        return $this->executeWrite('removeGroupMember', 'groups', '客户已移出该组。');
    }

    #[Acl('Weline_B2B::commerce:partner:price-lists', '创建价目表', 'plus', '创建 B2B 价目表')]
    public function savePriceList()
    {
        return $this->executeWrite('createPriceList', 'price-lists', '价目表已创建。');
    }

    #[Acl('Weline_B2B::commerce:partner:price-lists', '保存商品阶梯价', 'edit', '从商品编辑保存 B2B SKU 数量阶梯价')]
    public function saveProductSkuTiers(): string
    {
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $payload = $this->adminService->upsertSkuQtyTiers((array)$this->request->getPost());
            return $this->jsonOk(['tiers' => $payload]);
        } catch (\Weline\B2B\Service\B2BConflictException $conflict) {
            return $this->jsonError($conflict, 'save-product-sku-tiers');
        } catch (\Throwable $throwable) {
            return $this->jsonError($throwable, 'save-product-sku-tiers');
        }
    }

    #[Acl('Weline_B2B::commerce:partner:quotes', '审批报价', 'check', '签发报价、重新校验并生成不可变订单价格快照')]
    public function approveQuote()
    {
        return $this->executeWrite('approveQuote', 'quotes', '报价已审批并生成订单价格快照。');
    }

    #[Acl('Weline_B2B::commerce:partner:applications', '批准身份申请', 'check', '批准 B2B 身份申请并指定客户组')]
    public function approveMembershipApplication()
    {
        return $this->executeWrite(
            'approveMembershipApplication',
            'applications',
            '身份申请已批准并写入客户组。',
        );
    }

    #[Acl('Weline_B2B::commerce:partner:applications', '驳回身份申请', 'x', '驳回 B2B 身份申请')]
    public function rejectMembershipApplication()
    {
        return $this->executeWrite(
            'rejectMembershipApplication',
            'applications',
            '身份申请已驳回。',
        );
    }

    #[Acl('Weline_B2B::commerce:partner:applications', '撤销批发资格', 'user-minus', '撤销客户批发资格（不改申请审计行）')]
    public function revokeMembership()
    {
        return $this->executeWrite(
            'revokeMembership',
            'applications',
            '批发资格已撤销。已生成挂单按原状态机继续；新交易将拒绝。',
        );
    }

    #[Acl('Weline_B2B::commerce:partner:applications', '重新授权批发资格', 'user-check', '对资格已撤销的已批申请重新指定客户组并授权')]
    public function reauthorizeMembershipApplication()
    {
        return $this->executeWrite(
            'reauthorizeMembershipApplication',
            'applications',
            '批发资格已重新授权并写入客户组。',
        );
    }

    #[Acl('Weline_B2B::commerce:partner:applications:delete', '删除身份申请', 'trash', '删除 B2B 身份申请记录')]
    public function removeMembershipApplication()
    {
        return $this->executeWrite(
            'deleteMembershipApplication',
            'applications',
            '身份申请记录已删除。',
        );
    }

    #[Acl('Weline_B2B::commerce:partner:hang-orders', '批准挂单', 'check', '批准 B2B 定金挂单进入尾款')]
    public function approveHangOrder()
    {
        return $this->executeWrite('approveHangOrder', 'hang-orders', '挂单已批准，进入尾款。');
    }

    #[Acl('Weline_B2B::commerce:partner:hang-orders', '驳回挂单', 'x', '驳回 B2B 定金挂单并退定金')]
    public function rejectHangOrder()
    {
        return $this->executeWrite('rejectHangOrder', 'hang-orders', '挂单已驳回。');
    }

    /**
     * @param array<string,array{0:class-string,1:list<string>}> $sources
     * @param array<string,mixed> $extra
     */
    private function renderWorkspace(
        string $code,
        string $title,
        array $sources,
        array $status = [],
        array $form = [],
        array $extra = [],
    ): string {
        $scope = $this->assignB2bWorkScope(false);
        $filterWebsiteId = $scope['filter_website_id'];
        $filterChannelId = $scope['filter_channel_id'];

        $datasets = [];
        $hangTab = 'awaiting';
        $hangTabCounts = [];
        foreach ($sources as $label => [$modelClass, $fields]) {
            if (($form['kind'] ?? '') === 'hang-order' && $modelClass === B2BOrderHangRecord::class) {
                $hangTab = $this->resolveHangTab();
                $statusFilter = $this->hangTabToStatus($hangTab);
                $dataset = $this->loadHangRows($label, $fields, $statusFilter, $filterWebsiteId);
                $hangTabCounts = $this->hangTabCounts($filterWebsiteId);
                $dataset['rows'] = ObjectManager::getInstance(B2BHangAdminListPresenter::class)
                    ->enrich((array)($dataset['rows'] ?? []));
                $datasets[] = $dataset;
                continue;
            }
            $dataset = $this->loadRows($label, $modelClass, $fields);
            $dataset['rows'] = $this->filterRowsByB2bScope(
                (array)($dataset['rows'] ?? []),
                $filterWebsiteId,
                $filterChannelId,
            );
            $datasets[] = $dataset;
        }
        if (($form['kind'] ?? '') === 'membership-application') {
            $datasets = $this->enrichApplicationEntitlement($datasets);
        }
        $groupOptions = $this->filterRowsByB2bScope(
            (array)($extra['group_options'] ?? []),
            $filterWebsiteId,
            null,
        );
        $adminGroups = $this->filterRowsByB2bScope(
            (array)($extra['admin_groups'] ?? []),
            $filterWebsiteId,
            null,
        );
        $storefrontBase = $code === 'hang-orders' ? $this->resolveStorefrontBaseUrl() : '';
        $this->assign('workspace_code', $code);
        $this->assign('workspace_title', __($title));
        $this->assign('workspace_status', $status);
        $this->assign('workspace_datasets', $datasets);
        $this->assign('workspace_form', $form);
        $this->assign('storefront_base_url', $storefrontBase);
        $this->assign('group_options', $groupOptions);
        $this->assign('admin_groups', $adminGroups);
        if (($form['kind'] ?? '') === 'hang-order') {
            $this->assign('hang_tab', $hangTab);
            $this->assign('hang_tab_counts', $hangTabCounts);
            $this->assign('hang_tab_query_base', $this->b2bScopeQuery($scope));
        }
        if (($form['kind'] ?? '') === 'group') {
            $this->assign(
                'base_currency',
                (new \Weline\B2B\Service\B2BBaseCurrencyResolver())->forWebsite((int)($filterWebsiteId ?? 0)),
            );
        }
        foreach ($extra as $key => $value) {
            if ($key === 'group_options' || $key === 'admin_groups') {
                continue;
            }
            $this->assign((string)$key, $value);
        }
        return $this->fetch('index');
    }

    private function resolveStorefrontBaseUrl(): string
    {
        try {
            $provider = ObjectManager::getInstance(CurrentWebsiteStorefrontUrlProviderInterface::class);
            if ($provider instanceof CurrentWebsiteStorefrontUrlProviderInterface) {
                return rtrim(trim($provider->resolve($this->request)), '/');
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function executeWrite(string $command, string $returnPage, string $successMessage)
    {
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $this->adminService->{$command}((array)$this->request->getPost());
            MessageManager::success((string)__($successMessage));
        } catch (\Weline\B2B\Service\B2BConflictException $conflict) {
            $reference = $this->reportFailure($conflict, 'write:' . $command);
            $detail = trim($conflict->getMessage());
            MessageManager::error(
                $detail !== ''
                    ? ($detail . ' ' . (string)__('参考编号：%{1}', [$reference]))
                    : (string)__('操作失败，请稍后重试。参考编号：%{1}', [$reference]),
            );
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'write:' . $command);
            MessageManager::error((string)__('操作失败，请稍后重试。参考编号：%{1}', [$reference]));
        }
        $scope = $this->assignB2bWorkScope(true);
        $query = $this->b2bScopeQuery($scope);
        // 行内操作仍可能只带 website_id；无 target_scope 时回落旧深链。
        if (($query['target_scope'] ?? '') === 'default.default.default'
            && trim((string)$this->request->getPost('target_scope', '')) === '') {
            $scopeWebsite = trim((string)$this->request->getPost('website_id', ''));
            if ($scopeWebsite === '') {
                $multi = trim((string)$this->request->getPost('website_ids', ''));
                if (preg_match('/^\d+$/', $multi) === 1) {
                    $scopeWebsite = $multi;
                }
            }
            if ($scopeWebsite !== '' && preg_match('/^\d+$/', $scopeWebsite) === 1) {
                return $this->redirect('b2b/backend/control-center/' . $returnPage, [
                    'website_id' => $scopeWebsite,
                ]);
            }
        }

        return $this->redirect('b2b/backend/control-center/' . $returnPage, $query);
    }

    /** @param array<string,mixed> $payload */
    private function jsonOk(array $payload): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        return (string)json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function jsonError(\Throwable $throwable, string $context): string
    {
        $reference = $this->reportFailure($throwable, $context);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        $message = trim($throwable->getMessage());
        return (string)json_encode([
            'ok' => false,
            'error' => $message !== '' ? $message : (string)__('加载失败'),
            'reference' => $reference,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param list<string> $fields */
    private function loadHangRows(string $label, array $fields, ?string $statusFilter, ?int $filterWebsiteId): array
    {
        try {
            /** @var B2BOrderHangRecord $model */
            $model = ObjectManager::getInstance(B2BOrderHangRecord::class);
            $model->reset();
            if ($statusFilter !== null && $statusFilter !== '') {
                $model->where(B2BOrderHangRecord::schema_fields_HANG_STATUS, $statusFilter);
            }
            if ($filterWebsiteId !== null) {
                $model->where(B2BOrderHangRecord::schema_fields_WEBSITE_ID, $filterWebsiteId);
            }
            $rows = $model
                ->order(B2BOrderHangRecord::schema_fields_UPDATED_AT_EPOCH, 'DESC')
                ->limit(50)
                ->select()
                ->fetchArray();
            $safeRows = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row)) {
                    $safeRows[] = array_intersect_key($row, array_flip($fields));
                }
            }

            return ['label' => __($label), 'rows' => $safeRows, 'error' => ''];
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'load:hang');
            return ['label' => __($label), 'rows' => [], 'error' => (string)__('数据暂时无法加载。参考编号：%{1}', [$reference])];
        }
    }

    private function resolveHangTab(): string
    {
        $tab = strtolower(trim((string)$this->request->getGet('hang_tab', 'awaiting')));
        $allowed = ['awaiting', 'deposit', 'balance', 'completed', 'rejected', 'expired', 'all'];
        if (!in_array($tab, $allowed, true)) {
            return 'awaiting';
        }

        return $tab;
    }

    private function hangTabToStatus(string $tab): ?string
    {
        return match ($tab) {
            'awaiting' => 'awaiting_merchant_approval',
            'deposit' => 'awaiting_deposit',
            'balance' => 'awaiting_balance',
            'completed' => 'completed',
            'rejected' => 'rejected',
            'expired' => 'expired',
            default => null,
        };
    }

    /**
     * @return array<string,int>
     */
    private function hangTabCounts(?int $filterWebsiteId): array
    {
        $map = [
            'awaiting' => 'awaiting_merchant_approval',
            'deposit' => 'awaiting_deposit',
            'balance' => 'awaiting_balance',
            'completed' => 'completed',
            'rejected' => 'rejected',
            'expired' => 'expired',
        ];
        $counts = ['all' => 0];
        foreach ($map as $tab => $status) {
            $counts[$tab] = 0;
        }
        try {
            /** @var B2BOrderHangRecord $model */
            $model = ObjectManager::getInstance(B2BOrderHangRecord::class);
            $model->reset();
            if ($filterWebsiteId !== null) {
                $model->where(B2BOrderHangRecord::schema_fields_WEBSITE_ID, $filterWebsiteId);
            }
            $rows = $model->select()->fetchArray();
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $counts['all']++;
                $status = strtolower(trim((string)($row['hang_status'] ?? '')));
                foreach ($map as $tab => $want) {
                    if ($status === $want) {
                        $counts[$tab]++;
                    }
                }
            }
        } catch (\Throwable) {
            // keep zeros
        }

        return $counts;
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

    /**
     * Project live entitlement onto application audit rows (revoke does not rewrite audit status).
     *
     * @param list<array{label:mixed,rows:list<array<string,mixed>>,error:string}> $datasets
     * @return list<array{label:mixed,rows:list<array<string,mixed>>,error:string}>
     */
    private function enrichApplicationEntitlement(array $datasets): array
    {
        try {
            $store = ObjectManager::getInstance(CustomerGroupStore::class);
        } catch (\Throwable) {
            $store = new CustomerGroupStore();
        }
        if (!$store instanceof CustomerGroupStore) {
            $store = new CustomerGroupStore();
        }

        foreach ($datasets as $di => $dataset) {
            if (!is_array($dataset) || !isset($dataset['rows']) || !is_array($dataset['rows'])) {
                continue;
            }
            foreach ($dataset['rows'] as $ri => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $customerId = trim((string)($row['customer_id'] ?? ''));
                $websiteId = (int)($row['website_id'] ?? -1);
                $active = false;
                $groupId = null;
                if ($customerId !== '' && $websiteId >= 0) {
                    try {
                        $group = $store->groupForCustomer($customerId, $websiteId);
                        if ($group !== null && $group->isActive()) {
                            $active = true;
                            $groupId = $group->groupId;
                        }
                    } catch (\Throwable) {
                        $active = false;
                    }
                }
                $row['entitlement_active'] = $active;
                $row['entitlement_group_id'] = $groupId;
                $datasets[$di]['rows'][$ri] = $row;
            }
        }

        return $datasets;
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

    /** @return array<string,string> */
    private function applicationsWorkspaceStatus(): array
    {
        if (!$this->rolloutBlocksWrites()) {
            return [];
        }
        $mode = (string)($this->rolloutStatus()['mode'] ?? 'off');

        return [
            (string)__('灰度写入') => (string)__(
                '当前 B2B 灰度未开启（%{1}）。批准/改组等写入会被拒绝；请先开启白名单或全面开启后再批。',
                [$mode],
            ),
        ];
    }

    private function rolloutBlocksWrites(): bool
    {
        try {
            $gate = ObjectManager::getInstance(B2BRolloutGate::class);
            return !$gate->isEffectivelyOn(B2BService::CAPABILITY, B2BRolloutGate::scopeKey(0));
        } catch (\Throwable) {
            return true;
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
