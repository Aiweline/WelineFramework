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
            $result = [
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
            $result['compile_manifest'] = (new FrameworkCompileManifest())->write(
                $modulesRoot,
                $outputDirectory,
            );
            return $result;
        } finally {
            // server:start may fork/exec after control-plane compilation. Do
            // not let a compile lock descriptor escape into Master/Workers.
            AtomicCompiledFilePublisher::releaseProcessLocks();
        }
    }
}
