<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\B2BOrderMessageRecord;
use Weline\B2B\Model\B2BOrderThreadRecord;
use Weline\Framework\Manager\ObjectManager;

/**
 * B2B order-scoped chat (not CustomerService). Memory mode for tests; DB via Records.
 */
final class B2BOrderThreadService
{
    public const ERROR_NOT_FOUND = 'b2b_order_chat_not_found';
    public const ERROR_FORBIDDEN = 'b2b_order_chat_forbidden';
    public const ERROR_INVALID = 'b2b_order_chat_invalid';

    /** @var array<string,array<string,mixed>>|null */
    private ?array $threads = null;

    /** @var array<string,list<array<string,mixed>>>|null */
    private ?array $messages = null;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(?callable $clock = null, bool $useMemory = false)
    {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
        if ($useMemory) {
            $this->threads = [];
            $this->messages = [];
        }
    }

    public static function forTesting(?callable $clock = null): self
    {
        return new self($clock, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function openOrCreate(string $orderRef, string $customerId, int $websiteId, ?string $hangId = null): array
    {
        $orderRef = trim($orderRef);
        $customerId = trim($customerId);
        if ($orderRef === '' || $customerId === '' || $websiteId < 0) {
            throw new B2BConflictException(self::ERROR_INVALID, __('订单沟通参数无效'));
        }
        $existing = $this->findByOrderRef($orderRef);
        if ($existing !== null) {
            if ((string)$existing['customer_id'] !== $customerId || (int)$existing['website_id'] !== $websiteId) {
                throw new B2BConflictException(self::ERROR_FORBIDDEN, __('无权打开该订单沟通'));
            }

            return $existing;
        }
        $this->assertHangOwnership($orderRef, $customerId, $websiteId);
        $now = ($this->clock)();
        $row = [
            'thread_id' => 'th_' . bin2hex(random_bytes(10)),
            'order_ref' => $orderRef,
            'hang_id' => $hangId,
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'merchant_unread' => 0,
            'customer_unread' => 0,
            'last_message_at_epoch' => null,
            'created_at_epoch' => $now,
            'updated_at_epoch' => $now,
        ];
        $this->putThread($row);
        $this->messages[$row['thread_id']] = $this->messages[$row['thread_id']] ?? [];

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listMessages(string $threadId, int $sinceRowId = 0): array
    {
        $threadId = trim($threadId);
        $thread = $this->requireThread($threadId);
        unset($thread);
        $all = $this->loadMessages($threadId);
        if ($sinceRowId <= 0) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (array $m): bool => (int)($m['message_row_id'] ?? 0) > $sinceRowId,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function send(string $threadId, string $role, string $body, string $actorCustomerId = ''): array
    {
        $threadId = trim($threadId);
        $role = strtolower(trim($role));
        $body = trim($body);
        if ($body === '' || !in_array($role, [
            B2BOrderMessageRecord::ROLE_CUSTOMER,
            B2BOrderMessageRecord::ROLE_MERCHANT,
            B2BOrderMessageRecord::ROLE_SYSTEM,
        ], true)) {
            throw new B2BConflictException(self::ERROR_INVALID, __('消息无效'));
        }
        $thread = $this->requireThread($threadId);
        if ($role === B2BOrderMessageRecord::ROLE_CUSTOMER) {
            if ($actorCustomerId === '' || (string)$thread['customer_id'] !== $actorCustomerId) {
                throw new B2BConflictException(self::ERROR_FORBIDDEN, __('无权发送订单消息'));
            }
        }
        $now = ($this->clock)();
        $list = $this->loadMessages($threadId);
        $rowId = count($list) + 1;
        $msg = [
            'message_row_id' => $rowId,
            'message_id' => 'msg_' . bin2hex(random_bytes(8)),
            'thread_id' => $threadId,
            'sender_role' => $role,
            'body_text' => $body,
            'attachments_json' => null,
            'created_at_epoch' => $now,
        ];
        $list[] = $msg;
        $this->saveMessages($threadId, $list);
        if ($role === B2BOrderMessageRecord::ROLE_CUSTOMER) {
            $thread['merchant_unread'] = (int)$thread['merchant_unread'] + 1;
        } elseif ($role === B2BOrderMessageRecord::ROLE_MERCHANT) {
            $thread['customer_unread'] = (int)$thread['customer_unread'] + 1;
        }
        $thread['last_message_at_epoch'] = $now;
        $thread['updated_at_epoch'] = $now;
        $this->putThread($thread);

        return $msg;
    }

    /**
     * @return array<string,mixed>
     */
    public function markSeen(string $threadId, string $role, string $actorCustomerId = ''): array
    {
        $thread = $this->requireThread(trim($threadId));
        $role = strtolower(trim($role));
        if ($role === B2BOrderMessageRecord::ROLE_CUSTOMER) {
            if ($actorCustomerId === '' || (string)$thread['customer_id'] !== $actorCustomerId) {
                throw new B2BConflictException(self::ERROR_FORBIDDEN, __('无权标记已读'));
            }
            $thread['customer_unread'] = 0;
        } elseif ($role === B2BOrderMessageRecord::ROLE_MERCHANT) {
            $thread['merchant_unread'] = 0;
        } else {
            throw new B2BConflictException(self::ERROR_INVALID, __('角色无效'));
        }
        $thread['updated_at_epoch'] = ($this->clock)();
        $this->putThread($thread);

        return $thread;
    }

    public function countUnreadForCustomer(int $customerId, int $websiteId): int
    {
        if ($customerId <= 0) {
            return 0;
        }
        $total = 0;
        foreach ($this->allThreads() as $thread) {
            if ((string)$thread['customer_id'] !== (string)$customerId) {
                continue;
            }
            if ((int)$thread['website_id'] !== $websiteId) {
                continue;
            }
            $total += max(0, (int)$thread['customer_unread']);
        }

        return $total;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listThreadsForCustomer(int $customerId, int $websiteId): array
    {
        $out = [];
        foreach ($this->allThreads() as $thread) {
            if ((string)$thread['customer_id'] !== (string)$customerId) {
                continue;
            }
            if ((int)$thread['website_id'] !== $websiteId) {
                continue;
            }
            $out[] = $thread;
        }

        return $out;
    }

    private function assertHangOwnership(string $orderRef, string $customerId, int $websiteId): void
    {
        try {
            $hang = ObjectManager::getInstance(B2BHangOrderService::class);
            if (!$hang instanceof B2BHangOrderService) {
                return;
            }
            $row = $hang->getByOrderRef($orderRef);
            if ($row === null) {
                return;
            }
            if ((string)$row->customerId !== $customerId || (int)$row->websiteId !== $websiteId) {
                throw new B2BConflictException(self::ERROR_FORBIDDEN, __('无权打开该挂单沟通'));
            }
        } catch (B2BConflictException $e) {
            throw $e;
        } catch (\Throwable) {
            // Hang optional when Order-only thread.
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getByOrderRef(string $orderRef): ?array
    {
        $orderRef = trim($orderRef);
        if ($orderRef === '') {
            return null;
        }

        return $this->findByOrderRef($orderRef);
    }

    /**
     * Merchant/admin open: reuse thread or create for the order's customer.
     *
     * @return array<string,mixed>
     */
    public function openOrCreateForMerchant(string $orderRef, string $customerId, int $websiteId, ?string $hangId = null): array
    {
        $orderRef = trim($orderRef);
        $customerId = trim($customerId);
        if ($orderRef === '' || $customerId === '' || $websiteId < 0) {
            throw new B2BConflictException(self::ERROR_INVALID, __('订单沟通参数无效'));
        }
        $existing = $this->findByOrderRef($orderRef);
        if ($existing !== null) {
            return $existing;
        }

        return $this->openOrCreate($orderRef, $customerId, $websiteId, $hangId);
    }

    /** @return array<string,mixed>|null */
    private function findByOrderRef(string $orderRef): ?array
    {
        foreach ($this->allThreads() as $thread) {
            if ((string)$thread['order_ref'] === $orderRef) {
                return $thread;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function requireThread(string $threadId): array
    {
        foreach ($this->allThreads() as $thread) {
            if ((string)$thread['thread_id'] === $threadId) {
                return $thread;
            }
        }
        throw new B2BConflictException(self::ERROR_NOT_FOUND, __('沟通线程不存在'));
    }

    /** @return list<array<string,mixed>> */
    private function allThreads(): array
    {
        if ($this->threads !== null) {
            return array_values($this->threads);
        }
        try {
            /** @var B2BOrderThreadRecord $model */
            $model = ObjectManager::create(B2BOrderThreadRecord::class, [], false);
            $rows = $model->select()->fetchArray();
            $out = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row)) {
                    $out[] = $this->hydrateThread($row);
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $row */
    private function putThread(array $row): void
    {
        if ($this->threads !== null) {
            $this->threads[(string)$row['thread_id']] = $row;

            return;
        }
        try {
            /** @var B2BOrderThreadRecord $model */
            $model = ObjectManager::create(B2BOrderThreadRecord::class, [], false);
            $existing = $model->clear()
                ->where(B2BOrderThreadRecord::schema_fields_THREAD_ID, $row['thread_id'])
                ->find()
                ->fetch();
            $target = $existing->getId() ? $existing : ObjectManager::create(B2BOrderThreadRecord::class, [], false);
            $target->setData([
                B2BOrderThreadRecord::schema_fields_THREAD_ID => $row['thread_id'],
                B2BOrderThreadRecord::schema_fields_ORDER_REF => $row['order_ref'],
                B2BOrderThreadRecord::schema_fields_HANG_ID => $row['hang_id'],
                B2BOrderThreadRecord::schema_fields_CUSTOMER_ID => $row['customer_id'],
                B2BOrderThreadRecord::schema_fields_WEBSITE_ID => $row['website_id'],
                B2BOrderThreadRecord::schema_fields_MERCHANT_UNREAD => (int)$row['merchant_unread'],
                B2BOrderThreadRecord::schema_fields_CUSTOMER_UNREAD => (int)$row['customer_unread'],
                B2BOrderThreadRecord::schema_fields_LAST_MESSAGE_AT_EPOCH => $row['last_message_at_epoch'],
                B2BOrderThreadRecord::schema_fields_CREATED_AT_EPOCH => (int)$row['created_at_epoch'],
                B2BOrderThreadRecord::schema_fields_UPDATED_AT_EPOCH => (int)$row['updated_at_epoch'],
            ])->save();
        } catch (\Throwable) {
            // Soft-fail persistence in degraded envs.
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateThread(array $row): array
    {
        return [
            'thread_id' => (string)($row[B2BOrderThreadRecord::schema_fields_THREAD_ID] ?? $row['thread_id'] ?? ''),
            'order_ref' => (string)($row[B2BOrderThreadRecord::schema_fields_ORDER_REF] ?? $row['order_ref'] ?? ''),
            'hang_id' => $row[B2BOrderThreadRecord::schema_fields_HANG_ID] ?? $row['hang_id'] ?? null,
            'customer_id' => (string)($row[B2BOrderThreadRecord::schema_fields_CUSTOMER_ID] ?? $row['customer_id'] ?? ''),
            'website_id' => (int)($row[B2BOrderThreadRecord::schema_fields_WEBSITE_ID] ?? $row['website_id'] ?? 0),
            'merchant_unread' => (int)($row[B2BOrderThreadRecord::schema_fields_MERCHANT_UNREAD] ?? $row['merchant_unread'] ?? 0),
            'customer_unread' => (int)($row[B2BOrderThreadRecord::schema_fields_CUSTOMER_UNREAD] ?? $row['customer_unread'] ?? 0),
            'last_message_at_epoch' => $row[B2BOrderThreadRecord::schema_fields_LAST_MESSAGE_AT_EPOCH] ?? $row['last_message_at_epoch'] ?? null,
            'created_at_epoch' => (int)($row[B2BOrderThreadRecord::schema_fields_CREATED_AT_EPOCH] ?? $row['created_at_epoch'] ?? 0),
            'updated_at_epoch' => (int)($row[B2BOrderThreadRecord::schema_fields_UPDATED_AT_EPOCH] ?? $row['updated_at_epoch'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function loadMessages(string $threadId): array
    {
        if ($this->messages !== null) {
            return array_values($this->messages[$threadId] ?? []);
        }
        try {
            /** @var B2BOrderMessageRecord $model */
            $model = ObjectManager::create(B2BOrderMessageRecord::class, [], false);
            $rows = $model->where(B2BOrderMessageRecord::schema_fields_THREAD_ID, $threadId)
                ->order(B2BOrderMessageRecord::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
            $out = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = [
                    'message_row_id' => (int)($row[B2BOrderMessageRecord::schema_fields_ID] ?? 0),
                    'message_id' => (string)($row[B2BOrderMessageRecord::schema_fields_MESSAGE_ID] ?? ''),
                    'thread_id' => (string)($row[B2BOrderMessageRecord::schema_fields_THREAD_ID] ?? ''),
                    'sender_role' => (string)($row[B2BOrderMessageRecord::schema_fields_SENDER_ROLE] ?? ''),
                    'body_text' => (string)($row[B2BOrderMessageRecord::schema_fields_BODY_TEXT] ?? ''),
                    'attachments_json' => $row[B2BOrderMessageRecord::schema_fields_ATTACHMENTS_JSON] ?? null,
                    'created_at_epoch' => (int)($row[B2BOrderMessageRecord::schema_fields_CREATED_AT_EPOCH] ?? 0),
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<array<string,mixed>> $list */
    private function saveMessages(string $threadId, array $list): void
    {
        if ($this->messages !== null) {
            $this->messages[$threadId] = $list;

            return;
        }
        $last = $list[array_key_last($list)] ?? null;
        if (!is_array($last)) {
            return;
        }
        try {
            /** @var B2BOrderMessageRecord $model */
            $model = ObjectManager::create(B2BOrderMessageRecord::class, [], false);
            $model->setData([
                B2BOrderMessageRecord::schema_fields_MESSAGE_ID => $last['message_id'],
                B2BOrderMessageRecord::schema_fields_THREAD_ID => $threadId,
                B2BOrderMessageRecord::schema_fields_SENDER_ROLE => $last['sender_role'],
                B2BOrderMessageRecord::schema_fields_BODY_TEXT => $last['body_text'],
                B2BOrderMessageRecord::schema_fields_ATTACHMENTS_JSON => $last['attachments_json'],
                B2BOrderMessageRecord::schema_fields_CREATED_AT_EPOCH => (int)$last['created_at_epoch'],
            ])->save();
        } catch (\Throwable) {
            // Soft-fail.
        }
    }
}
