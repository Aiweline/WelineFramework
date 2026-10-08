<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * Loads {@see StorefrontPagePrefetchContributionInterface} from module provides.
 */
final class StorefrontPagePrefetchContributionRegistry
{
    /** @var list<StorefrontPagePrefetchContributionInterface>|null */
    private ?array $contributions = null;

    public function __construct(
        private readonly bool $autoloadCompiled = true,
    ) {
    }

    /**
     * @return int logical keys primed across all contributions
     */
    public function primeBeforeLayoutFetch(?Template $template = null, string ...$templateRefs): int
    {
        $total = 0;
        foreach ($this->all() as $contribution) {
            try {
                $total += $contribution->primeBeforeLayoutFetch($template, ...$templateRefs);
            } catch (\Throwable) {
                continue;
            }
        }

        return $total;
    }

    /**
     * @param list<array{type:string,name:string,code:string,module:string}> $specs
     */
    public function prefetchInlineWidgetAssets(array $specs): int
    {
        if ($specs === []) {
            return 0;
        }
        $total = 0;
        foreach ($this->all() as $contribution) {
            try {
                $total += $contribution->prefetchInlineWidgetAssets($specs);
            } catch (\Throwable) {
                continue;
            }
        }

        return $total;
    }

    /**
     * @return list<StorefrontPagePrefetchContributionInterface>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->contributions ?? [];
    }

    private function ensureLoaded(): void
    {
        if ($this->contributions !== null) {
            return;
        }
        $this->contributions = [];
        if (!$this->autoloadCompiled) {
            return;
        }
        try {
            $serviceProviders = ObjectManager::getInstance(ServiceProviderRegistry::class);
            if (!$serviceProviders instanceof ServiceProviderRegistry) {
                return;
            }
            $prefix = StorefrontPagePrefetchContributionInterface::CAPABILITY_PREFIX;
            foreach ($serviceProviders->implementationsWithPrefix($prefix) as $implementation) {
                try {
                    $instance = ObjectManager::getInstance($implementation);
                    if ($instance instanceof StorefrontPagePrefetchContributionInterface) {
                        $this->contributions[] = $instance;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        } catch (\Throwable) {
        }
    }
}
