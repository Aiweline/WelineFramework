<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

/**
 * Resolved locale catalog boundary for switcher / select consumers.
 */
final class LocaleCatalogScope
{
    public const MODE_WEBSITE = 'website';
    public const MODE_BACKEND_PLATFORM = 'backend_platform';
    public const MODE_INJECTED = 'injected';

    /**
     * @param list<string> $codes
     */
    public function __construct(
        public readonly array $codes,
        public readonly string $defaultCode,
        public readonly string $currentCode,
        public readonly string $displayLocale,
        public readonly bool $allowRequest,
        public readonly string $mode,
        public readonly int $websiteId = 0,
    ) {
    }
}
