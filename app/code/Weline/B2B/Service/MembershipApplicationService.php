<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\MembershipApplicationRecord;
use Weline\Framework\Manager\ObjectManager;

/**
 * ToB identity applications. Clients must not supply group_id; admin assigns on approve.
 */
final class MembershipApplicationService
{
    public const ERROR_CLIENT_GROUP_FORBIDDEN = 'b2b_membership_client_group_forbidden';
    public const ERROR_NOT_FOUND = 'b2b_membership_application_not_found';
    public const ERROR_NOT_PENDING = 'b2b_membership_application_not_pending';
    public const ERROR_INVALID = 'b2b_membership_application_invalid';
    public const ERROR_ALREADY_PENDING = 'b2b_membership_already_pending';
    public const ERROR_ALREADY_MEMBER = 'b2b_membership_already_active';
    public const ERROR_NOT_REAUTHORIZABLE = 'b2b_membership_not_reauthorizable';

    /** @var list<array<string,mixed>>|null */
    private ?array $rows = null;

    /** @var (\Closure(): MembershipApplicationRecord)|null */
    private readonly ?\Closure $recordFactory;

    /**
     * @param (callable(): MembershipApplicationRecord)|null $recordFactory
     */
    public function __construct(
        private readonly CustomerGroupStore $groups,
        ?callable $recordFactory = null,
        bool $useMemory = false,
        private readonly ?B2BCreditGrantService $creditGrant = null,
    ) {
        $this->recordFactory = $recordFactory !== null ? \Closure::fromCallable($recordFactory) : null;
        if ($useMemory) {
            $this->rows = [];
        }
    }

    public static function forTesting(
        ?CustomerGroupStore $groups = null,
        ?B2BCreditGrantService $creditGrant = null,
    ): self {
        return new self(
            $groups ?? CustomerGroupStore::forTesting(),
            useMemory: true,
            creditGrant: $creditGrant,
        );
    }

