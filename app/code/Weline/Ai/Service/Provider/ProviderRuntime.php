<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Api\AiModel as AiModelSnapshot;
use Weline\Ai\Api\Provider\ProviderRuntimeInterface;
use Weline\Ai\Api\Provider\ProviderSessionInterface;
use Weline\Ai\Model\AiModel;

final class ProviderRuntime implements ProviderRuntimeInterface
{
    public function __construct(
        private readonly ProviderFactory $providerFactory,
        private readonly AiModel $modelPrototype,
    ) {
    }

    public function getProvider(AiModelSnapshot $model): ProviderSessionInterface
    {
        $record = $this->hydrate($model);
        $provider = $this->providerFactory->getProvider($record);

        return method_exists($provider, 'generateStreamFull')
            ? new StreamingProviderSession($provider, $this->modelPrototype)
            : new ProviderSession($provider, $this->modelPrototype);
    }

    private function hydrate(AiModelSnapshot $snapshot): AiModel
    {
        $model = clone $this->modelPrototype;
        $model->clearQuery()->clearData()->setData($snapshot->toArray());

        return $model;
    }
}
