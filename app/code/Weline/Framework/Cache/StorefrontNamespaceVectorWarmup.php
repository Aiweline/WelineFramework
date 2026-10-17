<?php

declare(strict_types=1);

namespace Weline\Framework\Cache;

use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\LocaleFallbackChain;

/**
 * Prefetch the Scope-stable storefront generation vector into the process snapshot.
 *
 * Used by WLS Worker READY (and Cron) so the first freezeCurrent / FPC decorate
 * does not pay a cold DB IN(...) for the base vector. Path Extra namespaces stay
 * out of this set — FPC composes them with an explicit policyPath.
 */
final class StorefrontNamespaceVectorWarmup
{
    /**
     * @return array{authority_clock:int,generations:array<string,int>}
     */
    public static function prefetchForReadyGate(): array
    {
        try {
            /** @var StorefrontCacheKeyContextResolver $resolver */
            $resolver = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class);
            /** @var NamespaceGenerationInterface $generations */
            $generations = ObjectManager::getInstance(NamespaceGenerationInterface::class);

            $defaultLocale = LocaleFallbackChain::websiteDefaultLocale();
            $locales = LocaleFallbackChain::candidates($defaultLocale, $defaultLocale);
            $namespaces = $resolver->namespacePaths('default', $locales);

            foreach (self::loadedWebsiteCodes() as $websiteCode) {
                if ($websiteCode === 'default') {
                    continue;
                }
                foreach ($resolver->namespacePaths($websiteCode, $locales) as $path) {
                    $namespaces[] = $path;
                }
            }

            return $generations->prefetchProcessVector(\array_values(\array_unique($namespaces)));
        } catch (\Throwable) {
            return [
                'authority_clock' => 0,
                'generations' => [],
            ];
        }
    }

    /** @return list<string> */
    private static function loadedWebsiteCodes(): array
    {
        $codes = [];
        try {
            foreach (Url::$parserSites as $site) {
                if (!\is_array($site)) {
                    continue;
                }
                $code = \trim((string)($site['code'] ?? ''));
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
        } catch (\Throwable) {
        }

        return \array_keys($codes);
    }
}
