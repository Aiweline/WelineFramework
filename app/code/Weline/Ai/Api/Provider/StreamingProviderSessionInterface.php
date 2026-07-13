<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Provider;

use Weline\Ai\Api\AiModel;

interface StreamingProviderSessionInterface extends ProviderSessionInterface
{
    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function generateStreamFull(AiModel $model, string $prompt, array $params = []): array;
}
