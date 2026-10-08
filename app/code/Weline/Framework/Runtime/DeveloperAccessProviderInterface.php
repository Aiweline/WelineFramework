<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Http\Request;

interface DeveloperAccessProviderInterface
{
    public function shouldInjectBootstrap(): bool;

    public function canAccessPanel(?Request $request = null): bool;

    public function canAccessApi(?Request $request = null): bool;

    /**
     * True only when a real panel Cookie session is active.
     * Must not alias {@see canAccessPanel()} (dev mode makes that always true).
     */
    public function hasActivePanelSession(): bool;
}
