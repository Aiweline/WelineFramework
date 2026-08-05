<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Throwable;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\CustomerGroupMembershipRecord;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\Framework\Manager\ObjectManager;

/** Durable group/membership store with an explicit memory-only test seam. */
final class CustomerGroupStore
{
    /** @var array<string, CustomerGroup>|null */
    private ?array $rows = null;

    /** @var array<string, string> */
    private array $membership = [];

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
            $this->newGroupRecord()->clear()->setData([
                CustomerGroupRecord::schema_fields_GROUP_ID => $group->groupId,
                CustomerGroupRecord::schema_fields_WEBSITE_ID => $group->websiteId,
                CustomerGroupRecord::schema_fields_CODE => $group->code,
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

    public function countGroups(): int
    {
        if ($this->rows !== null) {
            return count($this->rows);
        }
        return count($this->newGroupRecord()->clear()->select()->fetchArray());
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
