<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Server-only task starter. No frontend resource may bind this interface.
 */
interface ResumableSystemTaskStarterInterface
{
    /**
     * @param array<string|int,mixed> $input
     */
    public function startForSystem(string $typeCode, array $input, string $systemPrincipal): TaskSnapshot;
}
