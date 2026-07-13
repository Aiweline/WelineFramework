<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetadataWrite
{
    /**
     * @param array<string, mixed> $metaData
     * @param array<string, mixed> $setting
     */
    public function __construct(
        public MetadataIdentity $identity,
        public array $metaData = [],
        public array $setting = [],
        public ?string $filePath = null,
        public ?string $fileFullPath = null,
        public ?string $area = null,
        public ?string $category = null,
    ) {
    }
}
