<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Api\AiModel as AiModelSnapshot;
use Weline\Ai\Api\Provider\StreamingProviderSessionInterface;

final class StreamingProviderSession extends ProviderSession implements StreamingProviderSessionInterface
{
    public function generateStreamFull(AiModelSnapshot $model, string $prompt, array $params = []): array
    {
        /** @var callable(\Weline\Ai\Model\AiModel,string,array<string,mixed>):array<string,mixed> $stream */
        $stream = [$this->provider, 'generateStreamFull'];

        return $stream($this->hydrate($model), $prompt, $params);
    }
}
