<?php

declare(strict_types=1);

namespace Weline\Framework\Log;

interface RuntimeLoggerProviderInterface
{
    public function supports(string $runtime, array $config): bool;

    public function create(string $channel, array $config): LoggerInterface;
}
