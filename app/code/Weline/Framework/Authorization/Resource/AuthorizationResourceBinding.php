<?php

declare(strict_types=1);

namespace Weline\Framework\Authorization\Resource;

/** Immutable binding from an auth surface to a canonical source_id. */
final class AuthorizationResourceBinding
{
    public const SURFACE_QUERY_PROVIDER = 'query_provider';
    public const SURFACE_RESUMABLE_TASK = 'resumable_task';
    public const SURFACE_CONTROLLER = 'controller';
    public const SURFACE_MENU = 'menu';
    public const SURFACE_SYSTEM_OPERATION = 'system_operation';

    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $surface,
        public readonly string $surfaceId,
        public readonly string $module,
        public readonly array $metadata = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'surface' => $this->surface,
            'surface_id' => $this->surfaceId,
            'module' => $this->module,
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $metadata = $data['metadata'] ?? [];
        if (!\is_array($metadata)) {
            $metadata = [];
        }
        return new self(
            sourceId: (string)($data['source_id'] ?? ''),
            surface: (string)($data['surface'] ?? ''),
            surfaceId: (string)($data['surface_id'] ?? ''),
            module: (string)($data['module'] ?? ''),
            metadata: $metadata,
        );
    }
}
