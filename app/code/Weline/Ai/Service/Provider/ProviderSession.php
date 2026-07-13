<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Api\AiModel as AiModelSnapshot;
use Weline\Ai\Api\Provider\ProviderSessionInterface;
use Weline\Ai\Model\AiModel;

class ProviderSession implements ProviderSessionInterface
{
    public function __construct(
        protected readonly ProviderInterface $provider,
        protected readonly AiModel $modelPrototype,
    ) {
    }

    public function generate(AiModelSnapshot $model, string $prompt, array $params = []): array
    {
        return $this->provider->generate($this->hydrate($model), $prompt, $params);
    }

    public function getProviderCode(): string
    {
        return $this->provider->getProviderCode();
    }

    protected function hydrate(AiModelSnapshot $snapshot): AiModel
    {
        $model = clone $this->modelPrototype;
        $model->clearQuery()->clearData()->setData($snapshot->toArray());

        return $model;
    }
}
