<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute\Option;

interface AttributeOptionStoreInterface
{
    public const SCOPE_SHARED = 0;

    public function register(AttributeOptionDefinition $definition): void;

    /** Find a shared catalog option (scope_instance_id = 0). */
    public function find(int $attributeId, string $code): ?AttributeOptionRecord;

    public function findInScope(
        int $attributeId,
        string $code,
        int $scopeInstanceId,
    ): ?AttributeOptionRecord;

    /**
     * Match shared first (by code or normalized label), then instance-private;
     * otherwise insert a private row when $scopeInstanceId > 0, or shared when 0.
     */
    public function ensureInScope(
        int $eavEntityId,
        int $attributeId,
        int $scopeInstanceId,
        string $code,
        string $label,
        string $swatchColor = '',
        string $swatchImage = '',
        string $swatchText = '',
    ): AttributeOptionRecord;

    public function assertUsableByInstance(int $optionId, int $scopeInstanceId): AttributeOptionRecord;
}
