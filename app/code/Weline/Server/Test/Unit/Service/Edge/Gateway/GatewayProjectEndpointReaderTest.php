<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayProjectEndpointReader;
use Weline\Server\Service\ServerInstanceManager;

final class GatewayProjectEndpointReaderTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'wls-endpoints-' . \bin2hex(\random_bytes(8));
        self::assertTrue(@\mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach ((array)@\scandir($this->directory) as $leaf) {
            if ($leaf === '.' || $leaf === '..') {
                continue;
            }
            @\unlink($this->directory . DIRECTORY_SEPARATOR . $leaf);
        }
        @\rmdir($this->directory);
    }

    public function testValidInstanceNameContainingTmpSegmentIsNotHiddenAsSidecar(): void
    {
        $instance = 'primary.tmp.worker';
        $path = $this->directory . DIRECTORY_SEPARATOR . $instance . '.json';
        self::assertNotFalse(\file_put_contents($path, '{"instance_id":"primary.tmp.worker"}'));
        @\chmod($path, 0600);

        $manager = $this->manager();

        $endpoints = (new GatewayProjectEndpointReader($manager))->all();

        self::assertArrayHasKey($instance, $endpoints);
        self::assertSame($instance, $endpoints[$instance]['instance_id']);
    }

    public function testCompleteReaderAndAllEndpointMutationsShareNamespaceLock(): void
    {
        $reader = new \ReflectionMethod(GatewayProjectEndpointReader::class, 'all');
        $readerSource = $this->methodSource($reader);
        self::assertStringContainsString(
            'GATEWAY_ENDPOINT_NAMESPACE_LOCK',
            $readerSource,
        );
        self::assertStringContainsString('withExclusiveLock(', $readerSource);

        $writer = new \ReflectionMethod(
            ServerInstanceManager::class,
            'updateJsonFileAtomically',
        );
        $writerSource = $this->methodSource($writer);
        self::assertStringContainsString('withExclusiveLock(', $writerSource);
        $lockResolver = new \ReflectionMethod(
            ServerInstanceManager::class,
            'gatewayEndpointNamespaceLockForFile',
        );
        self::assertStringContainsString(
            'GATEWAY_ENDPOINT_NAMESPACE_LOCK',
            $this->methodSource($lockResolver),
        );
        $instanceWriter = new \ReflectionMethod(
            ServerInstanceManager::class,
            'atomicUpdateJson',
        );
        $instanceWriterSource = $this->methodSource($instanceWriter);
        self::assertStringContainsString(
            'GATEWAY_ENDPOINT_NAMESPACE_LOCK',
            $instanceWriterSource,
        );
        self::assertStringContainsString(
            'withExclusiveLock(',
            $instanceWriterSource,
        );
    }

    public function testWriterReservesTheReaderCapacityBeforePublishingANewEndpoint(): void
    {
        $manager = $this->manager();
        for ($offset = 0; $offset < 256; $offset++) {
            $instance = 'capacity-' . \str_pad((string)$offset, 3, '0', STR_PAD_LEFT);
            self::assertNotFalse(\file_put_contents(
                $manager->getInstanceFile($instance),
                '{"name":"' . $instance . '"}',
            ));
        }

        $manager->saveInstance('capacity-000', [
            'port' => 28080,
            'gateway' => ['requested_mode' => 'auto'],
        ]);
        self::assertFileExists($manager->getInstanceFile('capacity-000'));

        try {
            $manager->saveInstance('capacity-overflow', [
                'port' => 28081,
                'gateway' => ['requested_mode' => 'auto'],
            ]);
            self::fail('Publishing endpoint 257 must fail before the reader namespace is poisoned.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('capacity', \strtolower($exception->getMessage()));
        }
        self::assertFileDoesNotExist($manager->getInstanceFile('capacity-overflow'));
        self::assertCount(256, (new GatewayProjectEndpointReader($manager))->all());
    }

    public function testWriterDoesNotHideAtomicPublicationFailure(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('A lock-link fixture requires symlink support.');
        }
        $manager = $this->manager();
        $instance = 'atomic-failure';
        $lock = $manager->getInstanceFile($instance) . '.lock';
        self::assertTrue(@\symlink($this->directory, $lock));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publish WLS instance endpoint');
        $manager->saveInstance($instance, ['port' => 28082]);
    }

    public function testWriterCollectsBackupOnlyWhenCurrentEndpointIdentityIsValid(): void
    {
        $manager = $this->manager();
        $instance = 'atomic-backup-valid';
        $file = $manager->getInstanceFile($instance);
        $backup = $file . '.wls-backup-' . \str_repeat('4', 16);
        $manager->saveInstance($instance, ['port' => 28084]);
        self::assertNotFalse(@\copy($file, $backup));
        @\chmod($backup, 0600);

        $manager->saveInstance($instance, ['port' => 28085]);

        self::assertFileDoesNotExist($backup);
        $updated = \json_decode(
            (string)\file_get_contents($file),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(28085, $updated['port'] ?? null);
    }

    public function testWriterPreservesBackupWhenCurrentEndpointIdentityIsForeign(): void
    {
        $manager = $this->manager();
        $instance = 'atomic-backup-foreign';
        $file = $manager->getInstanceFile($instance);
        $backup = $file . '.wls-backup-' . \str_repeat('5', 16);
        $manager->saveInstance($instance, ['port' => 28086]);
        self::assertNotFalse(@\copy($file, $backup));
        @\chmod($backup, 0600);
        // Plant a foreign current leaf beside retained recovery evidence.
        // atomicWrite() refuse-layers over unresolved backups, so this fixture
        // writes the torn current file directly.
        self::assertNotFalse(@\file_put_contents(
            $file,
            "{\"name\":\"another-instance\",\"port\":28087}\n",
            LOCK_EX,
        ));
        @\chmod($file, 0600);

        try {
            $manager->saveInstance($instance, ['port' => 28088]);
            self::fail('Foreign endpoint identity must veto retained-backup cleanup.');
        } catch (\RuntimeException) {
            self::assertFileExists($backup);
            self::assertStringContainsString(
                'another-instance',
                (string)\file_get_contents($file),
            );
        }
    }

    public function testLegacyEndpointReplacementDoesNotInheritTheWls2ReaderCeiling(): void
    {
        $manager = $this->manager();
        for ($offset = 0; $offset < 257; $offset++) {
            $instance = 'legacy-' . \str_pad((string)$offset, 3, '0', STR_PAD_LEFT);
            self::assertNotFalse(\file_put_contents(
                $manager->getInstanceFile($instance),
                '{"name":"' . $instance . '","port":8080}',
            ));
        }

        $manager->saveInstance('legacy-000', ['port' => 28083]);

        $updated = \json_decode(
            (string)\file_get_contents($manager->getInstanceFile('legacy-000')),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(28083, $updated['port']);
    }

    private function manager(): ServerInstanceManager
    {
        return new class($this->directory) extends ServerInstanceManager {
            public function __construct(private readonly string $directory)
            {
            }

            public function getInstanceDir(): string
            {
                return $this->directory . DIRECTORY_SEPARATOR;
            }

            public function getInstanceFile(string $name): string
            {
                return $this->getInstanceDir() . $name . '.json';
            }
        };
    }

    private function methodSource(\ReflectionMethod $method): string
    {
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
