<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\Di\ReflectionArtifactContract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\Provider\ObjectMetadataPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllManagerContractsPass().
 */
final class ManagerContractsTest extends TestCase
{
    public function testAllManagerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ObjectManagerReflectionArtifact::testAcceptCompiledFactoriesRejectsLegacyFlat' => function () {
            $this->setUp_ObjectManagerReflectionArtifact();
            try {
                $this->assert_ObjectManagerReflectionArtifact_testAcceptCompiledFactoriesRejectsLegacyFlat();
            } finally {
                $this->tearDown_ObjectManagerReflectionArtifact();
            }
        },
            'ObjectManagerReflectionArtifact::testAcceptCompiledFactoriesAcceptsV2Envelope' => function () {
            $this->setUp_ObjectManagerReflectionArtifact();
            try {
                $this->assert_ObjectManagerReflectionArtifact_testAcceptCompiledFactoriesAcceptsV2Envelope();
            } finally {
                $this->tearDown_ObjectManagerReflectionArtifact();
            }
        },
            'ObjectManagerReflectionArtifact::testAcceptReflectionMetadataRejectsLegacyFlat' => function () {
            $this->setUp_ObjectManagerReflectionArtifact();
            try {
                $this->assert_ObjectManagerReflectionArtifact_testAcceptReflectionMetadataRejectsLegacyFlat();
            } finally {
                $this->tearDown_ObjectManagerReflectionArtifact();
            }
        },
            'ObjectManagerReflectionArtifact::testPreloadRejectsLegacyFlatWithReasonAndProviderFails' => function () {
            $this->setUp_ObjectManagerReflectionArtifact();
            try {
                $this->assert_ObjectManagerReflectionArtifact_testPreloadRejectsLegacyFlatWithReasonAndProviderFails();
            } finally {
                $this->tearDown_ObjectManagerReflectionArtifact();
            }
        },
            'ObjectManagerReflectionArtifact::testPreloadAcceptsV2AndEmptyArgGetInstanceSkipsReflectionParse' => function () {
            $this->setUp_ObjectManagerReflectionArtifact();
            try {
                $this->assert_ObjectManagerReflectionArtifact_testPreloadAcceptsV2AndEmptyArgGetInstanceSkipsReflectionParse();
            } finally {
                $this->tearDown_ObjectManagerReflectionArtifact();
            }
        },
        ];
    }

private string $factoriesPath_ObjectManagerReflectionArtifact;

private string $metadataPath_ObjectManagerReflectionArtifact;

private ?string $factoriesBackup_ObjectManagerReflectionArtifact = null;

private ?string $metadataBackup_ObjectManagerReflectionArtifact = null;

private function setUp_ObjectManagerReflectionArtifact(): void
{
    $this->factoriesPath_ObjectManagerReflectionArtifact = BP . 'generated' . DIRECTORY_SEPARATOR . 'compiled_factories.php';
    $this->metadataPath_ObjectManagerReflectionArtifact = BP . 'generated' . DIRECTORY_SEPARATOR . 'reflection_metadata.php';
    $this->factoriesBackup_ObjectManagerReflectionArtifact = \is_file($this->factoriesPath_ObjectManagerReflectionArtifact) ? \file_get_contents($this->factoriesPath_ObjectManagerReflectionArtifact) : null;
    $this->metadataBackup_ObjectManagerReflectionArtifact = \is_file($this->metadataPath_ObjectManagerReflectionArtifact) ? \file_get_contents($this->metadataPath_ObjectManagerReflectionArtifact) : null;
    Runtime::setMode(RuntimeInterface::MODE_WLS);
    ObjectManager::clearInstances();
    ObjectManager::relieveMemoryPressure(true);
    ObjectManager::resetParseMethodParamsMetadataCallCount();
    $this->resetPreloadState_ObjectManagerReflectionArtifact();
}

private function tearDown_ObjectManagerReflectionArtifact(): void
{
    $this->restoreArtifact_ObjectManagerReflectionArtifact($this->factoriesPath_ObjectManagerReflectionArtifact, $this->factoriesBackup_ObjectManagerReflectionArtifact);
    $this->restoreArtifact_ObjectManagerReflectionArtifact($this->metadataPath_ObjectManagerReflectionArtifact, $this->metadataBackup_ObjectManagerReflectionArtifact);
    Runtime::resetModeCache();
    ObjectManager::clearInstances();
    ObjectManager::relieveMemoryPressure(true);
    $this->resetPreloadState_ObjectManagerReflectionArtifact();
}

