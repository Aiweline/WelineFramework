<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

interface AdapterModelBindingInterface
{
    /** @return array<string, string> */
    public function getDefaultModelBindings(): array;
}
