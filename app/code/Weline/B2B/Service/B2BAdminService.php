<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Service\B2BConflictException;
use Weline\Framework\Manager\ObjectManager;

/**
 * Explicitly gated backend commands. The underlying B2B service remains the
 * owner of group, price-list, quote recheck and immutable snapshot rules.
 */
final class B2BAdminService
{
    private ?MembershipApplicationService $membershipApplicationsLazy = null;
    private ?B2BHangOrderService $hangOrdersLazy = null;

    public function __construct(
        private readonly B2BService $service,
        ?MembershipApplicationService $membershipApplications = null,
        ?B2BHangOrderService $hangOrders = null,
    ) {
        $this->membershipApplicationsLazy = $membershipApplications;
        $this->hangOrdersLazy = $hangOrders;
    }

    private function membershipApplications(): MembershipApplicationService
    {
        return $this->membershipApplicationsLazy ??= new MembershipApplicationService(
            new CustomerGroupStore(),
        );
    }

    private function hangOrders(): B2BHangOrderService
    {
        return $this->hangOrdersLazy ??= new B2BHangOrderService();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createGroup(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        $this->assertMutable($websiteId);
        $name = trim((string)($input['name'] ?? $input['code'] ?? ''));
        $code = trim((string)($input['code'] ?? ''));
        if ($code === '') {
            $code = $this->slugCode($name !== '' ? $name : 'group');
        }
        $groupId = trim((string)($input['group_id'] ?? ''));
        if ($groupId === '') {
            $groupId = 'g-' . bin2hex(random_bytes(6));
        }
        if ($name === '') {
            $name = $code;
        }
        $created = $this->service->seedGroup(
            $groupId,
            $websiteId,
            $code,
            trim((string)($input['status'] ?? CustomerGroup::STATUS_ACTIVE)),
        );
        $store = new CustomerGroupStore();
        $store->updateDisplayName($created->groupId, $name);
        $row = $created->toArray();
        $row['name'] = $name;
        $row['is_system'] = false;
        return $row;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function renameGroup(array $input): array
    {
        $groupId = trim((string)($input['group_id'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $store = new CustomerGroupStore();
        $group = $store->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        $this->assertMutable($group->websiteId);
        $store->updateDisplayName($groupId, $name);
        return [
            'group_id' => $groupId,
            'name' => $name,
            'is_system' => $store->isSystemGroup($groupId),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function deleteGroup(array $input): array
    {
        $groupId = trim((string)($input['group_id'] ?? ''));
        $store = new CustomerGroupStore();
        $group = $store->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        $this->assertMutable($group->websiteId);
        $store->deleteGroup($groupId);
        return ['group_id' => $groupId, 'deleted' => true];
    }

    /**
     * @return list<array{value:string,label:string,website_id:int,code:string,name?:string}>
     */
    public function listActiveGroupOptions(): array
    {
        $store = new CustomerGroupStore();
        $store->ensureSystemVipLadder(0);
        return $store->listActiveOptions();
    }

    /**
     * @return list<array{group_id:string,website_id:int,code:string,name:string,status:string,is_system:bool}>
     */
    public function listAdminGroups(): array
    {
        $store = new CustomerGroupStore();
        $store->ensureSystemVipLadder(0);
        return $store->listAdminRows();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateGroupCapabilities(array $input): array
    {
        $groupId = trim((string)($input['group_id'] ?? ''));
        $store = new CustomerGroupStore();
        $group = $store->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        $this->assertMutable($group->websiteId);
        $name = trim((string)($input['name'] ?? ''));
        if ($name !== '') {
            $store->updateDisplayName($groupId, $name);
        }
        $payload = [];
        if (array_key_exists('description', $input)) {
            $payload['description'] = (string)$input['description'];
        }
        if (array_key_exists('credit_limit_minor', $input)) {
            $payload['credit_limit_minor'] = (int)$input['credit_limit_minor'];
        }
        if (array_key_exists('spend_threshold_minor', $input)) {
            $payload['spend_threshold_minor'] = (int)$input['spend_threshold_minor'];
        }
        if (array_key_exists('status', $input)) {
            $payload['status'] = (string)$input['status'];
        }
        if (array_key_exists('tier_rank', $input) || array_key_exists('sort_order', $input)) {
            $payload['tier_rank'] = (int)($input['tier_rank'] ?? $input['sort_order'] ?? -1);
        }
        $store->updateGroupCapabilities($groupId, $payload);
        $meta = $store->groupCreditMeta($groupId) ?? [];
        return [
            'group_id' => $groupId,
            'name' => (string)($meta['name'] ?? ($name !== '' ? $name : '')),
            'is_system' => $store->isSystemGroup($groupId),
            'description' => (string)($meta['description'] ?? ''),
            'credit_limit_minor' => (int)($meta['credit_limit_minor'] ?? 0),
            'spend_threshold_minor' => (int)($meta['spend_threshold_minor'] ?? 0),
            'tier_rank' => (int)($meta['tier_rank'] ?? -1),
            'status' => (string)($meta['status'] ?? $group->status),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ordered:list<array{group_id:string,tier_rank:int}>}
     */
    public function reorderGroups(array $input): array
    {
        $raw = $input['group_ids'] ?? $input['ordered_group_ids'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : (preg_split('/\s*,\s*/', $raw) ?: []);
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $ids = [];
        foreach ($raw as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            throw new \InvalidArgumentException(__('请提供排序后的客户组列表'));
        }
        $store = new CustomerGroupStore();
        $first = $store->get($ids[0]);
        if ($first === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$ids[0]]),
                ['group_id' => $ids[0]],
            );
        }
        $this->assertMutable($first->websiteId);
        return ['ordered' => $store->reorderGroups($ids)];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{group_id:string,query:string,base_currency:string,members:list<array<string,mixed>>}
     */
    public function listGroupMembers(array $input, ?GroupMemberDisplayPresenter $presenter = null): array
    {
        $groupId = trim((string)($input['group_id'] ?? ''));
        $query = trim((string)($input['q'] ?? $input['query'] ?? ''));
        $limit = (int)($input['limit'] ?? 50);
        $limit = max(1, min(200, $limit));
        $store = new CustomerGroupStore();
        $group = $store->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        // 先取组内成员再富化过滤，以便姓名/邮箱搜索（Store 仅按 customer_id LIKE）。
        $fetchLimit = $query !== '' ? max($limit, 200) : $limit;
        $members = $store->listMembersByGroup($groupId, '', $fetchLimit);
        $presenter ??= GroupMemberDisplayPresenter::createDefault();
        $members = $presenter->enrich($members);
        if ($query !== '') {
            $members = $presenter->filter($members, $query);
            $members = array_values(array_slice($members, 0, $limit));
        }
        $baseCurrency = (new B2BBaseCurrencyResolver())->forWebsite($group->websiteId);

        return [
            'group_id' => $groupId,
            'query' => $query,
            'base_currency' => $baseCurrency,
            'members' => $members,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{group_id:string,customer_id:string,website_id:int,assigned:bool}
     */
    public function assignGroupMember(array $input): array
    {
        $groupId = trim((string)($input['group_id'] ?? ''));
        $customerId = trim((string)($input['customer_id'] ?? ''));
        $store = new CustomerGroupStore();
        $group = $store->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        $this->assertMutable($group->websiteId);
        if ($customerId === '') {
            throw new \InvalidArgumentException(__('请选择客户'));
        }
        $store->assignCustomer($customerId, $groupId);
        $creditGrant = $this->grantCreditAfterAssign($customerId, $group->websiteId, $groupId);

        return [
            'group_id' => $groupId,
            'customer_id' => $customerId,
            'website_id' => $group->websiteId,
            'assigned' => true,
            'credit_grant' => $creditGrant,
        ];
    }

    /**
     * Same top-up path as membership approve: fill b2b_credit up to group credit_limit.
     *
     * @return array{ok:bool,granted_minor:int,target_minor:int,balance_minor:int,skipped:?string}|null
     */
    private function grantCreditAfterAssign(string $customerId, int $websiteId, string $groupId): ?array
    {
        try {
            $grant = ObjectManager::getInstance(B2BCreditGrantService::class);
        } catch (\Throwable) {
            return null;
        }
        if (!$grant instanceof B2BCreditGrantService) {
            return null;
        }
        try {
            return $grant->grantToTarget($customerId, $websiteId, $groupId);
        } catch (\Throwable $e) {
            w_log_error('b2b_credit_grant_after_assign_failed: ' . $e->getMessage(), [
                'customer_id' => $customerId,
                'website_id' => $websiteId,
                'group_id' => $groupId,
            ]);

            return [
                'ok' => false,
                'granted_minor' => 0,
                'target_minor' => 0,
                'balance_minor' => 0,
                'skipped' => 'grant_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{group_id:string,customer_id:string,website_id:int,removed:bool}
     */
    public function removeGroupMember(array $input): array
    {
        $groupId = trim((string)($input['group_id'] ?? ''));
        $customerId = trim((string)($input['customer_id'] ?? ''));
        $store = new CustomerGroupStore();
        $group = $store->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        $this->assertMutable($group->websiteId);
        if ($customerId === '') {
            throw new \InvalidArgumentException(__('请选择客户'));
        }
        $current = $store->groupForCustomer($customerId, $group->websiteId);
        if ($current === null || $current->groupId !== $groupId) {
            return [
                'group_id' => $groupId,
                'customer_id' => $customerId,
                'website_id' => $group->websiteId,
                'removed' => false,
            ];
        }
        $removed = $store->unassignCustomer($customerId, $group->websiteId);

        return [
            'group_id' => $groupId,
            'customer_id' => $customerId,
            'website_id' => $group->websiteId,
            'removed' => $removed,
        ];
    }

    private function slugCode(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? 'group';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'group';
        }
        return substr($slug, 0, 64);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createPriceList(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        $this->assertMutable($websiteId);
        $listId = trim((string)($input['list_id'] ?? ''));
        if ($listId === '') {
            $listId = 'pl-' . bin2hex(random_bytes(6));
        }
        $skuAmounts = $this->normalizeCreatePriceListAmounts($input);
        return $this->service->seedPriceList(
            $listId,
            trim((string)($input['group_id'] ?? '')),
            $websiteId,
            (int)($input['version'] ?? 1),
            $skuAmounts,
            trim((string)($input['channel_id'] ?? '')) ?: null,
            true,
        )->toMeta();
    }

    /**
     * Product-edit website-level qty tiers (channel_id=null) with full copy-forward.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function upsertSkuQtyTiers(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        $this->assertMutable($websiteId);

        return $this->productSkuQtyTiers()->upsertSkuQtyTiers($input);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string, array<int,int>|int>
     */
    private function normalizeCreatePriceListAmounts(array $input): array
    {
        $sku = trim((string)($input['sku'] ?? ''));
        if ($sku === '') {
            throw new \InvalidArgumentException((string)__('请填写商品 SKU'));
        }
        $minQty = (int)($input['min_qty'] ?? 1);
        if ($minQty < 1) {
            $minQty = 1;
        }
        $amount = (int)($input['amount_minor'] ?? -1);
        if ($amount < 0) {
            throw new \InvalidArgumentException((string)__('批发价（分）必须 ≥ 0'));
        }
        $out = [$sku => [$minQty => $amount]];

        $tierRows = $input['tiers'] ?? null;
        if (is_array($tierRows)) {
            foreach ($tierRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowSku = trim((string)($row['sku'] ?? $sku));
                if ($rowSku === '') {
                    $rowSku = $sku;
                }
                $rowMinRaw = $row['min_qty'] ?? null;
                $rowAmountRaw = $row['amount_minor'] ?? null;
                if ($rowMinRaw === null || $rowMinRaw === '' || $rowAmountRaw === null || $rowAmountRaw === '') {
                    continue;
                }
                $rowMin = (int)$rowMinRaw;
                $rowAmount = (int)$rowAmountRaw;
                if ($rowMin < 1 || $rowAmount < 0) {
                    continue;
                }
                $out[$rowSku][$rowMin] = $rowAmount;
            }
        }

        return $out;
    }

    private function productSkuQtyTiers(): ProductSkuQtyTierAdminService
    {
        return new ProductSkuQtyTierAdminService($this->service);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function approveMembershipApplication(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        if ($websiteId >= 0) {
            $this->assertMutable($websiteId);
        }
        return $this->membershipApplications()->approve(
            trim((string)($input['application_id'] ?? '')),
            trim((string)($input['group_id'] ?? '')),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reauthorizeMembershipApplication(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        if ($websiteId >= 0) {
            $this->assertMutable($websiteId);
        }

        return $this->membershipApplications()->reauthorize(
            trim((string)($input['application_id'] ?? '')),
            trim((string)($input['group_id'] ?? '')),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function rejectMembershipApplication(array $input): array
    {
        return $this->membershipApplications()->reject(
            trim((string)($input['application_id'] ?? '')),
            trim((string)($input['notes'] ?? '')),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function deleteMembershipApplication(array $input): array
    {
        return $this->membershipApplications()->delete(
            trim((string)($input['application_id'] ?? '')),
        );
    }

    /**
     * Revoke active ToB entitlement. Does not rewrite application audit rows.
     * Accepts one website_id or multi website_ids (CSV / list) for quick revoke.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function revokeMembership(array $input): array
    {
        $customerId = trim((string)($input['customer_id'] ?? ''));
        $websiteIds = $this->normalizeWebsiteIds($input);
        if ($customerId === '' || $websiteIds === []) {
            throw new B2BConflictException(
                'b2b_membership_revoke_invalid',
                __('撤销资格参数非法'),
                ['customer_id' => $customerId, 'website_ids' => $websiteIds],
            );
        }

        $store = new CustomerGroupStore();
        $results = [];
        $anyRemoved = false;
        foreach ($websiteIds as $websiteId) {
            $this->assertMutable($websiteId);
            $removed = $store->unassignCustomer($customerId, $websiteId);
            $anyRemoved = $anyRemoved || $removed;
            $results[] = [
                'website_id' => $websiteId,
                'revoked' => $removed,
            ];
        }

        return [
            'customer_id' => $customerId,
            'website_ids' => $websiteIds,
            'website_id' => $websiteIds[0],
            'revoked' => $anyRemoved,
            'results' => $results,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return list<int>
     */
    private function normalizeWebsiteIds(array $input): array
    {
        $raw = $input['website_ids'] ?? $input['website_id'] ?? null;
        $parts = [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                $parts[] = (string)$item;
            }
        } else {
            $parts = preg_split('/\s*,\s*/', trim((string)$raw)) ?: [];
        }
        $ids = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '' || !preg_match('/^\d+$/', $part)) {
                continue;
            }
            $id = (int)$part;
            if ($id < 0 || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function approveHangOrder(array $input): array
    {
        $key = trim((string)($input['hang_id'] ?? $input['order_ref'] ?? ''));
        $balanceIntent = trim((string)($input['balance_intent_code'] ?? '')) ?: null;

        return $this->hangOrders()->approve($key, $balanceIntent);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function rejectHangOrder(array $input): array
    {
        $key = trim((string)($input['hang_id'] ?? $input['order_ref'] ?? ''));

        return $this->hangOrders()->reject(
            $key,
            notes: trim((string)($input['notes'] ?? '')),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function approveQuote(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        $this->assertMutable($websiteId);
        $customerId = trim((string)($input['customer_id'] ?? ''));
        $quoteRequest = [
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'sku' => trim((string)($input['sku'] ?? '')),
            'retail_amount_minor' => (int)($input['retail_amount_minor'] ?? -1),
        ];
        $channelId = trim((string)($input['channel_id'] ?? ''));
        if ($channelId !== '') {
            $quoteRequest['channel_id'] = $channelId;
        }
        $issued = $this->service->issueQuote($quoteRequest);
        $tokenId = trim((string)($issued['token']['token_id'] ?? ''));
        if ($tokenId === '') {
            throw new \RuntimeException('b2b_admin_quote_token_missing');
        }
        return $this->service->submit(
            $tokenId,
            $customerId,
            $websiteId,
            trim((string)($input['order_ref'] ?? '')),
        );
    }

    private function assertMutable(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('b2b_admin_website_invalid');
        }
        try {
            $this->service->rollout()->assertMutable(
                B2BService::CAPABILITY,
                B2BRolloutGate::scopeKey($websiteId),
            );
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_starts_with($message, 'commerce_rollout_immutable:')) {
                $mode = substr($message, strlen('commerce_rollout_immutable:')) ?: 'off';
                throw new B2BConflictException(
                    'b2b_rollout_immutable',
                    __('B2B 灰度未对该站点开启（当前模式：%{1}），无法批准或写入。请先到「迁移状态」确认，或由运维将站点加入白名单后再试。', [$mode]),
                    ['website_id' => $websiteId, 'mode' => $mode],
                    0,
                    $exception,
                );
            }
            throw $exception;
        }
    }
}