private function assert_ObjectManagerReflectionArtifact_testAcceptCompiledFactoriesRejectsLegacyFlat(): void
{
    $result = ReflectionArtifactContract::acceptCompiledFactoriesPayload(['Weline\Framework\Ui\FormKey' => static fn() => new \stdClass()]);
    self::assertFalse($result['ok']);
    self::assertSame(ReflectionArtifactContract::REJECT_LEGACY_FLAT, $result['reason']);
}

private function assert_ObjectManagerReflectionArtifact_testAcceptCompiledFactoriesAcceptsV2Envelope(): void
{
    $factories = [ObjectManagerReflectionArtifactLeafFixture::class => static fn() => new ObjectManagerReflectionArtifactLeafFixture()];
    $signature = ReflectionArtifactContract::computeSourceSignature(ObjectManagerReflectionArtifactLeafFixture::class, null, true);
    self::assertNotNull($signature);
    $result = ReflectionArtifactContract::acceptCompiledFactoriesPayload(['format' => ReflectionArtifactContract::COMPILED_FACTORIES_FORMAT, 'php_version_id' => \PHP_VERSION_ID, 'factories' => $factories, 'source_signatures' => [ObjectManagerReflectionArtifactLeafFixture::class => $signature]]);
    self::assertTrue($result['ok']);
    self::assertArrayHasKey(ObjectManagerReflectionArtifactLeafFixture::class, $result['factories']);
}

private function assert_ObjectManagerReflectionArtifact_testAcceptReflectionMetadataRejectsLegacyFlat(): void
{
    $result = ReflectionArtifactContract::acceptReflectionMetadataPayload([ObjectManagerReflectionArtifactLeafFixture::class . '::__construct' => ['className' => ObjectManagerReflectionArtifactLeafFixture::class, 'methodName' => '__construct', 'params' => []]]);
    self::assertFalse($result['ok']);
    self::assertSame(ReflectionArtifactContract::REJECT_LEGACY_FLAT, $result['reason']);
}

private function assert_ObjectManagerReflectionArtifact_testPreloadRejectsLegacyFlatWithReasonAndProviderFails(): void
{
    $this->writeFile_ObjectManagerReflectionArtifact($this->factoriesPath_ObjectManagerReflectionArtifact, "<?php\nreturn ['Std' => static fn() => new \\stdClass()];\n");
    $this->writeFile_ObjectManagerReflectionArtifact($this->metadataPath_ObjectManagerReflectionArtifact, "<?php\nreturn ['Std::__construct' => ['className' => 'Std', 'methodName' => '__construct', 'params' => []]];\n");
    $this->resetPreloadState_ObjectManagerReflectionArtifact();
    $stats = ObjectManager::preloadRuntimeMetadata();
    self::assertSame(0, $stats['compiled_factories']);
    self::assertSame(0, $stats['reflection_metadata']);
    self::assertSame(ReflectionArtifactContract::REJECT_LEGACY_FLAT, $stats['compiled_factories_reject_reason']);
    self::assertSame(ReflectionArtifactContract::REJECT_LEGACY_FLAT, $stats['reflection_metadata_reject_reason']);
    $provider = new ObjectMetadataPreloadProvider();
    $result = $provider->preload(new WorkerPreloadContext(WorkerPreloadContext::PHASE_BOOTSTRAP, 'worker', 0, 'test', \getmypid() ?: 0, RuntimeInterface::MODE_WLS));
    self::assertSame('failed', $result->status());
    self::assertStringContainsString('legacy_flat', $result->message());
}

