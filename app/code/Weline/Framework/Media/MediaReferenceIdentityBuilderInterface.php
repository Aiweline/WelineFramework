<?php

declare(strict_types=1);

namespace Weline\Framework\Media;

/**
 * Builds media-reference identities. FileManager provides the implementation;
 * Framework w_scope() only resolves this contract.
 */
interface MediaReferenceIdentityBuilderInterface
{
    /**
     * @param array<string, mixed> $other
     */
    public function build(
        ?string $scope,
        string $type,
        string $code,
        array $other = [],
    ): MediaReferenceIdentityInterface;
}
