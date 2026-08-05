<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Scope\PhraseScopeSource;
use Weline\I18n\Api\Scope\PhraseScopeValue;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TASK-P1C-005-I18N：phrase 按 Scope 链 + locale 链解析。
 *
 * 词典键约定与 Meta Taglib 一致：`{source}|scope:{storageScope}`；
 * 无 scope 后缀的词作为最终 default 回落。
 */
final class PhraseScopeResolver
{
    public const SCOPE_SUFFIX = '|scope:';

    public function __construct(
        private readonly SystemConfigScopeResolver $scopeResolver,
    ) {
    }

    /**
     * @param callable(string $word, string $locale): (?string) $lookup 命中译文；null/空表示未命中
     * @param list<string>|null $localeFallbackChain
     */
    public function resolve(
        string $source,
        ScopeIdentity $identity,
        string $localeCode,
        callable $lookup,
        ?array $localeFallbackChain = null,
        ?callable $unscopedFallback = null,
    ): PhraseScopeValue {
        $source = \trim($source);
        $localeCode = \trim($localeCode);
        $chain = $this->scopeResolver->chainFromIdentity($identity);
        $exactStorage = $this->scopeResolver->toStorageScope($identity);
        $locales = $this->normalizeLocaleChain($localeCode, $localeFallbackChain);

        if ($source !== '') {
            foreach ($chain as $storageScope) {
                $word = $this->scopedWord($source, $storageScope);
                foreach ($locales as $locale) {
                    $hit = $lookup($word, $locale);
                    if ($hit === null || $hit === '') {
                        continue;
                    }
                    $hitIdentity = $this->scopeResolver->fromStorageScope($storageScope);
                    $sourceKind = $storageScope === $exactStorage
                        ? PhraseScopeSource::KIND_EXACT
                        : PhraseScopeSource::KIND_FALLBACK;

                    return new PhraseScopeValue(
                        text: $hit,
                        source: new PhraseScopeSource(
                            sourceKind: $sourceKind,
                            scopeKind: $hitIdentity?->scopeKind,
                            storageScope: $storageScope,
                            locale: $locale,
                            lookupWord: $word,
                        ),
                        requestedScope: $identity,
                        requestedLocale: $localeCode,
                        fallbackStorageScopes: $chain,
                        localeFallbackChain: $locales,
                    );
                }
            }
        }

        $defaultText = $source;
        if ($unscopedFallback !== null && $source !== '') {
            $defaultText = (string)$unscopedFallback($source, $localeCode);
            if ($defaultText === '') {
                $defaultText = $source;
            }
        }

        return new PhraseScopeValue(
            text: $defaultText,
            source: $source === ''
                ? PhraseScopeSource::unresolved()
                : PhraseScopeSource::fromDefault($localeCode !== '' ? $localeCode : null),
            requestedScope: $identity,
            requestedLocale: $localeCode,
            fallbackStorageScopes: $chain,
            localeFallbackChain: $locales,
        );
    }

    public function scopedWord(string $source, string $storageScope): string
    {
        return $source . self::SCOPE_SUFFIX . $storageScope;
    }

    /**
     * @param list<string>|null $localeFallbackChain
     * @return list<string>
     */
    private function normalizeLocaleChain(string $localeCode, ?array $localeFallbackChain): array
    {
        $chain = [];
        if ($localeFallbackChain !== null) {
            foreach ($localeFallbackChain as $locale) {
                $locale = \trim((string)$locale);
                if ($locale !== '') {
                    $chain[] = $locale;
                }
            }
        } else {
            if ($localeCode !== '') {
                $chain[] = $localeCode;
            }
            $chain[] = 'zh_Hans_CN';
        }

        return \array_values(\array_unique($chain));
    }
}