    public function isMemory(): bool
    {
        return $this->rows !== null;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function submit(array $input): array
    {
        if (array_key_exists('group_id', $input)
            || array_key_exists('assigned_group_id', $input)
            || array_key_exists('customer_group_id', $input)
        ) {
            throw new B2BConflictException(
                self::ERROR_CLIENT_GROUP_FORBIDDEN,
                __('申请单不得由客户端指定客户组'),
                ['forbidden_keys' => ['group_id', 'assigned_group_id', 'customer_group_id']],
            );
        }

        $customerId = trim((string)($input['customer_id'] ?? ''));
        $websiteId = (int)($input['website_id'] ?? -1);
        $company = trim((string)($input['company_name'] ?? ''));
        $phone = trim((string)($input['contact_phone'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));

        if ($customerId === '' || strlen($customerId) > 64
            || $websiteId < 0
            || $company === '' || strlen($company) > 191
            || $phone === '' || strlen($phone) > 64
        ) {
            throw new B2BConflictException(
                self::ERROR_INVALID,
                __('B2B membership 申请参数非法'),
                [
                    'customer_id' => $customerId,
                    'website_id' => $websiteId,
                ],
            );
        }

        $existingGroup = $this->groups->groupForCustomer($customerId, $websiteId);
        if ($existingGroup !== null && $existingGroup->isActive()) {
            throw new B2BConflictException(
                self::ERROR_ALREADY_MEMBER,
                __('已开通批发身份，无需重复申请'),
                ['customer_id' => $customerId, 'website_id' => $websiteId, 'group_id' => $existingGroup->groupId],
            );
        }

        $pending = $this->findPendingForCustomer($customerId, $websiteId);
        if ($pending !== null) {
            throw new B2BConflictException(
                self::ERROR_ALREADY_PENDING,
                __('已有待审核的批发身份申请'),
                [
                    'customer_id' => $customerId,
                    'website_id' => $websiteId,
                    'application_id' => (string)$pending[MembershipApplicationRecord::schema_fields_APPLICATION_ID],
                ],
            );
        }

        // One application per customer+website: edit+resubmit updates the latest row.
        $latest = $this->findLatestRow($customerId, $websiteId);
        if ($latest !== null) {
            return $this->resubmitExisting($latest, $company, $phone, $notes);
        }

        $now = gmdate('Y-m-d H:i:s');
        $applicationId = trim((string)($input['application_id'] ?? ''));
        if ($applicationId === '') {
            $applicationId = 'app-' . substr(hash('sha256', $customerId . '|' . $websiteId . '|' . $now . '|' . uniqid('', true)), 0, 24);
        }
        if (strlen($applicationId) > 64) {
            throw new B2BConflictException(self::ERROR_INVALID, __('application_id 过长'));
        }

        $row = [
            MembershipApplicationRecord::schema_fields_APPLICATION_ID => $applicationId,
            MembershipApplicationRecord::schema_fields_CUSTOMER_ID => $customerId,
            MembershipApplicationRecord::schema_fields_WEBSITE_ID => $websiteId,
            MembershipApplicationRecord::schema_fields_COMPANY_NAME => $company,
            MembershipApplicationRecord::schema_fields_CONTACT_PHONE => $phone,
            MembershipApplicationRecord::schema_fields_STATUS => MembershipApplicationRecord::STATUS_PENDING,
            MembershipApplicationRecord::schema_fields_ASSIGNED_GROUP_ID => null,
            MembershipApplicationRecord::schema_fields_NOTES => $notes !== '' ? $notes : null,
            MembershipApplicationRecord::schema_fields_CREATED_AT => $now,
            MembershipApplicationRecord::schema_fields_UPDATED_AT => $now,
        ];

        if ($this->rows !== null) {
            foreach ($this->rows as $existing) {
                if ((string)$existing[MembershipApplicationRecord::schema_fields_APPLICATION_ID] === $applicationId) {
                    throw new B2BConflictException(
                        self::ERROR_INVALID,
                        __('application_id 已存在'),
                        ['application_id' => $applicationId],
                    );
                }
            }
            $this->rows[] = $row;
            return $this->publicRow($row);
        }

        $this->newRecord()->clear()->setData($row)->save();
        return $this->publicRow($row);
    }

    /** @return list<array<string,mixed>> */
    public function listPending(int $websiteId = -1, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        if ($this->rows !== null) {
            $out = [];
            foreach ($this->rows as $row) {
                if ((string)$row[MembershipApplicationRecord::schema_fields_STATUS]
                    !== MembershipApplicationRecord::STATUS_PENDING
                ) {
                    continue;
                }
                if ($websiteId >= 0
                    && (int)$row[MembershipApplicationRecord::schema_fields_WEBSITE_ID] !== $websiteId
                ) {
                    continue;
                }
                $out[] = $this->publicRow($row);
                if (count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        }

        $model = $this->newRecord()->clear()
            ->where(
                MembershipApplicationRecord::schema_fields_STATUS,
                MembershipApplicationRecord::STATUS_PENDING,
            );
        if ($websiteId >= 0) {
            $model->where(MembershipApplicationRecord::schema_fields_WEBSITE_ID, $websiteId);
        }
        $rows = $model->order(MembershipApplicationRecord::schema_fields_CREATED_AT, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $this->publicRow($row);
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function approve(string $applicationId, string $groupId): array
    {
        $applicationId = trim($applicationId);
        $groupId = trim($groupId);
        if ($applicationId === '' || $groupId === '') {
            throw new B2BConflictException(self::ERROR_INVALID, __('批准参数非法'));
        }

        $row = $this->requirePending($applicationId);
        $customerId = (string)$row[MembershipApplicationRecord::schema_fields_CUSTOMER_ID];
        $websiteId = (int)$row[MembershipApplicationRecord::schema_fields_WEBSITE_ID];
        $this->groups->assignCustomer($customerId, $groupId);

        $now = gmdate('Y-m-d H:i:s');
        $row[MembershipApplicationRecord::schema_fields_STATUS] = MembershipApplicationRecord::STATUS_APPROVED;
        $row[MembershipApplicationRecord::schema_fields_ASSIGNED_GROUP_ID] = $groupId;
        $row[MembershipApplicationRecord::schema_fields_UPDATED_AT] = $now;
        try {
            $this->persist($row);
        } catch (\Throwable $e) {
            // Compensate half-success: membership without approved application row.
            try {
                $this->groups->unassignCustomer($customerId, $websiteId);
            } catch (\Throwable) {
            }
            throw $e;
        }
        $this->grantCreditAfterApprove($customerId, $websiteId, $groupId);
        return $this->publicRow($row);
    }

    /**
     * Re-grant entitlement for an approved audit row after revoke.
     * Keeps status=approved; updates assigned_group_id and membership.
     *
     * @return array<string,mixed>
     */
    public function reauthorize(string $applicationId, string $groupId): array
    {
        $applicationId = trim($applicationId);
        $groupId = trim($groupId);
        if ($applicationId === '' || $groupId === '') {
            throw new B2BConflictException(self::ERROR_INVALID, __('重新授权参数非法'));
        }

        $row = $this->find($applicationId);
        if ($row === null) {
            throw new B2BConflictException(
                self::ERROR_NOT_FOUND,
                __('申请单不存在：%{1}', [$applicationId]),
                ['application_id' => $applicationId],
            );
        }
        if ((string)$row[MembershipApplicationRecord::schema_fields_STATUS]
            !== MembershipApplicationRecord::STATUS_APPROVED
        ) {
            throw new B2BConflictException(
                self::ERROR_NOT_REAUTHORIZABLE,
                __('仅已批准且资格已撤销的申请可重新授权'),
                [
                    'application_id' => $applicationId,
                    'status' => (string)$row[MembershipApplicationRecord::schema_fields_STATUS],
                ],
            );
        }

        $customerId = (string)$row[MembershipApplicationRecord::schema_fields_CUSTOMER_ID];
        $websiteId = (int)$row[MembershipApplicationRecord::schema_fields_WEBSITE_ID];
        $existing = $this->groups->groupForCustomer($customerId, $websiteId);
        if ($existing !== null && $existing->isActive()) {
            throw new B2BConflictException(
                self::ERROR_ALREADY_MEMBER,
                __('已开通批发身份'),
                ['customer_id' => $customerId, 'website_id' => $websiteId],
            );
        }

        $this->groups->assignCustomer($customerId, $groupId);
        $now = gmdate('Y-m-d H:i:s');
        $row[MembershipApplicationRecord::schema_fields_ASSIGNED_GROUP_ID] = $groupId;
        $row[MembershipApplicationRecord::schema_fields_UPDATED_AT] = $now;
        try {
            $this->persist($row);
        } catch (\Throwable $e) {
            try {
                $this->groups->unassignCustomer($customerId, $websiteId);
            } catch (\Throwable) {
            }
            throw $e;
        }
        $this->grantCreditAfterApprove($customerId, $websiteId, $groupId);

        return $this->publicRow($row);
    }

    private function grantCreditAfterApprove(string $customerId, int $websiteId, string $groupId): void
    {
        $grant = $this->creditGrant;
        if ($grant === null) {
            try {
                $grant = ObjectManager::getInstance(B2BCreditGrantService::class);
            } catch (\Throwable) {
                return;
            }
        }
        if (!$grant instanceof B2BCreditGrantService) {
            return;
        }
        try {
            $grant->grantToTarget($customerId, $websiteId, $groupId);
        } catch (\Throwable $e) {
            w_log_error('b2b_credit_grant_after_approve_failed: ' . $e->getMessage(), [
                'customer_id' => $customerId,
                'website_id' => $websiteId,
                'group_id' => $groupId,
            ]);
        }
    }

    /**
     * Latest application for storefront projection (any status).
     *
     * @return array<string,mixed>|null
     */
    public function latestForCustomer(string $customerId, int $websiteId): ?array
    {
        $customerId = trim($customerId);
        if ($customerId === '' || $websiteId < 0) {
            return null;
        }

        if ($this->rows !== null) {
            $best = null;
            foreach ($this->rows as $row) {
                if ((string)$row[MembershipApplicationRecord::schema_fields_CUSTOMER_ID] !== $customerId) {
                    continue;
                }
                if ((int)$row[MembershipApplicationRecord::schema_fields_WEBSITE_ID] !== $websiteId) {
                    continue;
                }
                if ($best === null
                    || (string)$row[MembershipApplicationRecord::schema_fields_CREATED_AT]
                        >= (string)$best[MembershipApplicationRecord::schema_fields_CREATED_AT]
                ) {
                    $best = $row;
                }
            }
            return $best !== null ? $this->publicRow($best) : null;
        }

        $model = $this->newRecord()->clear()
            ->where(MembershipApplicationRecord::schema_fields_CUSTOMER_ID, $customerId)
            ->where(MembershipApplicationRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->order(MembershipApplicationRecord::schema_fields_CREATED_AT, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();
        $row = $model[0] ?? null;
        return is_array($row) ? $this->publicRow($row) : null;
    }

    /** @return array<string,mixed> */
    public function reject(string $applicationId, string $notes = ''): array
    {
        $applicationId = trim($applicationId);
        if ($applicationId === '') {
            throw new B2BConflictException(self::ERROR_INVALID, __('驳回参数非法'));
        }

        $row = $this->requirePending($applicationId);
        $now = gmdate('Y-m-d H:i:s');
        $row[MembershipApplicationRecord::schema_fields_STATUS] = MembershipApplicationRecord::STATUS_REJECTED;
        $row[MembershipApplicationRecord::schema_fields_UPDATED_AT] = $now;
        $notes = trim($notes);
        if ($notes !== '') {
            $existing = trim((string)($row[MembershipApplicationRecord::schema_fields_NOTES] ?? ''));
            $row[MembershipApplicationRecord::schema_fields_NOTES] = $existing !== ''
                ? ($existing . "\n" . $notes)
                : $notes;
        }
        $this->persist($row);
        return $this->publicRow($row);
    }

    /**
     * Permanently remove an application audit row (does not unassign live entitlement).
     *
     * @return array<string,mixed>
     */
    public function delete(string $applicationId): array
    {
        $applicationId = trim($applicationId);
        if ($applicationId === '') {
            throw new B2BConflictException(self::ERROR_INVALID, __('删除申请参数非法'));
        }

        $row = $this->find($applicationId);
        if ($row === null) {
            throw new B2BConflictException(
                self::ERROR_NOT_FOUND,
                __('申请单不存在：%{1}', [$applicationId]),
                ['application_id' => $applicationId],
            );
        }

        $public = $this->publicRow($row);
        if ($this->rows !== null) {
            $index = $row['_memory_index'] ?? null;
            if (!is_int($index)) {
                throw new \RuntimeException('b2b_membership_memory_index_missing');
            }
            array_splice($this->rows, $index, 1);
            return $public + ['deleted' => true];
        }

        $model = $this->newRecord();
        $model->clear()
            ->where(MembershipApplicationRecord::schema_fields_APPLICATION_ID, $applicationId)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            throw new B2BConflictException(
                self::ERROR_NOT_FOUND,
                __('申请单不存在：%{1}', [$applicationId]),
                ['application_id' => $applicationId],
            );
        }
        $model->delete();

        return $public + ['deleted' => true];
    }

    /** @return array<string,mixed>|null */
    private function findPendingForCustomer(string $customerId, int $websiteId): ?array
    {
        if ($this->rows !== null) {
            foreach ($this->rows as $row) {
                if ((string)$row[MembershipApplicationRecord::schema_fields_CUSTOMER_ID] !== $customerId) {
                    continue;
                }
                if ((int)$row[MembershipApplicationRecord::schema_fields_WEBSITE_ID] !== $websiteId) {
                    continue;
                }
                if ((string)$row[MembershipApplicationRecord::schema_fields_STATUS]
                    === MembershipApplicationRecord::STATUS_PENDING
                ) {
                    return $row;
                }
            }
            return null;
        }

        $model = $this->newRecord()->clear()
            ->where(MembershipApplicationRecord::schema_fields_CUSTOMER_ID, $customerId)
            ->where(MembershipApplicationRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->where(
                MembershipApplicationRecord::schema_fields_STATUS,
                MembershipApplicationRecord::STATUS_PENDING,
            )
            ->order(MembershipApplicationRecord::schema_fields_CREATED_AT, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();
        $row = $model[0] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * Raw latest row (any status) with optional memory index for persist.
     *
     * @return array<string,mixed>|null
     */
    private function findLatestRow(string $customerId, int $websiteId): ?array
    {
        if ($this->rows !== null) {
            $best = null;
            $bestIndex = null;
            foreach ($this->rows as $index => $row) {
                if ((string)$row[MembershipApplicationRecord::schema_fields_CUSTOMER_ID] !== $customerId) {
                    continue;
                }
                if ((int)$row[MembershipApplicationRecord::schema_fields_WEBSITE_ID] !== $websiteId) {
                    continue;
                }
                if ($best === null
                    || (string)$row[MembershipApplicationRecord::schema_fields_CREATED_AT]
                        >= (string)$best[MembershipApplicationRecord::schema_fields_CREATED_AT]
                ) {
                    $best = $row;
                    $bestIndex = $index;
                }
            }
            if ($best === null || !is_int($bestIndex)) {
                return null;
            }
            $best['_memory_index'] = $bestIndex;
            return $best;
        }

        $model = $this->newRecord()->clear()
            ->where(MembershipApplicationRecord::schema_fields_CUSTOMER_ID, $customerId)
            ->where(MembershipApplicationRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->order(MembershipApplicationRecord::schema_fields_CREATED_AT, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();
        $row = $model[0] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function resubmitExisting(array $row, string $company, string $phone, string $notes): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $row[MembershipApplicationRecord::schema_fields_COMPANY_NAME] = $company;
        $row[MembershipApplicationRecord::schema_fields_CONTACT_PHONE] = $phone;
        if ($notes !== '') {
            $row[MembershipApplicationRecord::schema_fields_NOTES] = $notes;
        }
        $row[MembershipApplicationRecord::schema_fields_STATUS] = MembershipApplicationRecord::STATUS_PENDING;
        $row[MembershipApplicationRecord::schema_fields_ASSIGNED_GROUP_ID] = null;
        $row[MembershipApplicationRecord::schema_fields_UPDATED_AT] = $now;
        $this->persist($row);
        return $this->publicRow($row);
    }

    /** @return array<string,mixed> */
    private function requirePending(string $applicationId): array
    {
        $row = $this->find($applicationId);
        if ($row === null) {
            throw new B2BConflictException(
                self::ERROR_NOT_FOUND,
                __('申请单不存在：%{1}', [$applicationId]),
                ['application_id' => $applicationId],
            );
        }
        if ((string)$row[MembershipApplicationRecord::schema_fields_STATUS]
            !== MembershipApplicationRecord::STATUS_PENDING
        ) {
            throw new B2BConflictException(
                self::ERROR_NOT_PENDING,
                __('申请单不在待审状态'),
                [
                    'application_id' => $applicationId,
                    'status' => (string)$row[MembershipApplicationRecord::schema_fields_STATUS],
                ],
            );
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function find(string $applicationId): ?array
    {
        if ($this->rows !== null) {
            foreach ($this->rows as $index => $row) {
                if ((string)$row[MembershipApplicationRecord::schema_fields_APPLICATION_ID] === $applicationId) {
                    $row['_memory_index'] = $index;
                    return $row;
                }
            }
            return null;
        }

        $model = $this->newRecord();
        $model->clear()
            ->where(MembershipApplicationRecord::schema_fields_APPLICATION_ID, $applicationId)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            return null;
        }
        return $model->getData();
    }

    /** @param array<string,mixed> $row */
    private function persist(array $row): void
    {
        if ($this->rows !== null) {
            $index = $row['_memory_index'] ?? null;
            unset($row['_memory_index']);
            if (!is_int($index)) {
                throw new \RuntimeException('b2b_membership_memory_index_missing');
            }
            $this->rows[$index] = $row;
            return;
        }

        unset($row['_memory_index']);
        $model = $this->newRecord();
        $model->clear()
            ->where(
                MembershipApplicationRecord::schema_fields_APPLICATION_ID,
                (string)$row[MembershipApplicationRecord::schema_fields_APPLICATION_ID],
            )
            ->find()
            ->fetch();
        if (!$model->getId()) {
            throw new B2BConflictException(
                self::ERROR_NOT_FOUND,
                __('申请单不存在'),
            );
        }
        $model->setData([
            MembershipApplicationRecord::schema_fields_COMPANY_NAME
                => $row[MembershipApplicationRecord::schema_fields_COMPANY_NAME]
                    ?? $model->getData(MembershipApplicationRecord::schema_fields_COMPANY_NAME),
            MembershipApplicationRecord::schema_fields_CONTACT_PHONE
                => $row[MembershipApplicationRecord::schema_fields_CONTACT_PHONE]
                    ?? $model->getData(MembershipApplicationRecord::schema_fields_CONTACT_PHONE),
            MembershipApplicationRecord::schema_fields_STATUS
                => $row[MembershipApplicationRecord::schema_fields_STATUS],
            MembershipApplicationRecord::schema_fields_ASSIGNED_GROUP_ID
                => $row[MembershipApplicationRecord::schema_fields_ASSIGNED_GROUP_ID],
            MembershipApplicationRecord::schema_fields_NOTES
                => $row[MembershipApplicationRecord::schema_fields_NOTES] ?? null,
            MembershipApplicationRecord::schema_fields_UPDATED_AT
                => $row[MembershipApplicationRecord::schema_fields_UPDATED_AT],
        ])->save();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicRow(array $row): array
    {
        return [
            'application_id' => (string)$row[MembershipApplicationRecord::schema_fields_APPLICATION_ID],
            'customer_id' => (string)$row[MembershipApplicationRecord::schema_fields_CUSTOMER_ID],
            'website_id' => (int)$row[MembershipApplicationRecord::schema_fields_WEBSITE_ID],
            'company_name' => (string)$row[MembershipApplicationRecord::schema_fields_COMPANY_NAME],
            'contact_phone' => (string)$row[MembershipApplicationRecord::schema_fields_CONTACT_PHONE],
            'status' => (string)$row[MembershipApplicationRecord::schema_fields_STATUS],
            'assigned_group_id' => $row[MembershipApplicationRecord::schema_fields_ASSIGNED_GROUP_ID] !== null
                && $row[MembershipApplicationRecord::schema_fields_ASSIGNED_GROUP_ID] !== ''
                ? (string)$row[MembershipApplicationRecord::schema_fields_ASSIGNED_GROUP_ID]
                : null,
            'notes' => $row[MembershipApplicationRecord::schema_fields_NOTES] !== null
                ? (string)$row[MembershipApplicationRecord::schema_fields_NOTES]
                : null,
            'created_at' => (string)$row[MembershipApplicationRecord::schema_fields_CREATED_AT],
            'updated_at' => (string)$row[MembershipApplicationRecord::schema_fields_UPDATED_AT],
        ];
    }

    private function newRecord(): MembershipApplicationRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(MembershipApplicationRecord::class, [], false);
    }
}
