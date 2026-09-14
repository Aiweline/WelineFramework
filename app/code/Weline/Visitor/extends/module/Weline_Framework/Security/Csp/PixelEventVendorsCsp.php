<?php

declare(strict_types=1);

namespace Weline\Visitor\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Interface\PixelEventVendorInterface;
use Weline\Visitor\Service\PixelEventVendorScanner;

/**
 * Aggregate CSP from all PixelEventVendor providers into Framework app defaults.
 *
 * Zero-arg constructor required by CspSourceContributionRegistry (Extends).
 *
 * @param (callable():iterable<PixelEventVendorInterface>)|null $providersResolver test seam
 */
final class PixelEventVendorsCsp implements CspSourceContributionProviderInterface
{
    /** @var (callable():iterable<PixelEventVendorInterface>)|null */
    private $providersResolver;

    public function __construct(?callable $providersResolver = null)
    {
        $this->providersResolver = $providersResolver;
    }

    public function contribution(): CspSourceContribution
    {
        $merged = [];
        foreach ($this->providers() as $provider) {
            if (!$provider instanceof PixelEventVendorInterface) {
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

    /** @return iterable<PixelEventVendorInterface> */
    private function providers(): iterable
    {
        if ($this->providersResolver !== null) {
            return ($this->providersResolver)();
        }
        try {
            $scanner = ObjectManager::getInstance(PixelEventVendorScanner::class);
        } catch (\Throwable) {
            return [];
        }
        if (!$scanner instanceof PixelEventVendorScanner) {
            return [];
        }

        return $scanner->getProviderInstances();
    }
}
