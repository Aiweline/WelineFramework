<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * Writer guard for Checkout legacy vs Order Facade（MOD-P2D-004）.
 */
final class OrderWriterGuard
{
    public const ERROR_LEGACY_BLOCKED = 'order_legacy_writer_blocked';
    public const ERROR_NEW_BLOCKED = 'order_new_writer_blocked';

    public function __construct(
        private readonly OrderCutoverGate $gate = new OrderCutoverGate(),
    ) {
    }

    public function gate(): OrderCutoverGate
    {
        return $this->gate;
    }

    public function assertLegacyCheckoutWritable(string $subject = ''): void
    {
        if (!$this->gate->legacyWritable($subject)) {
            throw new OrderFacadeConflictException(
                self::ERROR_LEGACY_BLOCKED,
                \__('旧 Checkout writer 已被 cutover gate 禁止（mode=%{1}）', [$this->gate->mode()]),
                ['mode' => $this->gate->mode(), 'subject' => $subject],
            );
        }
    }

    public function assertNewOrderWritable(string $subject = ''): void
    {
        if (!$this->gate->newWritable($subject)) {
            throw new OrderFacadeConflictException(
                self::ERROR_NEW_BLOCKED,
                \__('新 Order writer 在当前 mode 不可写（mode=%{1}；shadow 仅允许 plan）', [$this->gate->mode()]),
                ['mode' => $this->gate->mode(), 'subject' => $subject],
            );
        }
    }
}
