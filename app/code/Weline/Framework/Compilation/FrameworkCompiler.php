<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Policy\RuntimePolicyProviderCompiler;
use Weline\Framework\Service\Query\QueryProviderCompiler;
use Weline\Framework\View\Cache\TemplateCachePolicyCompiler;

final class FrameworkCompiler
{
    public function __construct(
        private readonly ModuleRegistryCompiler $moduleRegistryCompiler,
        private readonly QueryProviderCompiler $queryProviderCompiler,
        private readonly RuntimePolicyProviderCompiler $runtimePolicyProviderCompiler = new RuntimePolicyProviderCompiler(),
        private readonly TemplateCachePolicyCompiler $templateCachePolicyCompiler = new TemplateCachePolicyCompiler(),
        private readonly ContainerCompiler $containerCompiler = new ContainerCompiler(),
        private readonly FrameworkCompileManifest $compileManifest = new FrameworkCompileManifest(),
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function compile(string $modulesRoot, string $outputDirectory): array
    {
        $outputDirectory = rtrim($outputDirectory, '/\\');
        $publisher = new AtomicCompiledFilePublisher();
        $outputLockAcquiredHere = $publisher->acquireDirectoryLock($outputDirectory, \LOCK_EX);
        $previousProviderRegistry = null;
        $providerRegistryInstalled = false;
        try {
            $hooksFile = \dirname($outputDirectory) . DS . 'hooks.php';
            $sourceBefore = $this->compileManifest->capture($modulesRoot, $hooksFile);
            $modules = $this->moduleRegistryCompiler->compile(
                $modulesRoot,
                $outputDirectory . DS . 'modules.php',
            );
            $previousProviderRegistry = ObjectManager::replaceServiceProviderRegistry(
                new ServiceProviderRegistry($outputDirectory . DS . 'modules.php'),
            );
            $providerRegistryInstalled = true;
            $compiled = [
                'modules' => $modules,
                'container' => $this->containerCompiler->compile(
                    $outputDirectory . DS . 'container.php',
                ),
                'query_providers' => $this->queryProviderCompiler->compile(
                    $outputDirectory . DS . 'query_providers.php',
                ),
                'runtime_policy_providers' => $this->runtimePolicyProviderCompiler->compile(
                    $modules,
                    $outputDirectory . DS . 'runtime_policy_providers.php',
                ),
                'template_cache_policies' => $this->templateCachePolicyCompiler->compile(
                    $modules,
                    $outputDirectory . DS . 'template_cache_policies.php',
                    $hooksFile,
                ),
            ];

            $sourceAfter = $this->compileManifest->capture(
                $modulesRoot,
                $hooksFile,
                (array)($sourceBefore['sources'] ?? []),
            );
            if (!$this->compileManifest->sameSourceState($sourceBefore, $sourceAfter)) {
                throw new \RuntimeException('Framework compiler inputs changed during compilation.');
            }
            $compiled['compile_manifest'] = $this->compileManifest->write(
                $sourceAfter,
                $outputDirectory,
            );

            return $compiled;
        } finally {
            if ($providerRegistryInstalled) {
                ObjectManager::replaceServiceProviderRegistry($previousProviderRegistry);
            }
            // Never release an outer caller's same-directory generation lock.
            if ($outputLockAcquiredHere) {
                AtomicCompiledFilePublisher::releaseDirectoryLock($outputDirectory);
            }
        }
    }

    public function isFresh(string $modulesRoot, string $outputDirectory): bool
    {
        return $this->compileManifest->isFresh(
            $modulesRoot,
            rtrim($outputDirectory, '/\\'),
        );
    }

    public function isPublishedGenerationValid(
        string $modulesRoot,
        string $outputDirectory,
        string $hooksFile,
    ): bool {
        return $this->compileManifest->isPublishedGenerationValid(
            $modulesRoot,
            rtrim($outputDirectory, '/\\'),
            $hooksFile,
        );
    }
}
