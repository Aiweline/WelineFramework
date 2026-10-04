<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Theme;

final readonly class ThemeApplicationResolution
{
    public function __construct(
        public ?ThemeApplicationReference $reference,
        public ?string $sourceScopeKey,
        public bool $own,
        public int $localRevision,
    ) {
    }
}
