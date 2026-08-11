<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayProjectReleasePackageResolver;

final class GatewayProjectReleasePackageResolverTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-project-gateway-release-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testUsesOnlyTheFixedProjectDistributionTarget(): void
    {
        $target = 'darwin-arm64';
        $package = $this->root . '/extend/server/wls-gateway/' . $target;
        self::assertTrue(\mkdir($package, 0700, true));
        self::assertNotFalse(\file_put_contents($package . '/manifest.json', "{}\n"));

        $result = (new GatewayProjectReleasePackageResolver(
            $this->root,
            $target,
        ))->resolve();

        self::assertTrue($result['ok']);
        self::assertSame('AVAILABLE', $result['state']);
        self::assertSame((string)\realpath($package), $result['path']);
        self::assertSame($target, $result['target_profile']);
    }

    public function testMissingTargetIsAnExplicitFailClosedResult(): void
    {
        $result = (new GatewayProjectReleasePackageResolver(
            $this->root,
            'linux-x86_64',
        ))->resolve();

        self::assertFalse($result['ok']);
        self::assertSame('PACKAGE_UNAVAILABLE', $result['state']);
        self::assertSame('', $result['path']);
        self::assertStringContainsString(
            'extend/server/wls-gateway/linux-x86_64',
            $result['reason'],
        );
    }

    public function testLinkedTargetIsRejectedRatherThanFollowed(): void
    {
        $outside = $this->root . '-outside';
        self::assertTrue(\mkdir($outside, 0700, true));
        self::assertNotFalse(\file_put_contents($outside . '/manifest.json', "{}\n"));
        self::assertTrue(\mkdir($this->root . '/extend/server/wls-gateway', 0700, true));
        self::assertTrue(\symlink(
            $outside,
            $this->root . '/extend/server/wls-gateway/darwin-arm64',
        ));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('linked');
            (new GatewayProjectReleasePackageResolver(
                $this->root,
                'darwin-arm64',
            ))->resolve();
        } finally {
            @\unlink($this->root . '/extend/server/wls-gateway/darwin-arm64');
            $this->removeTree($outside);
        }
    }

    public function testLinkedParentIsRejectedBeforeTargetResolution(): void
    {
        $outside = $this->root . '-parent-outside';
        self::assertTrue(\mkdir($outside . '/server/wls-gateway/darwin-arm64', 0700, true));
        self::assertNotFalse(\file_put_contents(
            $outside . '/server/wls-gateway/darwin-arm64/manifest.json',
            "{}\n",
        ));
        self::assertTrue(\symlink($outside, $this->root . '/extend'));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('linked');
            (new GatewayProjectReleasePackageResolver(
                $this->root,
                'darwin-arm64',
            ))->resolve();
        } finally {
            @\unlink($this->root . '/extend');
            $this->removeTree($outside);
        }
    }

    public function testUnsupportedPlatformArchitectureMappingFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported');
        GatewayProjectReleasePackageResolver::targetProfile(
            'Windows',
            'arm64',
        );
    }

    public function testWindowsCanonicalPathsNormalizeCaseAndSeparators(): void
    {
        $equals = new \ReflectionMethod(
            GatewayProjectReleasePackageResolver::class,
            'canonicalPathsEqual',
        );
        $within = new \ReflectionMethod(
            GatewayProjectReleasePackageResolver::class,
            'pathIsWithin',
        );
        $equals->setAccessible(true);
        $within->setAccessible(true);

        self::assertTrue($equals->invoke(
            null,
            'C:\\ProgramData\\Weline\\Gateway\\',
            'c:/programdata/weline/gateway',
            'Windows',
        ));
        self::assertTrue($within->invoke(
            null,
            'C:\\Projects\\Store\\extend\\server\\wls-gateway\\windows-x86_64',
            'c:/projects/store',
            'Windows',
        ));
        self::assertFalse($within->invoke(
            null,
            'C:\\Projects\\Store-Escape\\extend\\server\\wls-gateway\\windows-x86_64',
            'c:/projects/store',
            'Windows',
        ));
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            return;
        }
        $entries = \scandir($path);
        if (!\is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $target = $path . DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($target) && !\is_link($target)) {
                $this->removeTree($target);
            } else {
                @\unlink($target);
            }
        }
        @\rmdir($path);
    }
}
