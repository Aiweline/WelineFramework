<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

use Weline\Framework\Manager\ObjectManager;

/**
 * 编译产物自愈：先验世代，失效则重建一次并复验。
 *
 * 只允许在控制面（CLI / Worker 启动）调用；Web 请求内不得调用（编译耗时不可控）。
 * 编译本身由 FrameworkCompiler 的目录锁串行化，多个入口同时自愈不会并发写产物。
 */
final class GenerationRepairService
{
    /** @var callable(string, string): bool */
    private $isValid;

    /** @var callable(string, string): mixed */
    private $compile;

    public function __construct(?callable $isValid = null, ?callable $compile = null)
    {
        $this->isValid = $isValid ?? static function (string $modulesRoot, string $outputDirectory): bool {
            return (new FrameworkCompileManifest())->isPublishedGenerationValid(
                $modulesRoot,
                $outputDirectory,
                \dirname($outputDirectory) . DS . 'hooks.php',
            );
        };
        $this->compile = $compile ?? static function (string $modulesRoot, string $outputDirectory): mixed {
            // FrameworkCompiler 有必需依赖，必须走容器解析（无参 new 会 TypeError）。
            return ObjectManager::getInstance(FrameworkCompiler::class)->compile($modulesRoot, $outputDirectory);
        };
    }

    /**
     * @return array{repaired:bool, reason:string, error:?string}
     */
    public function ensure(string $modulesRoot, string $outputDirectory): array
    {
        if (($this->isValid)($modulesRoot, $outputDirectory)) {
            return ['repaired' => false, 'reason' => 'valid', 'error' => null];
        }

        try {
            ($this->compile)($modulesRoot, $outputDirectory);
        } catch (\Throwable $e) {
            return [
                'repaired' => false,
                'reason' => 'compile_failed',
                'error' => $e->getMessage(),
            ];
        }

        if (($this->isValid)($modulesRoot, $outputDirectory)) {
            return ['repaired' => true, 'reason' => 'repaired', 'error' => null];
        }

        return ['repaired' => false, 'reason' => 'post_check_failed', 'error' => null];
    }
}
