<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * deploy.static_asset_mode.* — a module moves its own static files into the root
 * the mode class already chose. It does not read system.deploy or open staging.
 */
interface StaticAssetModeContributorInterface
{
    public function publish(StaticAssetModeContext $context): void;
}
