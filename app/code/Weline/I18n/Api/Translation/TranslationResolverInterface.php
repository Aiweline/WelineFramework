<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Translation;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Scope\PhraseScopeValue;

interface TranslationResolverInterface
{
    /**
     * @param list<string> $preferredModules
     */
    public function translate(string $source, string $localeCode, array $preferredModules = []): string;

    /**
     * typed Scope + locale fallback（TASK-P1C-005-I18N）。
     * 旧 translate() 仍为无 Scope 精确/模块 CSV 路径。
     *
     * @param list<string> $preferredModules
     * @param list<string>|null $localeFallbackChain
     */
    public function translateForScope(
        string $source,
        ScopeIdentity $identity,
        string $localeCode,
        array $preferredModules = [],
        ?array $localeFallbackChain = null,
    ): PhraseScopeValue;
}
