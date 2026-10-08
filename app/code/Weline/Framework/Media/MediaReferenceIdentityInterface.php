<?php

declare(strict_types=1);

namespace Weline\Framework\Media;

/**
 * Immutable media-reference identity (MediaReferenceIdentity.v1).
 */
interface MediaReferenceIdentityInterface
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
