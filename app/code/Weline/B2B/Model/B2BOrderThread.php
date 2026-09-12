<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

/**
 * B2B 订单沟通线程领域投影（定金挂单绑定；不绑客服 ChatSession）。
 */
final class B2BOrderThread
{
    public const MENU_SIGNAL_CODE = 'b2b.order_chat';
    public const ACCOUNT_SECTION = 'b2b-order-chat';

    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_MERCHANT = 'merchant';
    public const ROLE_SYSTEM = 'system';

    public const ROLES = [
        self::ROLE_CUSTOMER,
        self::ROLE_MERCHANT,
        self::ROLE_SYSTEM,
    ];

    public function __construct(
        public readonly string $threadId,
        public readonly string $orderRef,
        public readonly string $customerId,
        public readonly int $websiteId,
        public readonly ?string $hangId = null,
        public readonly int $merchantUnread = 0,
        public readonly int $customerUnread = 0,
        public readonly ?int $lastMessageAtEpoch = null,
        public readonly int $createdAtEpoch = 0,
        public readonly int $updatedAtEpoch = 0,
    ) {
        if ($threadId === '' || strlen($threadId) > 64) {
            throw new \InvalidArgumentException(__('B2B thread_id 非法'));
        }
        if ($orderRef === '' || strlen($orderRef) > 64) {
            throw new \InvalidArgumentException(__('B2B thread order_ref 非法'));
        }
        if ($customerId === '' || strlen($customerId) > 64) {
            throw new \InvalidArgumentException(__('B2B thread customer_id 非法'));
        }
        if ($websiteId < 0 || $merchantUnread < 0 || $customerUnread < 0) {
            throw new \InvalidArgumentException(__('B2B thread 计数非法'));
        }
        if ($hangId !== null && ($hangId === '' || strlen($hangId) > 64)) {
            throw new \InvalidArgumentException(__('B2B thread hang_id 非法'));
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'thread_id' => $this->threadId,
            'order_ref' => $this->orderRef,
            'hang_id' => $this->hangId,
            'customer_id' => $this->customerId,
            'website_id' => $this->websiteId,
            'merchant_unread' => $this->merchantUnread,
            'customer_unread' => $this->customerUnread,
            'last_message_at_epoch' => $this->lastMessageAtEpoch,
            'created_at_epoch' => $this->createdAtEpoch,
            'updated_at_epoch' => $this->updatedAtEpoch,
        ];
    }

    public function with(
        ?int $merchantUnread = null,
        ?int $customerUnread = null,
        ?int $lastMessageAtEpoch = null,
        ?int $updatedAtEpoch = null,
        ?string $hangId = null,
    ): self {
        return new self(
            threadId: $this->threadId,
            orderRef: $this->orderRef,
            customerId: $this->customerId,
            websiteId: $this->websiteId,
            hangId: $hangId !== null ? ($hangId !== '' ? $hangId : null) : $this->hangId,
            merchantUnread: $merchantUnread ?? $this->merchantUnread,
            customerUnread: $customerUnread ?? $this->customerUnread,
            lastMessageAtEpoch: $lastMessageAtEpoch ?? $this->lastMessageAtEpoch,
            createdAtEpoch: $this->createdAtEpoch,
            updatedAtEpoch: $updatedAtEpoch ?? $this->updatedAtEpoch,
        );
    }
}
