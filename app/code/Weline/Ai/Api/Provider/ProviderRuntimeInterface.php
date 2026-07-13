<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Provider;

use Weline\Ai\Api\AiModel;

/** Public provider-selection boundary; internal provider objects never escape. */
interface ProviderRuntimeInterface
{
    public function getProvider(AiModel $model): ProviderSessionInterface;
}
