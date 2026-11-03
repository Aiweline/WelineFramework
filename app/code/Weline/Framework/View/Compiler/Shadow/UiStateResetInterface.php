<?php

declare(strict_types=1);

namespace Weline\Framework\View\Compiler\Shadow;

/**
 * Optional per-request UI state resetter for compiled D-form templates,
 * supplied by the data-table module.
 */
interface UiStateResetInterface
{
    public function resetUiState(): void;
}
