<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Scope\PhraseScopeValue;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Api\Translation\TranslationResolverInterface;

final class TranslationResolver implements TranslationResolverInterface
{
    /** @var array<string, array<string, string>> */
    private array $moduleWords = [];

    public function __construct(
        private readonly ?PhraseScopeResolver $phraseScopeResolver = null,
        private readonly ?DictionaryRepositoryInterface $dictionaryRepository = null,
    ) {
    }

    public function translate(string $source, string $localeCode, array $preferredModules = []): string
    {
        $source = \trim($source);
        if ($source === '') {
            return '';
        }

        foreach (\array_values(\array_unique($preferredModules)) as $moduleName) {
            $words = $this->moduleWords((string)$moduleName, $localeCode);
            if (!isset($words[$source]) || $words[$source] === '') {
                continue;
            }
            $translated = $words[$source];
            // Latin identity (Hanfu,Hanfu) is an explicit same-text translation.
            // CJK identity in a non-zh locale is an untranslated collect placeholder
            // and must not mask later modules or the public dictionary.
            if ($this->isUsableModuleTranslation($source, $translated, $localeCode)) {
                return $translated;
            }
        }

        $dictionary = $this->getDictionaryRepository();
        if ($dictionary !== null) {
            $entry = $dictionary->getEntry($source, $localeCode);
            if ($entry !== null) {
                $translated = \trim((string)$entry->translation);
                if ($translated !== '' && $this->isUsableModuleTranslation($source, $translated, $localeCode)) {
                    return $translated;
                }
                if ($translated !== '' && $translated !== $source) {
                    return $translated;
                }
            }
        }

        // Prefer explicit locale lookup over global __(), which can follow a lagging
        // RequestContext/KeyBuilder language and reverse-translate EN display strings.
        return $source;
    }

    public function reset(): void
    {
        $this->moduleWords = [];
    }

    public function translateForScope(
        string $source,
        ScopeIdentity $identity,
        string $localeCode,
        array $preferredModules = [],
        ?array $localeFallbackChain = null,
    ): PhraseScopeValue {
        $resolver = $this->getPhraseScopeResolver();

        return $resolver->resolve(
            source: $source,
            identity: $identity,
            localeCode: $localeCode,
            lookup: function (string $word, string $locale) use ($preferredModules): ?string {
                foreach (\array_values(\array_unique($preferredModules)) as $moduleName) {
                    $words = $this->moduleWords((string)$moduleName, $locale);
                    if (!isset($words[$word]) || $words[$word] === '') {
                        continue;
                    }
                    if ($this->isUsableModuleTranslation($word, $words[$word], $locale)) {
                        return $words[$word];
                    }
                }
                $dictionary = $this->getDictionaryRepository();
                if ($dictionary === null) {
                    return null;
                }
                $entry = $dictionary->getEntry($word, $locale);
                if ($entry === null) {
                    return null;
                }
                $translated = \trim((string)$entry->translation);
                if ($translated === '' || $translated === $word) {
                    return null;
                }

                return $translated;
            },
            localeFallbackChain: $localeFallbackChain,
            unscopedFallback: function (string $plain, string $locale) use ($preferredModules): string {
                return $this->translate($plain, $locale, $preferredModules);
            },
        );
    }

    /** @return array<string, string> */
    private function moduleWords(string $moduleName, string $localeCode): array
    {
        $cacheKey = DictionaryCacheNamespace::cacheKey($localeCode . '|' . $moduleName);
        if (isset(DictionaryCacheNamespace::localCache($this->moduleWords, 2048)[$cacheKey])) {
            return DictionaryCacheNamespace::localCache($this->moduleWords, 2048)[$cacheKey];
        }

        $words = [];
        try {
            $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
            $csvFile = (string)($moduleInfo['base_path'] ?? '') . '/i18n/' . $localeCode . '.csv';
            if (!\is_file($csvFile)) {
                return DictionaryCacheNamespace::localCache($this->moduleWords, 2048)[$cacheKey] = [];
            }

            $handle = @\fopen($csvFile, 'r');
            if ($handle === false) {
                return DictionaryCacheNamespace::localCache($this->moduleWords, 2048)[$cacheKey] = [];
            }
            try {
                while (($data = \fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                    if (!isset($data[0], $data[1])) {
                        continue;
                    }
                    $key = \trim((string)$data[0]);
                    $value = \trim((string)$data[1]);
                    // The loader must retain identity entries so translate() can
                    // distinguish an explicit same-text translation from a miss.
                    if ($key !== '' && $value !== '') {
                        $words[$key] = $value;
                    }
                }
            } finally {
                \fclose($handle);
            }
        } catch (\Throwable) {
        }

        return DictionaryCacheNamespace::localCache($this->moduleWords, 2048)[$cacheKey] = $words;
    }

    private function isUsableModuleTranslation(string $source, string $translated, string $localeCode): bool
    {
        $translated = \trim($translated);
        if ($translated === '') {
            return false;
        }
        if ($translated !== $source) {
            return true;
        }
        if ($this->isChineseLocale($localeCode)) {
            return true;
        }

        return !$this->containsCjk($source);
    }

    private function isChineseLocale(string $localeCode): bool
    {
        return \str_starts_with(\strtolower(\str_replace('-', '_', $localeCode)), 'zh');
    }

    private function containsCjk(string $text): bool
    {
        return \preg_match('/[\x{4e00}-\x{9fff}]/u', $text) === 1;
    }

    private function getPhraseScopeResolver(): PhraseScopeResolver
    {
        if ($this->phraseScopeResolver !== null) {
            return $this->phraseScopeResolver;
        }

        /** @var PhraseScopeResolver $resolver */
        $resolver = ObjectManager::getInstance(PhraseScopeResolver::class);

        return $resolver;
    }

    private function getDictionaryRepository(): ?DictionaryRepositoryInterface
    {
        if ($this->dictionaryRepository !== null) {
            return $this->dictionaryRepository;
        }
        try {
            /** @var DictionaryRepositoryInterface $repo */
            $repo = ObjectManager::getInstance(DictionaryRepositoryInterface::class);

            return $repo;
        } catch (\Throwable) {
            return null;
        }
    }
}
