<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Configuration;

final readonly class ScenarioRecord
{
    /** @param array<string, string> $modelBindings */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $version,
        public bool $active,
        public string $defaultModel,
        public array $modelBindings,
    ) {
    }

    public function getModelBinding(string $primaryModality): ?string
    {
        $modelCode = trim((string)($this->modelBindings[$primaryModality] ?? ''));

        return $modelCode !== '' ? $modelCode : null;
    }
}
