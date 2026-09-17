<?php

declare(strict_types=1);

namespace Weline\FileManager\Service\MediaReference;

/**
 * Immutable media reference identity built by w_scope / MediaReferenceIdentityBuilder.
 */
final readonly class MediaReferenceIdentity
{
    /**
     * @param array<string, string> $tags
     * @param array<string, mixed> $slot
     */
    public function __construct(
        public string $root,
        public string $scope,
        public string $code,
        public string $path,
        public array $tags,
        public array $slot = [],
        public string $codeKey = 'sku',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'scope' => $this->scope,
            'code' => $this->code,
            'code_key' => $this->codeKey,
            'path' => $this->path,
            'tags' => $this->tags,
            'slot' => $this->slot,
        ];
    }
}
