<?php

declare(strict_types=1);

namespace Weline\ModuleRouter\Api;

/** Public read-only boundary for the compiled module-router rule map. */
interface RouterRulesReaderInterface
{
    /** @return array<string, array{origin: string, class: string}> */
    public function read(): array;
}
