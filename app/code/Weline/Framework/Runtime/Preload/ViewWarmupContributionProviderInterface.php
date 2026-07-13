<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

/**
 * Module-owned, startup-only view warmup contribution.
 *
 * Register implementations as `view_warmup_contribution.<Module_Name>` in
 * etc/module.php. Providers describe data only; they never execute rendering.
 */
interface ViewWarmupContributionProviderInterface
{
    public const CAPABILITY_PREFIX = 'view_warmup_contribution.';

    public function contribution(): ViewWarmupContribution;
}
