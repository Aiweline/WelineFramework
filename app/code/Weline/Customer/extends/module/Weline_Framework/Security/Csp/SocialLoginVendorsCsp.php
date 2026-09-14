<?php

declare(strict_types=1);

namespace Weline\Customer\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Customer\Interface\SocialLoginProviderInterface;
use Weline\Customer\Service\SocialLogin\SocialLoginProviderCatalog;
use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Aggregate CSP from all registered social-login vendors into Framework app defaults.
 *
 * Zero-arg constructor required by CspSourceContributionRegistry (Extends).
 *
 * @param (callable():iterable<SocialLoginProviderInterface>)|null $providersResolver test seam
 */
final class SocialLoginVendorsCsp implements CspSourceContributionProviderInterface
{
    /** @var (callable():iterable<SocialLoginProviderInterface>)|null */
    private $providersResolver;

    public function __construct(?callable $providersResolver = null)
    {
        $this->providersResolver = $providersResolver;
    }

    public function contribution(): CspSourceContribution
    {
        $merged = [];
        foreach ($this->providers() as $provider) {
            if (!$provider instanceof SocialLoginProviderInterface) {
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

    /** @return iterable<SocialLoginProviderInterface> */
    private function providers(): iterable
    {
        if ($this->providersResolver !== null) {
            return ($this->providersResolver)();
        }
        try {
            $catalog = ObjectManager::getInstance(SocialLoginProviderCatalog::class);
        } catch (\Throwable) {
            return [];
        }
        if (!$catalog instanceof SocialLoginProviderCatalog) {
            return [];
        }

        return $catalog->providers();
    }
}
