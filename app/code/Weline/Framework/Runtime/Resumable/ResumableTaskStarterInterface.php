<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Safe start boundary used by frontend QueryProvider resources.
 *
 * It deliberately does not accept a PHP handler class, a policy, or a
 * business key from the browser. Those values are owned by the registered
 * business handler's prepareStart() implementation.
 */
interface ResumableTaskStarterInterface
{
    /**
     * @param array<string|int,mixed> $input
     */
    public function startForOwner(string $typeCode, array $input, TaskOwner $owner): TaskHandle;
}
