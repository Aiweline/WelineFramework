<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\Runtime\RuntimeProviderInterface;
use Weline\Server\Runtime\WlsRuntime;

/**
 * Bootstrap-safe RuntimeProvider: zero-arg constructible, no ObjectManager.
 */
final class WlsRuntimeProvider implements RuntimeProviderInterface
{
    public function supports(string $mode): bool
    {
        return $mode === RuntimeInterface::MODE_WLS;
    }

    public function create(string $mode): RuntimeInterface
    {
        if (!$this->supports($mode)) {
            throw new \InvalidArgumentException(
                'WlsRuntimeProvider only supports mode ' . RuntimeInterface::MODE_WLS
                . ', got: ' . $mode
            );
        }

        return new WlsRuntime();
    }
}
