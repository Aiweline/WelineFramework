<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Throwable;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\CustomerGroupMembershipRecord;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\SystemVipLadder;
use Weline\Framework\Manager\ObjectManager;

/** Durable group/membership store with an explicit memory-only test seam. */
final class CustomerGroupStore
{
    /** @var array<string, CustomerGroup>|null */
    private ?array $rows = null;

    /** @var array<string, string> */
    private array $membership = [];

    /** @var array<string, array{name:string,is_system:bool,description:string,credit_limit_minor:int,spend_threshold_minor:int,tier_rank:int,group_version:int}> */
    private array $meta = [];

    /** @var (\Closure(): CustomerGroupRecord)|null */
    private readonly ?\Closure $groupFactory;

    /** @var (\Closure(): CustomerGroupMembershipRecord)|null */
    private readonly ?\Closure $membershipFactory;

    /**
     * @param (callable(): CustomerGroupRecord)|null $groupFactory
     * @param (callable(): CustomerGroupMembershipRecord)|null $membershipFactory
     */
    public function __construct(
        ?callable $groupFactory = null,
        ?callable $membershipFactory = null,
        bool $useMemory = false,
    ) {
        $this->groupFactory = $groupFactory !== null ? \Closure::fromCallable($groupFactory) : null;
        $this->membershipFactory = $membershipFactory !== null
            ? \Closure::fromCallable($membershipFactory)
            : null;
        if ($useMemory) {
            $this->rows = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    public function isMemory(): bool
    {
        return $this->rows !== null;
    }

    public function put(CustomerGroup $group): void
    {
        if ($this->rows !== null) {
            foreach ($this->rows as $existing) {
                if ($existing->websiteId === $group->websiteId
                    && $existing->code === $group->code
                    && $existing->groupId !== $group->groupId
                ) {
                    throw $this->codeTaken($group);
                }
            }
            $this->rows[$group->groupId] = $group;
            $existingMeta = $this->meta[$group->groupId] ?? null;
            $this->meta[$group->groupId] = $this->normalizeMeta($group->groupId, $group->code, [
                'name' => $existingMeta['name'] ?? $group->code,
                'is_system' => (bool)($existingMeta['is_system'] ?? $group->isSystemVip()),
                'description' => (string)($existingMeta['description'] ?? ''),
                'credit_limit_minor' => (int)($existingMeta['credit_limit_minor'] ?? 0),
                'spend_threshold_minor' => (int)($existingMeta['spend_threshold_minor'] ?? 0),
                'tier_rank' => (int)($existingMeta['tier_rank'] ?? -1),
                'group_version' => (int)($existingMeta['group_version'] ?? 1),
            ]);
            return;
        }

        $existing = $this->findGroupModel($group->groupId);
        if ($existing !== null) {
            if ((int)$existing->getData(CustomerGroupRecord::schema_fields_WEBSITE_ID) !== $group->websiteId
                || (string)$existing->getData(CustomerGroupRecord::schema_fields_CODE) !== $group->code
            ) {
                throw new B2BConflictException(
                    'b2b_group_identity_immutable',
                    __('B2B group identity 不可变：%{1}', [$group->groupId]),
                    ['group_id' => $group->groupId],
                );
            }
            if ((string)$existing->getData(CustomerGroupRecord::schema_fields_STATUS) === $group->status) {
                return;
            }
            $existing
                ->setData(CustomerGroupRecord::schema_fields_STATUS, $group->status)
                ->setData(
                    CustomerGroupRecord::schema_fields_VERSION,
                    (int)$existing->getData(CustomerGroupRecord::schema_fields_VERSION) + 1,
                )
                ->setData(CustomerGroupRecord::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'))
                ->save();
            return;
        }

        try {
            $now = gmdate('Y-m-d H:i:s');
            $isSystem = $group->isSystemVip() ? 1 : 0;
            $tier = SystemVipLadder::tierFromGroupId($group->groupId) ?? -1;
            $this->newGroupRecord()->clear()->setData([
                CustomerGroupRecord::schema_fields_GROUP_ID => $group->groupId,
                CustomerGroupRecord::schema_fields_WEBSITE_ID => $group->websiteId,
                CustomerGroupRecord::schema_fields_CODE => $group->code,
                CustomerGroupRecord::schema_fields_NAME => $group->code,
                CustomerGroupRecord::schema_fields_IS_SYSTEM => $isSystem,
                CustomerGroupRecord::schema_fields_DESCRIPTION => '',
                CustomerGroupRecord::schema_fields_CREDIT_LIMIT_MINOR => 0,
                CustomerGroupRecord::schema_fields_SPEND_THRESHOLD_MINOR => 0,
                CustomerGroupRecord::schema_fields_TIER_RANK => $tier,
                CustomerGroupRecord::schema_fields_STATUS => $group->status,
                CustomerGroupRecord::schema_fields_VERSION => 1,
                CustomerGroupRecord::schema_fields_CREATED_AT => $now,
                CustomerGroupRecord::schema_fields_UPDATED_AT => $now,
            ])->save();
        } catch (Throwable $exception) {
            $byCode = $this->findByCode($group->websiteId, $group->code);
            if ($byCode !== null && $byCode->groupId !== $group->groupId) {
                throw $this->codeTaken($group, $exception);
            }
            throw $exception;
        }
    }

    public function get(string $groupId): ?CustomerGroup
    {
        $groupId = trim($groupId);
        if ($groupId === '') {
            return null;
        }
        if ($this->rows !== null) {
            return $this->rows[$groupId] ?? null;
        }
        $model = $this->findGroupModel($groupId);
        return $model !== null ? $this->hydrate($model->getData()) : null;
    }

    public function assignCustomer(string $customerId, string $groupId): void
    {
        $customerId = trim($customerId);
        if ($customerId === '' || strlen($customerId) > 64) {
            throw new \InvalidArgumentException(__('B2B customer_id 必填且不能超过 64 字符'));
        }
        $group = $this->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }

        if ($this->rows !== null) {
            $this->membership[$this->membershipKey($customerId, $group->websiteId)] = $group->groupId;
            return;
        }

        $existing = $this->findMembershipModel($customerId, $group->websiteId);
        if ($existing !== null) {
            if ((string)$existing->getData(CustomerGroupMembershipRecord::schema_fields_GROUP_ID)
                === $group->groupId
            ) {
                return;
            }
            $existing
                ->setData(CustomerGroupMembershipRecord::schema_fields_GROUP_ID, $group->groupId)
                ->setData(
                    CustomerGroupMembershipRecord::schema_fields_VERSION,
                    (int)$existing->getData(CustomerGroupMembershipRecord::schema_fields_VERSION) + 1,
                )
                ->setData(CustomerGroupMembershipRecord::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'))
                ->save();
            return;
        }

        $this->newMembershipRecord()->clear()->setData([
            CustomerGroupMembershipRecord::schema_fields_CUSTOMER_ID => $customerId,
            CustomerGroupMembershipRecord::schema_fields_WEBSITE_ID => $group->websiteId,
            CustomerGroupMembershipRecord::schema_fields_GROUP_ID => $group->groupId,
            CustomerGroupMembershipRecord::schema_fields_VERSION => 1,
            CustomerGroupMembershipRecord::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s'),
        ])->save();
    }

    public function groupForCustomer(string $customerId, int $websiteId): ?CustomerGroup
    {
        $customerId = trim($customerId);
        if ($customerId === '' || $websiteId < 0) {
            return null;
        }
        if ($this->rows !== null) {
            $groupId = $this->membership[$this->membershipKey($customerId, $websiteId)] ?? null;
        } else {
            $membership = $this->findMembershipModel($customerId, $websiteId);
            $groupId = $membership !== null
                ? (string)$membership->getData(CustomerGroupMembershipRecord::schema_fields_GROUP_ID)
                : null;
        }
        if ($groupId === null) {
            return null;
        }
        $group = $this->get($groupId);
        return $group !== null && $group->websiteId === $websiteId ? $group : null;
    }

    /**
     * Revoke storefront entitlement for (customer, website). Does not rewrite application rows.
     */
    public function unassignCustomer(string $customerId, int $websiteId): bool
    {
        $customerId = trim($customerId);
        if ($customerId === '' || strlen($customerId) > 64 || $websiteId < 0) {
            throw new \InvalidArgumentException(__('B2B unassign 参数非法'));
        }

        if ($this->rows !== null) {
            $key = $this->membershipKey($customerId, $websiteId);
            if (!array_key_exists($key, $this->membership)) {
                return false;
            }
            unset($this->membership[$key]);
            return true;
        }

        $existing = $this->findMembershipModel($customerId, $websiteId);
        if ($existing === null) {
            return false;
        }
        $existing->delete();
        return true;
    }

    public function countGroups(): int
    {
        if ($this->rows !== null) {
            return count($this->rows);
        }
        return count($this->newGroupRecord()->clear()->select()->fetchArray());
    }

    /**
     * Active groups for admin approve dropdowns.
     *
     * @return list<array{value:string,label:string,website_id:int,code:string}>
     */
    public function listActiveOptions(): array
    {
        /** @var list<CustomerGroup> $groups */
        $groups = [];
        if ($this->rows !== null) {
            foreach ($this->rows as $group) {
                if ($group->isActive()) {
                    $groups[] = $group;
                }
            }
        } else {
            $rows = $this->newGroupRecord()->clear()
                ->where(CustomerGroupRecord::schema_fields_STATUS, CustomerGroup::STATUS_ACTIVE)
                ->order(CustomerGroupRecord::schema_fields_CODE, 'ASC')
                ->select()
                ->fetchArray();
            foreach ($rows as $row) {
                if (\is_array($row)) {
                    $groups[] = $this->hydrate($row);
                }
            }
        }

        usort(
            $groups,
            function (CustomerGroup $a, CustomerGroup $b): int {
                $ta = $this->tierRankOf($a->groupId);
                $tb = $this->tierRankOf($b->groupId);
                $ladderA = $ta >= 0 ? 0 : 1;
                $ladderB = $tb >= 0 ? 0 : 1;
                return [$ladderA, $ta, $a->websiteId, $a->code, $a->groupId]
                    <=> [$ladderB, $tb, $b->websiteId, $b->code, $b->groupId];
            },
        );

        $options = [];
        foreach ($groups as $group) {
            $name = $this->displayNameOf($group->groupId, $group->code);
            $label = $name === $group->code ? $name : ($name . ' · ' . $group->code);
            $options[] = [
                'value' => $group->groupId,
                'label' => $label,
                'website_id' => $group->websiteId,
                'code' => $group->code,
                'name' => $name,
                'tier_rank' => $this->tierRankOf($group->groupId),
            ];
        }

        return $options;
    }

    /**
     * Ensure vip0…vip12 ladder exists; migrate legacy g-system-vip membership to vip0.
     *
     * @return CustomerGroup vip0 entry group
     */
    public function ensureSystemVipLadder(int $websiteId = 0): CustomerGroup
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('B2B website_id 不能为负数：%{1}', [$websiteId]));
        }

        $entry = null;
        foreach (SystemVipLadder::seedDefinitions() as $seed) {
            $group = $this->get($seed['group_id']);
            $created = false;
            if ($group === null) {
                $group = new CustomerGroup(
                    $seed['group_id'],
                    $websiteId,
                    $seed['code'],
                    CustomerGroup::STATUS_ACTIVE,
                );
                $this->put($group);
                $created = true;
            } elseif ($group->websiteId !== $websiteId) {
                throw new B2BConflictException(
                    'b2b_system_vip_website_mismatch',
                    __('系统 VIP 客户组已绑定其它站点'),
                    ['group_id' => $seed['group_id'], 'website_id' => $group->websiteId],
                );
            }
            $this->markSystem($group->groupId, true);
            if ($created) {
                $this->applySeedMeta($group->groupId, $seed, forceAmounts: true);
            } else {
                $this->applySeedMeta($group->groupId, $seed, forceAmounts: false);
            }
            if ((int)$seed['tier_rank'] === 0) {
                $entry = $group;
            }
        }

        $this->migrateLegacySystemVip($websiteId);

        if ($entry === null) {
            throw new \RuntimeException('b2b_vip0_missing');
        }

        return $entry;
    }

