<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Runtime;

use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\DeveloperAccessProviderInterface;
use Weline\Framework\Runtime\RawDeveloperAccessProviderInterface;

final class DeveloperAccessProvider implements DeveloperAccessProviderInterface, RawDeveloperAccessProviderInterface
{
    public function __construct(
        private readonly PanelAccessService $access,
    ) {
    }

    public function shouldInjectBootstrap(): bool
    {
        return $this->access->shouldInjectBootstrap();
    }

    public function canAccessPanel(?Request $request = null): bool
    {
        return $this->access->canAccessPanel($request);
    }

    public function canAccessApi(?Request $request = null): bool
    {
        return $this->access->canAccessApi($request);
    }

    public function canAccessRawHttp(string $rawRequest): bool
    {
        return $this->access->canAccessRawHttp($rawRequest);
    }
}