private function assert_ObjectManagerReflectionArtifact_testPreloadAcceptsV2AndEmptyArgGetInstanceSkipsReflectionParse(): void
{
    $leaf = ObjectManagerReflectionArtifactLeafFixture::class;
    $root = ObjectManagerReflectionArtifactRootFixture::class;
    $leafSignature = ReflectionArtifactContract::computeSourceSignature($leaf, null, true);
    $rootSignature = ReflectionArtifactContract::computeSourceSignature($root, null, true);
    $rootMetaSignature = ReflectionArtifactContract::computeSourceSignature($root, '__construct', false);
    self::assertNotNull($leafSignature);
    self::assertNotNull($rootSignature);
    self::assertNotNull($rootMetaSignature);
    $factoriesPhp = "<?php\nreturn [\n" . "    'format' => '" . ReflectionArtifactContract::COMPILED_FACTORIES_FORMAT . "',\n" . "    'php_version_id' => " . \PHP_VERSION_ID . ",\n" . "    'factories' => [\n" . "        '" . \str_replace('\\', '\\\\', $leaf) . "' => static fn() => new \\{$leaf}(),\n" . "        '" . \str_replace('\\', '\\\\', $root) . "' => static fn() => new \\{$root}(" . "\\Weline\\Framework\\Manager\\ObjectManager::getInstance('" . \str_replace('\\', '\\\\', $leaf) . "')),\n" . "    ],\n" . "    'source_signatures' => [\n" . "        '" . \str_replace('\\', '\\\\', $leaf) . "' => '{$leafSignature}',\n" . "        '" . \str_replace('\\', '\\\\', $root) . "' => '{$rootSignature}',\n" . "    ],\n" . "];\n";
    $this->writeFile_ObjectManagerReflectionArtifact($this->factoriesPath_ObjectManagerReflectionArtifact, $factoriesPhp);
    $metadataEnvelope = ['format' => ReflectionArtifactContract::REFLECTION_METADATA_FORMAT, 'php_version_id' => \PHP_VERSION_ID, 'metadata' => [$root . '::__construct' => ['className' => $root, 'methodName' => '__construct', 'params' => [['index' => 0, 'name' => 'leaf', 'typeName' => $leaf, 'hasDefault' => false, 'defaultValue' => null, 'isClass' => true]]]], 'source_signatures' => [$root . '::__construct' => $rootMetaSignature]];
    $this->writeFile_ObjectManagerReflectionArtifact($this->metadataPath_ObjectManagerReflectionArtifact, "<?php\nreturn " . \var_export($metadataEnvelope, true) . ";\n");
    $this->resetPreloadState_ObjectManagerReflectionArtifact();
    $stats = ObjectManager::preloadRuntimeMetadata();
    self::assertGreaterThan(0, $stats['compiled_factories']);
    self::assertGreaterThan(0, $stats['reflection_metadata']);
    self::assertNull($stats['compiled_factories_reject_reason']);
    self::assertNull($stats['reflection_metadata_reject_reason']);
    ObjectManager::resetParseMethodParamsMetadataCallCount();
    ObjectManager::clearInstances();
    $instance = ObjectManager::getInstance($root);
    self::assertInstanceOf(ObjectManagerReflectionArtifactRootFixture::class, $instance);
    self::assertInstanceOf(ObjectManagerReflectionArtifactLeafFixture::class, $instance->leaf);
    self::assertSame(0, ObjectManager::getParseMethodParamsMetadataCallCount());
}

private function resetPreloadState_ObjectManagerReflectionArtifact(): void
{
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('precompiledMetadata', null);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('precompiledLoaded', false);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('precompiledMetadataSourceSignatures', []);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('precompiledMetadataRejectReason', null);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('precompiledMetadataTrustedAfterPreload', false);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('compiledFactories', null);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('compiledFactoriesLoaded', false);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('compiledFactorySourceSignatures', []);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('compiledFactoriesRejectReason', null);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('compiledFactoriesTrustedAfterPreload', false);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('currentSourceSignatures', []);
    $this->writeObjectManagerProperty_ObjectManagerReflectionArtifact('methodParamsMetadata', []);
}

private function writeObjectManagerProperty_ObjectManagerReflectionArtifact(string $property, mixed $value): void
{
    $reflection = new \ReflectionProperty(ObjectManager::class, $property);
    $reflection->setAccessible(true);
    $reflection->setValue(null, $value);
}

private function writeFile_ObjectManagerReflectionArtifact(string $path, string $contents): void
{
    $dir = \dirname($path);
    if (!\is_dir($dir)) {
        \mkdir($dir, 0755, true);
    }
    \file_put_contents($path, $contents);
    \clearstatcache(true, $path);
    if (\function_exists('opcache_invalidate')) {
        \opcache_invalidate($path, true);
    }
}

private function restoreArtifact_ObjectManagerReflectionArtifact(string $path, ?string $backup): void
{
    if ($backup === null) {
        if (\is_file($path)) {
            @\unlink($path);
        }
        \clearstatcache(true, $path);
        if (\function_exists('opcache_invalidate')) {
            \opcache_invalidate($path, true);
        }
        return;
    }
    $this->writeFile_ObjectManagerReflectionArtifact($path, $backup);
}
}
