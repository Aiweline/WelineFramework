<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Image;

use Weline\Ai\Service\AiService;

final class ImageRuntime implements ImageRuntimeInterface
{
    public function __construct(private readonly AiService $service)
    {
    }

    public function resolveModel(
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        string $primaryModality = 'text2text',
    ): ?array {
        return $this->service->resolveModel($modelCode, $scenarioCode, $primaryModality);
    }

    public function generate(
        string $prompt,
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        array $params = [],
    ): array {
        return $this->service->generateImage($prompt, $modelCode, $scenarioCode, $params);
    }
}
