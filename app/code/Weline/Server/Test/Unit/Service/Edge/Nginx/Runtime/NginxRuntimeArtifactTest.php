<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxRuntimeArtifact;

final class NginxRuntimeArtifactTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-artifact-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testImmutableSlotVerifiesEveryComponent(): void
    {
        $binary = $this->root . DIRECTORY_SEPARATOR . 'source-nginx';
        $mime = $this->root . DIRECTORY_SEPARATOR . 'source-mime.types';
        self::assertSame(6, \file_put_contents($binary, 'binary'));
        self::assertSame(8, \file_put_contents($mime, 'types {}'));
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-A';
        $artifact = new NginxRuntimeArtifact();

        $manifest = $artifact->install($slot, 'gateway-nginx', [
            'bin/nginx' => ['source' => $binary, 'mode' => 0700],
            'conf/mime.types' => ['source' => $mime, 'mode' => 0600],
        ], ['protocol' => 'wls-edge/2']);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $manifest['runtime_generation']);
        $verified = $artifact->verify($slot, 'gateway-nginx');
        self::assertTrue($verified['ok']);
        self::assertSame(2, $verified['components']);
        self::assertFileExists($slot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'nginx');

        self::assertSame(7, \file_put_contents(
            $slot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'nginx',
            'changed',
        ));
        $changed = $artifact->verify($slot, 'gateway-nginx');
        self::assertFalse($changed['ok']);
        self::assertStringContainsString('digest or size mismatch', $changed['reason']);
    }

    public function testExistingSlotAndTraversalAreRejected(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        self::assertSame(4, \file_put_contents($source, 'data'));
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-B';
        self::assertTrue(\mkdir($slot, 0700));
        $artifact = new NginxRuntimeArtifact();

        try {
            $artifact->install($slot, 'gateway-nginx', [
                'bin/nginx' => ['source' => $source],
            ]);
            self::fail('An immutable slot must not be overwritten.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        $fresh = $this->root . DIRECTORY_SEPARATOR . 'slot-C';
        try {
            $artifact->install($fresh, 'gateway-nginx', [
                '../escape' => ['source' => $source],
            ]);
            self::fail('Archive-style traversal must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('relative and contained', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'escape');
    }

    public function testInstallRejectsComponentAndDirectoryCardinalityBeforePublication(): void
    {
        $artifact = new NginxRuntimeArtifact();
        $components = [];
        for ($index = 0; $index <= NginxRuntimeArtifact::MAX_COMPONENTS; ++$index) {
            $components['component-' . $index] = ['contents' => ''];
        }
        try {
            $artifact->install(
                $this->root . DIRECTORY_SEPARATOR . 'component-limit',
                'gateway-nginx',
                $components,
            );
            self::fail('An oversized runtime component map must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('component limit', $exception->getMessage());
        }

        $components = [];
        $paths = \intdiv(NginxRuntimeArtifact::MAX_DIRECTORIES, 3) + 1;
        for ($index = 0; $index < $paths; ++$index) {
            $components[
                'd' . $index . '/s' . $index . '/t' . $index . '/file'
            ] = ['contents' => ''];
        }
        try {
            $artifact->install(
                $this->root . DIRECTORY_SEPARATOR . 'directory-limit',
                'gateway-nginx',
                $components,
            );
            self::fail('An oversized runtime directory topology must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('directory limit', $exception->getMessage());
        }

        try {
            $artifact->install(
                $this->root . DIRECTORY_SEPARATOR . 'depth-limit',
                'gateway-nginx',
                [\implode('/', \array_fill(
                    0,
                    NginxRuntimeArtifact::MAX_PATH_DEPTH + 1,
                    'd',
                )) => ['contents' => '']],
            );
            self::fail('An oversized runtime path depth must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('relative and contained', $exception->getMessage());
        }
    }

    public function testInstallRejectsFileAndDirectoryPrefixCollisions(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('collides with a component directory');
        (new NginxRuntimeArtifact())->install(
            $this->root . DIRECTORY_SEPARATOR . 'prefix-collision',
            'gateway-nginx',
            [
                'bin' => ['contents' => 'file'],
                'bin/nginx' => ['contents' => 'child'],
            ],
        );
    }

    public function testInstallRejectsComponentsBelowTheArtifactManifestPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contract is unsafe');
        (new NginxRuntimeArtifact())->install(
            $this->root . DIRECTORY_SEPARATOR . 'manifest-prefix-collision',
            'gateway-nginx',
            ['manifest.json/child' => ['contents' => 'shadow']],
        );
    }

    public function testInstallRejectsSparseSourceBeyondTotalByteLimit(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'oversized-sparse-source';
        $handle = \fopen($source, 'wb');
        self::assertIsResource($handle);
        try {
            self::assertTrue(\ftruncate(
                $handle,
                NginxRuntimeArtifact::MAX_TOTAL_BYTES + 1,
            ));
        } finally {
            \fclose($handle);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('byte limit');
        (new NginxRuntimeArtifact())->install(
            $this->root . DIRECTORY_SEPARATOR . 'byte-limit',
            'gateway-nginx',
            ['bin/nginx' => ['source' => $source]],
        );
    }

    public function testVerifyRejectsOversizedComponentManifestBeforeTreeTraversal(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'oversized-manifest-slot';
        self::assertTrue(\mkdir($slot, 0700));
        $components = [];
        for ($index = 0; $index <= NginxRuntimeArtifact::MAX_COMPONENTS; ++$index) {
            $components['component-' . $index] = [
                'sha256' => \str_repeat('0', 64),
                'size' => 0,
                'mode' => 0600,
            ];
        }
        self::assertNotFalse(\file_put_contents(
            $slot . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode([
                'schema_version' => NginxRuntimeArtifact::SCHEMA_VERSION,
                'role' => 'gateway-nginx',
                'components' => $components,
                'runtime_generation' => \str_repeat('0', 64),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));

        $verified = (new NginxRuntimeArtifact())->verify($slot, 'gateway-nginx');
        self::assertFalse($verified['ok']);
        self::assertStringContainsString('manifest contract', $verified['reason']);
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
