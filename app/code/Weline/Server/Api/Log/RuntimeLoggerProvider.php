<?php

declare(strict_types=1);

namespace Weline\Server\Api\Log;

use Weline\Framework\Log\LoggerInterface;
use Weline\Framework\Log\RuntimeLoggerProviderInterface;
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLoggerAdapter;

final class RuntimeLoggerProvider implements RuntimeLoggerProviderInterface
{
    public function supports(string $runtime, array $config): bool
    {
        return $runtime === 'wls'
            || ErrorBootstrap::isInitialized();
    }

    public function create(string $channel, array $config): LoggerInterface
    {
        return new WlsLoggerAdapter($channel);
    }
}
