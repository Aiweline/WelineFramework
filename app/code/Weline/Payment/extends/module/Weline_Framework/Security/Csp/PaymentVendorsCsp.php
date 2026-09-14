<?php

declare(strict_types=1);

namespace Weline\Payment\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Service\PaymentProviderScanner;

/**
 * Aggregate CSP from all registered payment vendors into Framework app defaults.
 *
 * Zero-arg constructor required by CspSourceContributionRegistry (Extends).
 *
 * @param (callable():iterable<ProviderInterface>)|null $providersResolver test seam
 */
final class PaymentVendorsCsp implements CspSourceContributionProviderInterface
{
    /** @var (callable():iterable<ProviderInterface>)|null */
    private $providersResolver;

    public function __construct(?callable $providersResolver = null)
    {
        $this->providersResolver = $providersResolver;
    }

    public function contribution(): CspSourceContribution
    {
        $merged = [];
        foreach ($this->providers() as $provider) {
            if (!$provider instanceof ProviderInterface) {
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

    /** @return iterable<ProviderInterface> */
    private function providers(): iterable
    {
        if ($this->providersResolver !== null) {
            return ($this->providersResolver)();
        }
        try {
            $scanner = ObjectManager::getInstance(PaymentProviderScanner::class);
        } catch (\Throwable) {
            return [];
        }
        if (!$scanner instanceof PaymentProviderScanner) {
            return [];
        }

        return $scanner->getProviderInstances();
    }
}
