<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

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
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function compile(string $modulesRoot, string $outputDirectory): array
    {
        try {
            $outputDirectory = rtrim($outputDirectory, '/\\');
            $modules = $this->moduleRegistryCompiler->compile(
                $modulesRoot,
                $outputDirectory . DS . 'modules.php',
            );
            return [
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
                    \dirname($outputDirectory) . DS . 'hooks.php',
                ),
            ];
        } finally {
            // server:start may fork/exec after control-plane compilation. Do
            // not let a compile lock descriptor escape into Master/Workers.
            AtomicCompiledFilePublisher::releaseProcessLocks();
        }
    }

    /**
     * POSIX fast-path: published generation usable without recompile.
     * Missing artifacts returns false and forces a fresh compile/promote.
     */
    public function isFresh(string $modulesRoot, string $outputDirectory): bool
    {
        unset($modulesRoot);
        return $this->hasPublishedArtifacts($outputDirectory);
    }

    /**
     * Windows fast-path: prove published artifacts exist without recursively
     * scanning the source tree (Defender-cold walks are prohibitively slow).
     */
    public function isPublishedGenerationValid(
        string $modulesRoot,
        string $outputDirectory,
        string $hookRegistry = '',
    ): bool {
        unset($modulesRoot);
        if (!$this->hasPublishedArtifacts($outputDirectory)) {
            return false;
        }
        if ($hookRegistry !== '' && !\is_file($hookRegistry)) {
            return false;
        }
        return true;
    }

    private function hasPublishedArtifacts(string $outputDirectory): bool
    {
        $root = \rtrim($outputDirectory, '/\\');
        if ($root === '' || !\is_dir($root)) {
            return false;
        }
        foreach ([
            'modules.php',
            'query_providers.php',
            'runtime_policy_providers.php',
            'template_cache_policies.php',
            'container.php',
        ] as $fileName) {
            if (!\is_file($root . DIRECTORY_SEPARATOR . $fileName)) {
                return false;
            }
        }
        return true;
    }
}
