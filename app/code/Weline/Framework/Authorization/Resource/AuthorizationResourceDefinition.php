<?php

declare(strict_types=1);

namespace Weline\Framework\Authorization\Resource;

/** Immutable canonical ACL resource definition produced by Framework catalog. */
final class AuthorizationResourceDefinition
{
    /**
     * @param list<string> $tags
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $name,
        public readonly string $description,
        public readonly string $module,
        public readonly string $resourceType,
        public readonly string $origin,
        public readonly string $accessMode = 'edit',
        public readonly bool $isBackend = true,
        public readonly bool $apiExposable = false,
        public readonly string $scopeGroup = '',
        public readonly string $parentSource = '',
        public readonly array $tags = [],
        public readonly string $code = '',
        public readonly array $metadata = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'name' => $this->name,
            'description' => $this->description,
            'module' => $this->module,
            'resource_type' => $this->resourceType,
            'origin' => $this->origin,
            'access_mode' => $this->accessMode,
            'is_backend' => $this->isBackend,
            'api_exposable' => $this->apiExposable,
            'scope_group' => $this->scopeGroup,
            'parent_source' => $this->parentSource,
            'tags' => $this->tags,
            'code' => $this->code,
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $tags = $data['tags'] ?? [];
        if (!\is_array($tags)) {
            $tags = [];
        }
        $metadata = $data['metadata'] ?? [];
        if (!\is_array($metadata)) {
            $metadata = [];
        }
        return new self(
            sourceId: (string)($data['source_id'] ?? ''),
            name: (string)($data['name'] ?? ''),
            description: (string)($data['description'] ?? ''),
            module: (string)($data['module'] ?? ''),
            resourceType: (string)($data['resource_type'] ?? ''),
            origin: (string)($data['origin'] ?? ''),
            accessMode: (string)($data['access_mode'] ?? 'edit'),
            isBackend: (bool)($data['is_backend'] ?? true),
            apiExposable: (bool)($data['api_exposable'] ?? false),
            scopeGroup: (string)($data['scope_group'] ?? ''),
            parentSource: (string)($data['parent_source'] ?? ''),
            tags: \array_values(\array_map('strval', $tags)),
            code: (string)($data['code'] ?? ''),
            metadata: $metadata,
        );
    }
}
