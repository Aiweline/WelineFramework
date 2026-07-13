<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

interface AdapterStyleBindingInterface
{
    /** @return list<string> */
    public function getDefaultStyleCodes(): array;
}
