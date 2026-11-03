<?php

declare(strict_types=1);

namespace Weline\Framework\View;

/**
 * Optional DEV-time theme template spec gate (implemented by the theme module).
 */
interface ThemeSpecGateInterface
{
    /**
     * @throws \Throwable when the template violates the theme spec.
     */
    public function assertFileForDev(string $originPath): void;
}