    /**
     * @deprecated use ensureSystemVipLadder
     */
    public function ensureSystemVip(int $websiteId = 0): CustomerGroup
    {
        return $this->ensureSystemVipLadder($websiteId);
    }

    /**
     * @param array{
     *   tier_rank:int,
     *   group_id:string,
     *   code:string,
     *   name:string,
     *   description:string,
     *   credit_limit_minor:int,
     *   spend_threshold_minor:int
     * } $seed
     */
    private function applySeedMeta(string $groupId, array $seed, bool $forceAmounts): void
    {
        if ($this->rows !== null) {
            $meta = $this->normalizeMeta($groupId, $seed['code'], $this->meta[$groupId] ?? []);
            if (trim((string)$meta['name']) === '' || $meta['name'] === $seed['code']) {
                $meta['name'] = $seed['name'];
            }
            if (trim((string)$meta['description']) === '') {
                $meta['description'] = $seed['description'];
            }
            if ((int)$meta['tier_rank'] < 0) {
                $meta['tier_rank'] = (int)$seed['tier_rank'];
            }
            $tier = (int)$seed['tier_rank'];
            $shouldForce = $forceAmounts
                || SystemVipLadder::isLegacyLinearAmounts(
                    $tier,
                    (int)$meta['credit_limit_minor'],
                    (int)$meta['spend_threshold_minor'],
                );
            if ($shouldForce) {
                $meta['credit_limit_minor'] = (int)$seed['credit_limit_minor'];
                $meta['spend_threshold_minor'] = (int)$seed['spend_threshold_minor'];
            }
            $meta['is_system'] = true;
            $this->meta[$groupId] = $this->normalizeMeta($groupId, $seed['code'], $meta);
            return;
        }

        $model = $this->findGroupModel($groupId);
        if ($model === null) {
            return;
        }
        $changed = false;
        $name = trim((string)$model->getData(CustomerGroupRecord::schema_fields_NAME));
        if ($name === '' || $name === $seed['code']) {
            $model->setData(CustomerGroupRecord::schema_fields_NAME, $seed['name']);
            $changed = true;
        }
        $desc = trim((string)$model->getData(CustomerGroupRecord::schema_fields_DESCRIPTION));
        if ($desc === '') {
            $model->setData(CustomerGroupRecord::schema_fields_DESCRIPTION, $seed['description']);
            $changed = true;
        }
        if ((int)$model->getData(CustomerGroupRecord::schema_fields_TIER_RANK) < 0) {
            $model->setData(CustomerGroupRecord::schema_fields_TIER_RANK, (int)$seed['tier_rank']);
            $changed = true;
        }
        $tier = (int)$seed['tier_rank'];
        $currentCredit = (int)$model->getData(CustomerGroupRecord::schema_fields_CREDIT_LIMIT_MINOR);
        $currentSpend = (int)$model->getData(CustomerGroupRecord::schema_fields_SPEND_THRESHOLD_MINOR);
        $shouldForce = $forceAmounts
            || SystemVipLadder::isLegacyLinearAmounts($tier, $currentCredit, $currentSpend);
        if ($shouldForce) {
            $model->setData(CustomerGroupRecord::schema_fields_CREDIT_LIMIT_MINOR, (int)$seed['credit_limit_minor']);
            $model->setData(
                CustomerGroupRecord::schema_fields_SPEND_THRESHOLD_MINOR,
                (int)$seed['spend_threshold_minor'],
            );
            $changed = true;
        }
        if ((int)$model->getData(CustomerGroupRecord::schema_fields_IS_SYSTEM) !== 1) {
            $model->setData(CustomerGroupRecord::schema_fields_IS_SYSTEM, 1);
            $changed = true;
        }
        if ($changed) {
            $model
                ->setData(CustomerGroupRecord::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'))
                ->save();
        }
    }

    /**
     * @return list<array{customer_id:string,website_id:int,group_id:string}>
     */
    public function listSystemVipMemberships(int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        $out = [];
        if ($this->rows !== null) {
            foreach ($this->membership as $key => $groupId) {
                if (!SystemVipLadder::isSystemVipGroupId((string)$groupId)
                    || (string)$groupId === SystemVipLadder::LEGACY_GROUP_ID
                ) {
                    continue;
                }
                [$websiteId, $customerId] = explode(':', $key, 2);
                $out[] = [
                    'customer_id' => (string)$customerId,
                    'website_id' => (int)$websiteId,
                    'group_id' => (string)$groupId,
                ];
                if (count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        }

        $rows = $this->newMembershipRecord()->clear()
            ->limit($limit)
            ->select()
            ->fetchArray();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $groupId = (string)($row[CustomerGroupMembershipRecord::schema_fields_GROUP_ID] ?? '');
            if (!SystemVipLadder::isSystemVipGroupId($groupId)
                || $groupId === SystemVipLadder::LEGACY_GROUP_ID
            ) {
                continue;
            }
            $out[] = [
                'customer_id' => (string)($row[CustomerGroupMembershipRecord::schema_fields_CUSTOMER_ID] ?? ''),
                'website_id' => (int)($row[CustomerGroupMembershipRecord::schema_fields_WEBSITE_ID] ?? -1),
                'group_id' => $groupId,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{customer_id:string,website_id:int,group_id:string,updated_at:string}>
     */
    public function listMembersByGroup(string $groupId, string $query = '', int $limit = 50): array
    {
        $groupId = trim($groupId);
        $query = trim($query);
        $limit = max(1, min(200, $limit));
        if ($groupId === '') {
            return [];
        }
        if ($this->get($groupId) === null) {
            return [];
        }

        $out = [];
        if ($this->rows !== null) {
            foreach ($this->membership as $key => $assignedGroupId) {
                if ((string)$assignedGroupId !== $groupId) {
                    continue;
                }
                [$websiteId, $customerId] = explode(':', $key, 2);
                $customerId = (string)$customerId;
                if ($query !== '' && !str_contains(strtolower($customerId), strtolower($query))) {
                    continue;
                }
                $out[] = [
                    'customer_id' => $customerId,
                    'website_id' => (int)$websiteId,
                    'group_id' => $groupId,
                    'updated_at' => '',
                ];
                if (count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        }

        $model = $this->newMembershipRecord()->clear()
            ->where(CustomerGroupMembershipRecord::schema_fields_GROUP_ID, $groupId);
        if ($query !== '') {
            $model->where(CustomerGroupMembershipRecord::schema_fields_CUSTOMER_ID, '%' . $query . '%', 'like');
        }
        $rows = $model->limit($limit)->select()->fetchArray();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'customer_id' => (string)($row[CustomerGroupMembershipRecord::schema_fields_CUSTOMER_ID] ?? ''),
                'website_id' => (int)($row[CustomerGroupMembershipRecord::schema_fields_WEBSITE_ID] ?? 0),
                'group_id' => (string)($row[CustomerGroupMembershipRecord::schema_fields_GROUP_ID] ?? $groupId),
                'updated_at' => (string)($row[CustomerGroupMembershipRecord::schema_fields_UPDATED_AT] ?? ''),
            ];
        }
        return $out;
    }

    private function migrateLegacySystemVip(int $websiteId): void
    {
        $legacyId = SystemVipLadder::LEGACY_GROUP_ID;
        $vip0 = SystemVipLadder::groupId(0);
        if ($this->rows !== null) {
            foreach ($this->membership as $key => $assigned) {
                if ($assigned === $legacyId) {
                    $this->membership[$key] = $vip0;
                }
            }
            if (isset($this->rows[$legacyId])) {
                unset($this->rows[$legacyId], $this->meta[$legacyId]);
            }
            return;
        }

        $rows = $this->newMembershipRecord()->clear()
            ->where(CustomerGroupMembershipRecord::schema_fields_GROUP_ID, $legacyId)
            ->select()
            ->fetchArray();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $customerId = (string)($row[CustomerGroupMembershipRecord::schema_fields_CUSTOMER_ID] ?? '');
            $site = (int)($row[CustomerGroupMembershipRecord::schema_fields_WEBSITE_ID] ?? -1);
            if ($customerId === '' || $site !== $websiteId) {
                continue;
            }
            try {
                $this->assignCustomer($customerId, $vip0);
            } catch (\Throwable) {
            }
        }

        $legacy = $this->findGroupModel($legacyId);
        if ($legacy !== null) {
            $legacy
                ->setData(CustomerGroupRecord::schema_fields_STATUS, CustomerGroup::STATUS_DISABLED)
                ->setData(CustomerGroupRecord::schema_fields_IS_SYSTEM, 0)
                ->setData(CustomerGroupRecord::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'))
                ->save();
        }
    }

    /**
     * @return array{
     *   credit_limit_minor:int,
     *   spend_threshold_minor:int,
     *   tier_rank:int,
     *   group_version:int,
     *   description:string,
     *   name:string,
     *   status:string
     * }|null
     */
    public function groupCreditMeta(string $groupId): ?array
    {
        $groupId = trim($groupId);
        $group = $this->get($groupId);
        if ($group === null) {
            return null;
        }
        if ($this->rows !== null) {
            $meta = $this->normalizeMeta($groupId, $group->code, $this->meta[$groupId] ?? []);
            return [
                'credit_limit_minor' => (int)$meta['credit_limit_minor'],
                'spend_threshold_minor' => (int)$meta['spend_threshold_minor'],
                'tier_rank' => (int)$meta['tier_rank'],
                'group_version' => (int)$meta['group_version'],
                'description' => (string)$meta['description'],
                'name' => (string)$meta['name'],
                'status' => $group->status,
            ];
        }
        $model = $this->findGroupModel($groupId);
        if ($model === null) {
            return null;
        }

        return [
            'credit_limit_minor' => (int)$model->getData(CustomerGroupRecord::schema_fields_CREDIT_LIMIT_MINOR),
            'spend_threshold_minor' => (int)$model->getData(CustomerGroupRecord::schema_fields_SPEND_THRESHOLD_MINOR),
            'tier_rank' => (int)$model->getData(CustomerGroupRecord::schema_fields_TIER_RANK),
            'group_version' => (int)$model->getData(CustomerGroupRecord::schema_fields_VERSION),
            'description' => (string)$model->getData(CustomerGroupRecord::schema_fields_DESCRIPTION),
            'name' => (string)$model->getData(CustomerGroupRecord::schema_fields_NAME),
            'status' => (string)$model->getData(CustomerGroupRecord::schema_fields_STATUS),
        ];
    }

    /**
     * @param array{description?:string,credit_limit_minor?:int,spend_threshold_minor?:int,status?:string,tier_rank?:int} $input
     */
    public function updateGroupCapabilities(string $groupId, array $input): void
    {
        $groupId = trim($groupId);
        $group = $this->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }

        if ($this->rows !== null) {
            $meta = $this->normalizeMeta($groupId, $group->code, $this->meta[$groupId] ?? []);
            if (array_key_exists('description', $input)) {
                $meta['description'] = trim((string)$input['description']);
            }
            if (array_key_exists('credit_limit_minor', $input)) {
                $meta['credit_limit_minor'] = max(0, (int)$input['credit_limit_minor']);
            }
            if (array_key_exists('spend_threshold_minor', $input)) {
                $meta['spend_threshold_minor'] = max(0, (int)$input['spend_threshold_minor']);
            }
            if (array_key_exists('tier_rank', $input)) {
                $meta['tier_rank'] = max(-1, (int)$input['tier_rank']);
            }
            $meta['group_version'] = (int)$meta['group_version'] + 1;
            $this->meta[$groupId] = $meta;
            if (isset($input['status'])) {
                $status = (string)$input['status'];
                $this->rows[$groupId] = new CustomerGroup(
                    $group->groupId,
                    $group->websiteId,
                    $group->code,
                    $status,
                );
            }
            return;
        }

        $model = $this->findGroupModel($groupId);
        if ($model === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        if (array_key_exists('description', $input)) {
            $model->setData(CustomerGroupRecord::schema_fields_DESCRIPTION, trim((string)$input['description']));
        }
        if (array_key_exists('credit_limit_minor', $input)) {
            $model->setData(
                CustomerGroupRecord::schema_fields_CREDIT_LIMIT_MINOR,
                max(0, (int)$input['credit_limit_minor']),
            );
        }
        if (array_key_exists('spend_threshold_minor', $input)) {
            $model->setData(
                CustomerGroupRecord::schema_fields_SPEND_THRESHOLD_MINOR,
                max(0, (int)$input['spend_threshold_minor']),
            );
        }
        if (array_key_exists('tier_rank', $input)) {
            $model->setData(CustomerGroupRecord::schema_fields_TIER_RANK, max(-1, (int)$input['tier_rank']));
        }
        if (isset($input['status'])) {
            $model->setData(CustomerGroupRecord::schema_fields_STATUS, (string)$input['status']);
        }
        $model
            ->setData(
                CustomerGroupRecord::schema_fields_VERSION,
                (int)$model->getData(CustomerGroupRecord::schema_fields_VERSION) + 1,
            )
            ->setData(CustomerGroupRecord::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'))
            ->save();
    }

    /**
     * Rewrite display sort ranks for the given ordered group ids (0..n-1).
     *
     * @param list<string> $orderedGroupIds
     * @return list<array{group_id:string,tier_rank:int}>
     */
    public function reorderGroups(array $orderedGroupIds): array
    {
        $out = [];
        $rank = 0;
        foreach ($orderedGroupIds as $groupId) {
            $groupId = trim((string)$groupId);
            if ($groupId === '' || $this->get($groupId) === null) {
                continue;
            }
            $this->updateGroupCapabilities($groupId, ['tier_rank' => $rank]);
            $out[] = ['group_id' => $groupId, 'tier_rank' => $rank];
            $rank++;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{name:string,is_system:bool,description:string,credit_limit_minor:int,spend_threshold_minor:int,tier_rank:int,group_version:int}
     */
    private function normalizeMeta(string $groupId, string $fallbackCode, array $meta): array
    {
        return [
            'name' => trim((string)($meta['name'] ?? '')) !== ''
                ? trim((string)$meta['name'])
                : $fallbackCode,
            'is_system' => (bool)($meta['is_system'] ?? SystemVipLadder::isSystemVipGroupId($groupId)),
            'description' => (string)($meta['description'] ?? ''),
            'credit_limit_minor' => max(0, (int)($meta['credit_limit_minor'] ?? 0)),
            'spend_threshold_minor' => max(0, (int)($meta['spend_threshold_minor'] ?? 0)),
            'tier_rank' => (int)($meta['tier_rank'] ?? (SystemVipLadder::tierFromGroupId($groupId) ?? -1)),
            'group_version' => max(1, (int)($meta['group_version'] ?? 1)),
        ];
    }

    public function updateDisplayName(string $groupId, string $name): void
    {
        $groupId = trim($groupId);
        $name = trim($name);
        if ($groupId === '' || $name === '' || strlen($name) > 128) {
            throw new \InvalidArgumentException(__('客户组名称非法'));
        }
        $group = $this->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }

        if ($this->rows !== null) {
            $meta = $this->normalizeMeta($groupId, $group->code, $this->meta[$groupId] ?? []);
            $meta['name'] = $name;
            $this->meta[$groupId] = $meta;
            return;
        }

        $model = $this->findGroupModel($groupId);
        if ($model === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        $model
            ->setData(CustomerGroupRecord::schema_fields_NAME, $name)
            ->setData(
                CustomerGroupRecord::schema_fields_VERSION,
                (int)$model->getData(CustomerGroupRecord::schema_fields_VERSION) + 1,
            )
            ->setData(CustomerGroupRecord::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'))
            ->save();
    }

    public function deleteGroup(string $groupId): void
    {
        $groupId = trim($groupId);
        if ($groupId === '') {
            throw new \InvalidArgumentException(__('B2B group_id 必填'));
        }
        if ($this->isSystemGroup($groupId)) {
            throw new B2BConflictException(
                'b2b_system_group_protected',
                __('系统客户组不可删除'),
                ['group_id' => $groupId],
            );
        }
        $group = $this->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }

        if ($this->rows !== null) {
            unset($this->rows[$groupId], $this->meta[$groupId]);
            foreach ($this->membership as $key => $assigned) {
                if ($assigned === $groupId) {
                    unset($this->membership[$key]);
                }
            }
            return;
        }

        $model = $this->findGroupModel($groupId);
        if ($model === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                __('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        $model->delete();
    }

    public function isSystemGroup(string $groupId): bool
    {
        $groupId = trim($groupId);
        if (SystemVipLadder::isSystemVipGroupId($groupId)) {
            return true;
        }
        if ($this->rows !== null) {
            return (bool)($this->meta[$groupId]['is_system'] ?? false);
        }
        $model = $this->findGroupModel($groupId);
        if ($model === null) {
            return false;
        }
        return (int)$model->getData(CustomerGroupRecord::schema_fields_IS_SYSTEM) === 1;
    }

    /**
     * @return list<array{
     *   group_row_id:int,
     *   group_id:string,
     *   website_id:int,
     *   code:string,
     *   name:string,
     *   status:string,
     *   is_system:bool,
     *   description:string,
     *   credit_limit_minor:int,
     *   spend_threshold_minor:int,
     *   tier_rank:int
     * }>
     */
    public function listAdminRows(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $localSeed = null;
        try {
            $localSeed = ObjectManager::getInstance(CustomerGroupLocalSeedService::class);
        } catch (\Throwable) {
            $localSeed = null;
        }
        if ($this->rows !== null) {
            $out = [];
            foreach ($this->rows as $group) {
                $meta = $this->normalizeMeta($group->groupId, $group->code, $this->meta[$group->groupId] ?? []);
                $name = $this->displayNameOf($group->groupId, $group->code);
                $description = (string)$meta['description'];
                $out[] = [
                    'group_row_id' => 0,
                    'group_id' => $group->groupId,
                    'website_id' => $group->websiteId,
                    'code' => $group->code,
                    'name' => $name,
                    'status' => $group->status,
                    'is_system' => $this->isSystemGroup($group->groupId),
                    'description' => $description,
                    'credit_limit_minor' => (int)$meta['credit_limit_minor'],
                    'spend_threshold_minor' => (int)$meta['spend_threshold_minor'],
                    'tier_rank' => (int)$meta['tier_rank'],
                ];
            }
            usort(
                $out,
                static function (array $a, array $b): int {
                    $rankA = (int)$a['tier_rank'];
                    $rankB = (int)$b['tier_rank'];
                    $normA = $rankA < 0 ? PHP_INT_MAX : $rankA;
                    $normB = $rankB < 0 ? PHP_INT_MAX : $rankB;

                    return [$normA, (string)$a['name']] <=> [$normB, (string)$b['name']];
                },
            );
            return array_slice($out, 0, $limit);
        }

        $rows = $this->newGroupRecord()->clear()
            ->order(CustomerGroupRecord::schema_fields_TIER_RANK, 'ASC')
            ->order(CustomerGroupRecord::schema_fields_NAME, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $groupId = (string)($row[CustomerGroupRecord::schema_fields_GROUP_ID] ?? '');
            $code = (string)($row[CustomerGroupRecord::schema_fields_CODE] ?? '');
            $rowId = (int)($row[CustomerGroupRecord::schema_fields_ID] ?? 0);
            $name = trim((string)($row[CustomerGroupRecord::schema_fields_NAME] ?? ''));
            $name = $name !== '' ? $name : $code;
            $description = (string)($row[CustomerGroupRecord::schema_fields_DESCRIPTION] ?? '');
            if ($localSeed instanceof CustomerGroupLocalSeedService && $rowId > 0) {
                $name = $localSeed->localizedName($rowId, $name);
                $description = $localSeed->localizedDescription($rowId, $description);
            }
            $out[] = [
                'group_row_id' => $rowId,
                'group_id' => $groupId,
                'website_id' => (int)($row[CustomerGroupRecord::schema_fields_WEBSITE_ID] ?? 0),
                'code' => $code,
                'name' => $name,
                'status' => (string)($row[CustomerGroupRecord::schema_fields_STATUS] ?? ''),
                'is_system' => (int)($row[CustomerGroupRecord::schema_fields_IS_SYSTEM] ?? 0) === 1
                    || SystemVipLadder::isSystemVipGroupId($groupId),
                'description' => $description,
                'credit_limit_minor' => (int)($row[CustomerGroupRecord::schema_fields_CREDIT_LIMIT_MINOR] ?? 0),
                'spend_threshold_minor' => (int)($row[CustomerGroupRecord::schema_fields_SPEND_THRESHOLD_MINOR] ?? 0),
                'tier_rank' => (int)($row[CustomerGroupRecord::schema_fields_TIER_RANK] ?? -1),
            ];
        }
        usort(
            $out,
            static function (array $a, array $b): int {
                $rankA = (int)$a['tier_rank'];
                $rankB = (int)$b['tier_rank'];
                $normA = $rankA < 0 ? PHP_INT_MAX : $rankA;
                $normB = $rankB < 0 ? PHP_INT_MAX : $rankB;

                return [$normA, (string)$a['name']] <=> [$normB, (string)$b['name']];
            },
        );
        return array_slice($out, 0, $limit);
    }

    private function tierRankOf(string $groupId): int
    {
        if ($this->rows !== null) {
            return (int)($this->meta[$groupId]['tier_rank'] ?? (SystemVipLadder::tierFromGroupId($groupId) ?? -1));
        }
        $model = $this->findGroupModel($groupId);
        if ($model === null) {
            return SystemVipLadder::tierFromGroupId($groupId) ?? -1;
        }
        $rank = (int)$model->getData(CustomerGroupRecord::schema_fields_TIER_RANK);
        if ($rank >= 0) {
            return $rank;
        }

        return SystemVipLadder::tierFromGroupId($groupId) ?? -1;
    }

    private function markSystem(string $groupId, bool $isSystem): void
    {
        if ($this->rows !== null) {
            $fallback = $this->rows[$groupId]->code ?? $groupId;
            $meta = $this->normalizeMeta($groupId, (string)$fallback, $this->meta[$groupId] ?? []);
            $meta['is_system'] = $isSystem;
            $this->meta[$groupId] = $meta;
            return;
        }
        $model = $this->findGroupModel($groupId);
        if ($model === null) {
            return;
        }
        if ((int)$model->getData(CustomerGroupRecord::schema_fields_IS_SYSTEM) === ($isSystem ? 1 : 0)) {
            return;
        }
        $model
            ->setData(CustomerGroupRecord::schema_fields_IS_SYSTEM, $isSystem ? 1 : 0)
            ->setData(CustomerGroupRecord::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'))
            ->save();
    }

    private function displayNameOf(string $groupId, string $fallbackCode): string
    {
        if ($this->rows !== null) {
            $name = trim((string)($this->meta[$groupId]['name'] ?? ''));
            return $name !== '' ? $name : $fallbackCode;
        }
        $model = $this->findGroupModel($groupId);
        if ($model === null) {
            return $fallbackCode;
        }
        $name = trim((string)$model->getData(CustomerGroupRecord::schema_fields_NAME));
        return $name !== '' ? $name : $fallbackCode;
    }

    private function findGroupModel(string $groupId): ?CustomerGroupRecord
    {
        $model = $this->newGroupRecord();
        $model->clear()
            ->where(CustomerGroupRecord::schema_fields_GROUP_ID, trim($groupId))
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    private function findMembershipModel(
        string $customerId,
        int $websiteId,
    ): ?CustomerGroupMembershipRecord {
        $model = $this->newMembershipRecord();
        $model->clear()
            ->where(CustomerGroupMembershipRecord::schema_fields_CUSTOMER_ID, trim($customerId))
            ->where(CustomerGroupMembershipRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    private function findByCode(int $websiteId, string $code): ?CustomerGroup
    {
        $model = $this->newGroupRecord();
        $model->clear()
            ->where(CustomerGroupRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->where(CustomerGroupRecord::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        return $model->getId() ? $this->hydrate($model->getData()) : null;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): CustomerGroup
    {
        return new CustomerGroup(
            (string)$row[CustomerGroupRecord::schema_fields_GROUP_ID],
            (int)$row[CustomerGroupRecord::schema_fields_WEBSITE_ID],
            (string)$row[CustomerGroupRecord::schema_fields_CODE],
            (string)$row[CustomerGroupRecord::schema_fields_STATUS],
        );
    }

    private function codeTaken(
        CustomerGroup $group,
        ?Throwable $previous = null,
    ): B2BConflictException {
        return new B2BConflictException(
            'b2b_group_code_taken',
            __('同一 Website 的 B2B group code 已占用：%{1}', [$group->code]),
            ['website_id' => $group->websiteId, 'code' => $group->code],
            0,
            $previous,
        );
    }

    private function membershipKey(string $customerId, int $websiteId): string
    {
        return $websiteId . ':' . $customerId;
    }

    private function newGroupRecord(): CustomerGroupRecord
    {
        return $this->groupFactory !== null
            ? ($this->groupFactory)()
            : ObjectManager::create(CustomerGroupRecord::class, [], false);
    }

    private function newMembershipRecord(): CustomerGroupMembershipRecord
    {
        return $this->membershipFactory !== null
            ? ($this->membershipFactory)()
            : ObjectManager::create(CustomerGroupMembershipRecord::class, [], false);
    }
}
