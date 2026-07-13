<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\View\CompileStateResetterInterface;
use Weline\Theme\Taglib\Slot;

final class CompileStateResetter implements CompileStateResetterInterface
{
    public function resetCompileState(): void
    {
        Slot::clearRegisteredSlots();
    }
}
