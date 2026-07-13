<?php

declare(strict_types=1);

namespace Weline\Framework\View;

interface CompileStateResetterInterface
{
    public function resetCompileState(): void;
}
