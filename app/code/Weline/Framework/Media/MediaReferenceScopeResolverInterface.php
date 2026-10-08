<?php

declare(strict_types=1);

namespace Weline\Framework\Media;

/**
 * Resolves ambient media storage_scope from RequestContext.
 * FileManager provides the implementation; Framework only resolves this contract.
 */
interface MediaReferenceScopeResolverInterface
{
    public function fromContext(): ?string;
}
