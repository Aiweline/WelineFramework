<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxProcessIdentity;

final class NginxProcessIdentityTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-identity-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testAdoptionBindsPidDigestGenerationAndExactArgvPaths(): void
    {
        [$identity, $command, $generation, $processManifest] = $this->fixture();

        $adopted = $identity->inspect(32123, $command, true);
        self::assertTrue($adopted['ok']);
        self::assertTrue($adopted['adopted']);
        self::assertSame($generation, $adopted['runtime_generation']);
        self::assertSame(32123, $identity->recordedPid());

        $verified = $identity->inspect(32123, $command, false);
        self::assertTrue($verified['ok']);
        self::assertFalse($verified['adopted']);
        self::assertFileExists($processManifest);
    }

    public function testDifferentPidCannotReuseExistingProcessGeneration(): void
    {
        [$identity, $command] = $this->fixture();
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);

        $reused = $identity->inspect(32124, $command, true);

        self::assertFalse($reused['ok']);
        self::assertStringContainsString('generation does not match', $reused['reason']);
        self::assertSame(32123, $identity->recordedPid());
    }

    public function testBinaryMutationAndArgvMismatchFailClosed(): void
    {
        [$identity, $command, , , $binary] = $this->fixture();
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);
        self::assertSame(7, \file_put_contents($binary, 'changed'));

        $mutated = $identity->inspect(32123, $command, false);
        self::assertFalse($mutated['ok']);
        self::assertStringContainsString('binary digest', $mutated['reason']);

        [$fresh, , , , $freshBinary] = $this->fixture('second');
        $wrongCommand = $this->quote($freshBinary) . ' -p ' . $this->quote($this->root)
            . ' -c ' . $this->quote($this->root . DIRECTORY_SEPARATOR . 'wrong.conf');
        $wrong = $fresh->inspect(65432, $wrongCommand, true);
        self::assertFalse($wrong['ok']);
        self::assertStringContainsString('argv', $wrong['reason']);
    }

    public function testClearRequiresMatchingPid(): void
    {
        [$identity, $command] = $this->fixture();
        self::assertTrue($identity->inspect(32123, $command, true)['ok']);

        try {
            $identity->clear(99999);
            self::fail('A different PID must not clear the process identity.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('another generation', $exception->getMessage());
        }

        $identity->clear(32123);
        self::assertNull($identity->recordedPid());
    }

    public function testLegacyManifestIsAttestedOnceAndThenFenced(): void
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'legacy';
        self::assertTrue(\mkdir($directory, 0700, true));
        $binary = $directory . DIRECTORY_SEPARATOR . 'nginx';
        $prefix = $directory . DIRECTORY_SEPARATOR . 'runtime';
        $config = $prefix . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'nginx.conf';
        $manifest = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        $processManifest = $prefix . DIRECTORY_SEPARATOR . 'run'
            . DIRECTORY_SEPARATOR . 'nginx.process-identity.json';
        self::assertTrue(\mkdir(\dirname($config), 0700, true));
        self::assertTrue(\mkdir(\dirname($processManifest), 0700, true));
        self::assertSame(6, \file_put_contents($binary, 'legacy'));
        self::assertSame(8, \file_put_contents($config, 'events{}'));
        self::assertNotFalse(\file_put_contents($manifest, \json_encode([
            'version' => '1.26.3',
            'source_sha256' => \hash('sha256', 'legacy-source'),
            'platform' => \PHP_OS_FAMILY,
            'binary' => $binary,
        ], JSON_THROW_ON_ERROR)));
        $identity = new NginxProcessIdentity(
            role: 'legacy-project-nginx',
            binary: $binary,
            prefix: $prefix,
            config: $config,
            installManifest: $manifest,
            processManifest: $processManifest,
        );
        $command = $this->quote($binary) . ' -p ' . $this->quote($prefix)
            . ' -c ' . $this->quote($config);

        $adopted = $identity->inspect(76543, $command, true);
        self::assertTrue($adopted['ok']);
        self::assertTrue($adopted['adopted']);
        self::assertSame(7, \file_put_contents($binary, 'changed'));

        $changed = $identity->inspect(76543, $command, false);
        self::assertFalse($changed['ok']);
        self::assertStringContainsString('field mismatch', $changed['reason']);
    }

    /**
     * @return array{NginxProcessIdentity,string,string,string,string}
     */
    private function fixture(string $name = 'first'): array
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . $name;
        self::assertTrue(\mkdir($directory, 0700, true));
        $binary = $directory . DIRECTORY_SEPARATOR . 'nginx';
        $prefix = $directory . DIRECTORY_SEPARATOR . 'runtime';
        $config = $prefix . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'nginx.conf';
        $manifest = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        $processManifest = $prefix . DIRECTORY_SEPARATOR . 'run'
            . DIRECTORY_SEPARATOR . 'nginx.process-identity.json';
        self::assertTrue(\mkdir(\dirname($config), 0700, true));
        self::assertTrue(\mkdir(\dirname($processManifest), 0700, true));
        self::assertSame(6, \file_put_contents($binary, 'binary'));
        self::assertSame(8, \file_put_contents($config, 'events{}'));
        $generation = \hash('sha256', 'runtime-' . $name);
        $payload = [
            'version' => '1.30.4',
            'binary_sha256' => \hash_file('sha256', $binary),
            'runtime_generation' => $generation,
        ];
        self::assertNotFalse(\file_put_contents(
            $manifest,
            \json_encode($payload, JSON_THROW_ON_ERROR),
        ));
        $identity = new NginxProcessIdentity(
            role: 'test-nginx',
            binary: $binary,
            prefix: $prefix,
            config: $config,
            installManifest: $manifest,
            processManifest: $processManifest,
        );
        $command = $this->quote($binary) . ' -p ' . $this->quote($prefix)
            . ' -c ' . $this->quote($config);

        return [$identity, $command, $generation, $processManifest, $binary];
    }

    private function quote(string $value): string
    {
        return '"' . $value . '"';
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
