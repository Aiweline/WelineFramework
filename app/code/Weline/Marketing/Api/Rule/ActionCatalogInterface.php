<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Rule;

interface ActionCatalogInterface
{
    /** @return list<ActionDescriptor> */
    public function all(): array;
}
