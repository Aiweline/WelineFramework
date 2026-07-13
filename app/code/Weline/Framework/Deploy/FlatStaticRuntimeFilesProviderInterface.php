<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

interface FlatStaticRuntimeFilesProviderInterface
{
    public function moduleName(): string;

    /**
     * @return list<string>
     */
    public function relativeFiles(): array;
}
