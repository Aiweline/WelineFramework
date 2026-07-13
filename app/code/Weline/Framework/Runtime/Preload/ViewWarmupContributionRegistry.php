<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

use Weline\Framework\Compilation\ServiceProviderRegistry;

/**
 * Resolves the compiled provider index once per process and returns one
 * immutable aggregate. No directory scanning or request-path lookup occurs.
 */
final class ViewWarmupContributionRegistry
{
    private ?ViewWarmupContribution $aggregate = null;

    public function __construct(
        private readonly ServiceProviderRegistry $providers,
    ) {
    }

    public function aggregate(): ViewWarmupContribution
    {
        if ($this->aggregate instanceof ViewWarmupContribution) {
            return $this->aggregate;
        }

        $templates = [];
        $tagTemplates = [];
        $staticFiles = [];
        $hookNames = [];
        $fpcPaths = [];
        foreach ($this->providers->implementationsWithPrefix(
            ViewWarmupContributionProviderInterface::CAPABILITY_PREFIX,
        ) as $capability => $implementation) {
            if (!\class_exists($implementation)
                || !\is_subclass_of($implementation, ViewWarmupContributionProviderInterface::class)
            ) {
                throw new \RuntimeException(
                    "View warmup provider {$capability} must implement "
                    . ViewWarmupContributionProviderInterface::class,
                );
            }
            $reflection = new \ReflectionClass($implementation);
            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw new \RuntimeException(
                    "View warmup provider {$capability} must have a zero-argument constructor.",
                );
            }

            /** @var ViewWarmupContributionProviderInterface $provider */
            $provider = $reflection->newInstance();

            $contribution = $provider->contribution();
            foreach ($contribution->templates as $template) {
                $templates[$template] = true;
            }
            foreach ($contribution->tagTemplates as $type => $sources) {
                foreach ($sources as $source) {
                    $tagTemplates[$type][$source] = true;
                }
            }
            foreach ($contribution->staticFiles as $path) {
                $staticFiles[$path] = true;
            }
            foreach ($contribution->hookNames as $hookName) {
                $hookNames[$hookName] = true;
            }
            foreach ($contribution->fpcPaths as $path) {
                $fpcPaths[$path] = true;
            }
        }

        $normalizedTagTemplates = [];
        foreach ($tagTemplates as $type => $sources) {
            $normalizedTagTemplates[$type] = \array_keys($sources);
        }

        return $this->aggregate = new ViewWarmupContribution(
            templates: \array_keys($templates),
            tagTemplates: $normalizedTagTemplates,
            staticFiles: \array_keys($staticFiles),
            hookNames: \array_keys($hookNames),
            fpcPaths: \array_keys($fpcPaths),
        );
    }
}
