<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Service\Query\QueryProviderRegistry;

final class QueryProviderRegistryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-query-registry-' . bin2hex(random_bytes(6));
        if (!mkdir($concurrentDirectory = $this->tempDir, 0777, true) && !is_dir($concurrentDirectory)) {
            self::fail('Failed to create temp directory for QueryProviderRegistry tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testResolveProviderNameFromSourceFileReadsLiteralProviderName(): void
    {
        [, $providerFile] = $this->createProviderFixture('literal_provider');

        $registry = new QueryProviderRegistry();
        $method = new \ReflectionMethod(QueryProviderRegistry::class, 'resolveProviderNameFromSourceFile');
        $method->setAccessible(true);

        self::assertSame('literal_provider', $method->invoke($registry, $providerFile));
    }

    public function testGetProviderInstantiatesOnlyRequestedDefinition(): void
    {
        $markerFile = $this->tempDir . DIRECTORY_SEPARATOR . 'provider-loads.log';
        [$heavyClass, $heavyFile] = $this->createProviderFixture('heavy', $markerFile, 'heavy');
        [$analyticsClass, $analyticsFile] = $this->createProviderFixture('analytics', $markerFile, 'analytics');

        $registry = new QueryProviderRegistry();
        $this->setPrivateProperty($registry, 'definitionsLoaded', true);
        $this->setPrivateProperty($registry, 'providerDefinitions', [
            'heavy' => [
                'class_name' => $heavyClass,
                'source_file' => $heavyFile,
            ],
            'analytics' => [
                'class_name' => $analyticsClass,
                'source_file' => $analyticsFile,
            ],
        ]);
        $this->setPrivateProperty($registry, 'providers', []);
        $this->setPrivateProperty($registry, 'deferredDefinitions', []);

        $provider = $registry->getProvider('analytics');

        self::assertInstanceOf(QueryProviderInterface::class, $provider);
        self::assertSame('analytics', $provider?->getProviderName());
        self::assertSame("analytics\n", file_get_contents($markerFile));

        $providers = $this->getPrivateProperty($registry, 'providers');
        self::assertArrayHasKey('analytics', $providers);
        self::assertArrayNotHasKey('heavy', $providers);

        $definitions = $this->getPrivateProperty($registry, 'providerDefinitions');
        self::assertArrayHasKey('heavy', $definitions);
    }

    public function testGetProviderResolvesDeferredDynamicProviderWithoutInstantiatingNamedDefinitions(): void
    {
        $markerFile = $this->tempDir . DIRECTORY_SEPARATOR . 'provider-loads-dynamic.log';
        [$heavyClass, $heavyFile] = $this->createProviderFixture('heavy_literal', $markerFile, 'heavy_literal');
        [$dynamicClass, $dynamicFile] = $this->createProviderFixture(
            'dynamic_provider',
            $markerFile,
            'dynamic_provider',
            true
        );

        $registry = new QueryProviderRegistry();
        $this->setPrivateProperty($registry, 'definitionsLoaded', true);
        $this->setPrivateProperty($registry, 'providerDefinitions', [
            'heavy_literal' => [
                'class_name' => $heavyClass,
                'source_file' => $heavyFile,
            ],
        ]);
        $this->setPrivateProperty($registry, 'providers', []);
        $this->setPrivateProperty($registry, 'deferredDefinitions', [[
            'class_name' => $dynamicClass,
            'source_file' => $dynamicFile,
        ]]);

        $provider = $registry->getProvider('dynamic_provider');

        self::assertInstanceOf(QueryProviderInterface::class, $provider);
        self::assertSame('dynamic_provider', $provider?->getProviderName());
        self::assertSame("dynamic_provider\n", file_get_contents($markerFile));

        $providers = $this->getPrivateProperty($registry, 'providers');
        self::assertArrayHasKey('dynamic_provider', $providers);
        self::assertArrayNotHasKey('heavy_literal', $providers);

        $definitions = $this->getPrivateProperty($registry, 'providerDefinitions');
        self::assertArrayHasKey('heavy_literal', $definitions);

        $deferredDefinitions = $this->getPrivateProperty($registry, 'deferredDefinitions');
        self::assertSame([], $deferredDefinitions);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function createProviderFixture(
        string $providerName,
        ?string $markerFile = null,
        ?string $markerValue = null,
        bool $dynamicProviderName = false
    ): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $namespace = 'Weline\\Framework\\Test\\Unit\\Service\\Query\\Temp' . $suffix;
        $class = 'TempProvider' . $suffix;
        $fqcn = $namespace . '\\' . $class;
        $file = $this->tempDir . DIRECTORY_SEPARATOR . $class . '.php';

        $constructorBody = '';
        if ($markerFile !== null && $markerValue !== null) {
            $constructorBody = "    public function __construct()\n    {\n        file_put_contents(" . var_export($markerFile, true) . ', ' . var_export($markerValue . "\n", true) . ", FILE_APPEND);\n    }\n\n";
        }

        $providerNameMethod = $dynamicProviderName
            ? "    public function getProviderName(): string\n    {\n        \$providerName = '{$providerName}';\n        return \$providerName;\n    }\n"
            : "    public function getProviderName(): string\n    {\n        return '{$providerName}';\n    }\n";

        $source = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class {$class} implements QueryProviderInterface
{
{$constructorBody}{$providerNameMethod}

    public function execute(string \$operation, array \$params = []): mixed
    {
        return ['operation' => \$operation, 'params' => \$params];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => '{$providerName}',
            'name' => '{$providerName}',
            'description' => 'temp provider',
            'module' => 'Test_Module',
            'operations' => [],
        ];
    }
}
PHP;

        file_put_contents($file, $source);

        return [$fqcn, $file];
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function getPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($object);
    }

    private function deleteDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
