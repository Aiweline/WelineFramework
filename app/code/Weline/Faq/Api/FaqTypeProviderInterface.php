<?php

declare(strict_types=1);

namespace Weline\Faq\Api;

/**
 * FAQ owner publishes only the type it owns. Entity resolution normalizes
 * offer / product / site identifiers into storage entity_id + entity_uuid.
 */
interface FaqTypeProviderInterface
{
    public function typeCode(): string;

    /** @return array{entity_id:int,entity_uuid:string}|null */
    public function resolveEntity(string $uuid): ?array;
}
