<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Process default theme config for Env (and similar).
 * Theme provides the implementation; Framework must not soft-pull Theme FQCN.
 */
interface DefaultThemeConfigProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getRegisteredDefault(?string $area = null): array;
}
