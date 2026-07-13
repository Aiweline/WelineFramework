<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetaConfigRecord
{
    public function __construct(
        public int $id,
        public string $namespace,
        public string $configKey,
        public string $value,
        public string $scope,
        public ?string $locale,
        public ?string $identifyId,
        public ?int $metaId,
        public ?string $metaIdentify,
    ) {
    }
}
