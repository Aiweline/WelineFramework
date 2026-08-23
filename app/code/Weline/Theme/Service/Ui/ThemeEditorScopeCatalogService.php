<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ui;

use Weline\SystemConfig\Api\Scope\ScopeSelectorCatalogInterface;

/**
 * @deprecated Theme consumers should depend on ScopeSelectorCatalogInterface.
 */
final class ThemeEditorScopeCatalogService
{
    public function __construct(
        private readonly ScopeSelectorCatalogInterface $selectorCatalog,
    ) {
    }

    public function build(string $selectedScope, ?array $catalogOptions = null, array $claims = []): array
    {
        return $this->selectorCatalog->build($selectedScope, $catalogOptions, $claims);
    }
}
