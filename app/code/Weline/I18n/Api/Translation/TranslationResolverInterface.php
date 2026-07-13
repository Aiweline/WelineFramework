<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Translation;

interface TranslationResolverInterface
{
    /**
     * @param list<string> $preferredModules
     */
    public function translate(string $source, string $localeCode, array $preferredModules = []): string;
}
