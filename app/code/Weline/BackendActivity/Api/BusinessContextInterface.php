<?php
declare(strict_types=1);

namespace Weline\BackendActivity\Api;

interface BusinessContextInterface
{
    /**
     * @param array<string,mixed> $payload
     */
    public function mark(
        string $businessModule,
        string $entityType,
        string|int $entityId,
        string $action,
        string $title = '',
        array $payload = []
    ): void;
}
