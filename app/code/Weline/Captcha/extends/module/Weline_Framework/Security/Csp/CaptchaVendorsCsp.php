<?php

declare(strict_types=1);

namespace Weline\Captcha\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Captcha\Interface\VerificationProviderInterface;
use Weline\Captcha\Service\CaptchaProviderRegistry;
use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Aggregate CSP from all registered captcha vendors into Framework app defaults.
 *
 * Zero-arg constructor required by CspSourceContributionRegistry (Extends).
 *
 * @param (callable():iterable<VerificationProviderInterface>)|null $providersResolver test seam
 */
final class CaptchaVendorsCsp implements CspSourceContributionProviderInterface
{
    /** @var (callable():iterable<VerificationProviderInterface>)|null */
    private $providersResolver;

    public function __construct(?callable $providersResolver = null)
    {
        $this->providersResolver = $providersResolver;
    }

    public function contribution(): CspSourceContribution
    {
        $merged = [];
        foreach ($this->providers() as $provider) {
            if (!$provider instanceof VerificationProviderInterface) {
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

    /** @return iterable<VerificationProviderInterface> */
    private function providers(): iterable
    {
        if ($this->providersResolver !== null) {
            return ($this->providersResolver)();
        }
        try {
            $registry = ObjectManager::getInstance(CaptchaProviderRegistry::class);
        } catch (\Throwable) {
            return [];
        }
        if (!$registry instanceof CaptchaProviderRegistry) {
            return [];
        }

        return $registry->all();
    }
}
