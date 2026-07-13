<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetadataRecord
{
    /**
     * @param array<string, mixed> $metaData
     * @param array<string, mixed> $setting
     */
    public function __construct(
        public int $id,
        public string $namespace,
        public string $type,
        public string $identify,
        public ?string $filePath,
        public ?string $fileFullPath,
        public ?string $area,
        public ?string $category,
        public array $metaData,
        public array $setting,
    ) {
    }
}
