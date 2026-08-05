<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Operation;

/**
 * Process-local scope for one non-hot setup:upgrade invocation.
 *
 * Consumers must receive the current ID explicitly. This object owns only the
 * begin/clear boundary and is never consulted by standalone migration or
 * rollback services.
 */
final class SetupOperationContext
{
    public const MAX_OPERATION_ID_LENGTH = 64;

    private ?string $operationId = null;

    public function begin(): string
    {
        if ($this->operationId !== null) {
            throw new \LogicException(self::class . ' already has an active operation');
        }

        $operationId = sprintf(
            'setup-%s-%s',
            gmdate('YmdHis'),
            bin2hex(random_bytes(16)),
        );
        if (strlen($operationId) > self::MAX_OPERATION_ID_LENGTH) {
            throw new \LogicException(self::class);
        }

        return $this->operationId = $operationId;
    }

    public function current(): ?string
    {
        return $this->operationId;
    }

    public function clear(): void
    {
        $this->operationId = null;
    }
}
