<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Provider;

use Weline\Ai\Api\AiModel;

/** A provider session bound to one selected provider family. */
interface ProviderSessionInterface
{
    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function generate(AiModel $model, string $prompt, array $params = []): array;

    public function getProviderCode(): string;
}
