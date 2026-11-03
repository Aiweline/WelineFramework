<?php

declare(strict_types=1);

namespace Weline\DataTable\Integration\Framework;

use Weline\DataTable\Helper\UiAssets;
use Weline\Framework\View\Compiler\Shadow\UiStateResetInterface;

/**
 * Resets DataTable UI request state on behalf of the shadow compiler.
 */
final class UiStateReset implements UiStateResetInterface
{
    public function resetUiState(): void
    {
        UiAssets::resetRequestState();
    }
}
