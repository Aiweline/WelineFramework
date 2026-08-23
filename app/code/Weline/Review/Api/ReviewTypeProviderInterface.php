<?php

declare(strict_types=1);

namespace Weline\Review\Api;

/**
 * A review owner publishes only the type it owns. Themes may override its
 * templates, while field validation always remains server-owned here. Values
 * for extension fields declared by fields() must be returned from
 * normalizeValues() inside the extra array so they survive storage and readback.
 */
interface ReviewTypeProviderInterface
{
    public function typeCode(): string;

    /** @return array{entity_id:int,entity_uuid:string}|null */
    public function resolveEntity(string $externalEntityUuid): ?array;

    /** @return list<array<string,mixed>> */
    public function fields(): array;

    /** @return array<string,mixed> */
    public function normalizeValues(array $values, ?int $customerId): array;
}
