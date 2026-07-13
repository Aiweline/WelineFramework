<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Compilation;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\ServiceProviderRegistry;

final class ServiceProviderRegistryTest extends TestCase
{
    private string $registryFile;

    protected function setUp(): void
    {
        $this->registryFile = sys_get_temp_dir() . '/weline-provider-registry-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->registryFile)) {
            unlink($this->registryFile);
        }
    }

    public function testResolvesContractFromCompiledModuleOrder(): void
    {
        $registry = [
            'format' => 1,
            'order' => ['Weline_Framework', 'Weline_Api'],
            'modules' => [
                'Weline_Framework' => ['provides' => []],
                'Weline_Api' => ['provides' => ['Contract\\Auth' => 'Provider\\Auth']],
            ],
        ];
        file_put_contents($this->registryFile, '<?php return ' . var_export($registry, true) . ';');

        $providers = new ServiceProviderRegistry($this->registryFile);

        self::assertSame('Provider\\Auth', $providers->implementationFor('Contract\\Auth'));
        self::assertNull($providers->implementationFor('Contract\\Missing'));
    }

    public function testRejectsConflictingProviders(): void
    {
        $registry = [
            'format' => 1,
            'order' => ['Module_A', 'Module_B'],
            'modules' => [
                'Module_A' => ['provides' => ['Contract\\Auth' => 'Provider\\A']],
                'Module_B' => ['provides' => ['Contract\\Auth' => 'Provider\\B']],
            ],
        ];
        file_put_contents($this->registryFile, '<?php return ' . var_export($registry, true) . ';');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Multiple implementations provide');

        (new ServiceProviderRegistry($this->registryFile))->all();
    }
}
