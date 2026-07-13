<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

interface GlobalDictionaryProviderInterface
{
    /**
     * Resolve one exact fallback translation without materializing a locale.
     */
    public function word(string $locale, string $word): ?string;

    /**
     * Resolve translations owned by the modules involved in the current request.
     * An empty module list is reserved for non-persistent maintenance/CLI flows.
     *
     * @param list<string> $modules
     * @return array<string, string>
     */
    public function words(string $locale, array $modules = []): array;
}
