<?php

declare(strict_types=1);

namespace Weline\Social\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Interface\SocialPlatformProviderInterface;
use Weline\Social\Service\SocialPlatformRegistry;

/**
 * Aggregate CSP from all registered social/media platform providers.
 *
 * Zero-arg constructor required by CspSourceContributionRegistry (Extends).
 *
 * @param (callable():iterable<SocialPlatformProviderInterface>)|null $providersResolver test seam
 */
final class SocialPlatformsCsp implements CspSourceContributionProviderInterface
{
    /** @var (callable():iterable<SocialPlatformProviderInterface>)|null */
    private $providersResolver;

    public function __construct(?callable $providersResolver = null)
    {
        $this->providersResolver = $providersResolver;
    }

    public function contribution(): CspSourceContribution
    {
        $merged = [];
        foreach ($this->providers() as $provider) {
            if (!$provider instanceof SocialPlatformProviderInterface) {
                continue;
            }
            foreach ($provider->cspDirectives() as $directive => $sources) {
                $name = \strtolower(\trim((string)$directive));
                if ($name === '' || !\is_array($sources)) {
                    continue;
                }
                foreach ($sources as $src) {
                    $src = \trim((string)$src);
                    if ($src === '') {
                        continue;
                    }
                    $merged[$name][$src] = true;
                }
            }
        }

        $directives = [];
        foreach ($merged as $name => $map) {
            $list = \array_keys($map);
            \sort($list, \SORT_STRING);
            $directives[$name] = $list;
        }

        return new CspSourceContribution($directives);
    }

    /** @return iterable<SocialPlatformProviderInterface> */
    private function providers(): iterable
    {
        if ($this->providersResolver !== null) {
            return ($this->providersResolver)();
        }
        try {
            $registry = ObjectManager::getInstance(SocialPlatformRegistry::class);
        } catch (\Throwable) {
            return [];
        }
        if (!$registry instanceof SocialPlatformRegistry) {
            return [];
        }

        return \array_values($registry->getProviders());
    }
}
