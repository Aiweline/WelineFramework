<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Image;

interface ImageRuntimeInterface
{
    /** @return array<string, mixed>|null */
    public function resolveModel(
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        string $primaryModality = 'text2text',
    ): ?array;

    /** @return array<string, mixed> */
    public function generate(
        string $prompt,
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        array $params = [],
    ): array;
}
