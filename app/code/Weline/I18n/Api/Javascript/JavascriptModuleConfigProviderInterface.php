<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Javascript;

/**
 * Supplies immutable weline.modules.js source without coupling I18n to Theme.
 */
interface JavascriptModuleConfigProviderInterface
{
    public function content(string $area): string;

    public function priority(): int;
}
