<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayInitialBootstrapOperations;
use Weline\Server\Service\Edge\Gateway\GatewayProjectReleasePackageFetchConfig;
use Weline\Server\Service\Edge\Gateway\GatewayProjectReleasePackageFetcher;
use Weline\Server\Service\Edge\Gateway\GatewayProjectReleasePackageResolver;

final class GatewayProjectReleasePackageFetcherTest extends TestCase
{
    private string $root = '';
    private string $keysFile = '';
    private string $cdn = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-gateway-fetch-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $this->keysFile = $this->root . DIRECTORY_SEPARATOR . 'trusted-release-keys.json';
        $this->cdn = $this->root . DIRECTORY_SEPARATOR . 'cdn' . DIRECTORY_SEPARATOR . 'darwin-arm64';
        self::assertTrue(\mkdir($this->cdn, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testProbeTrustFailsClosedOnEmptyKeyInventory(): void
    {
        \file_put_contents($this->keysFile, '{"schema_version":1,"keys":[]}');
        $calls = 0;
        $fetcher = $this->newFetcher($this->enabledConfig(), $calls);
        $probe = $fetcher->probeTrust();
        self::assertFalse($probe['ok']);
        self::assertSame(GatewayProjectReleasePackageFetcher::STATE_TRUST_UNAVAILABLE, $probe['state']);
        self::assertSame(0, $calls);
    }

    public function testFetchRefusesNetworkWhenNoEnabledTrustKey(): void
    {
        \file_put_contents($this->keysFile, \json_encode([
            'schema_version' => 1,
            'keys' => [[
                'id' => 'disabled-key',
                'algorithm' => 'ed25519',
                'enabled' => false,
                'public_key_base64' => \base64_encode(\str_repeat('a', 32)),
            ]],
        ], JSON_THROW_ON_ERROR));
        $calls = 0;
        $fetcher = $this->newFetcher($this->enabledConfig(), $calls);
        $result = $fetcher->fetch('darwin-arm64', false, $this->deadline());
        self::assertFalse($result['ok']);
        self::assertSame(GatewayProjectReleasePackageFetcher::STATE_TRUST_UNAVAILABLE, $result['state']);
        self::assertSame(0, $calls);
    }

    public function testFetchDisabledWithoutBaseUrl(): void
    {
        $this->writeEnabledKey();
        $calls = 0;
        $fetcher = $this->newFetcher(new GatewayProjectReleasePackageFetchConfig(
            '',
            true,
            30.0,
            ['cdn.test'],
        ), $calls);
        $result = $fetcher->fetch('darwin-arm64', false, $this->deadline());
        self::assertFalse($result['ok']);
        self::assertSame(GatewayProjectReleasePackageFetcher::STATE_FETCH_DISABLED, $result['state']);
        self::assertSame(0, $calls);
    }

    public function testFetchPublishesAtomicallyAfterInjectedVerify(): void
    {
        $this->writeEnabledKey();
        $body = "controller\n";
        $manifest = [
            'components' => [
                'app/controller.php' => [
                    'sha256' => \hash('sha256', $body),
                    'size' => \strlen($body),
                    'mode' => 0644,
                ],
            ],
            'release_ready' => true,
        ];
        \file_put_contents($this->cdn . '/manifest.json', \json_encode($manifest, JSON_THROW_ON_ERROR));
        \file_put_contents($this->cdn . '/manifest.sig', "sig\n");
        self::assertTrue(\mkdir($this->cdn . '/app', 0700, true));
        \file_put_contents($this->cdn . '/app/controller.php', $body);

        $calls = 0;
        $cdnRoot = $this->cdn;
        $fetcher = new GatewayProjectReleasePackageFetcher(
            $this->enabledConfig(),
            $this->root,
            $this->keysFile,
            $this->root . '/stage',
            static function (string $url, string $destination) use (&$calls, $cdnRoot): void {
                ++$calls;
                $prefix = 'https://cdn.test/wls/gateway/darwin-arm64/';
                self::assertStringStartsWith($prefix, $url);
                $relative = \rawurldecode(\substr($url, \strlen($prefix)));
                $source = $cdnRoot . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $parent = \dirname($destination);
                if (!\is_dir($parent)) {
                    self::assertTrue(\mkdir($parent, 0700, true));
                }
                self::assertNotFalse(\copy($source, $destination));
            },
            static function (string $directory): array {
                self::assertFileExists($directory . '/manifest.json');
                self::assertFileExists($directory . '/manifest.sig');
                self::assertFileExists($directory . '/app/controller.php');
                $bytes = (string)\file_get_contents($directory . '/manifest.json');
                return [
                    'package_dir' => $directory,
                    'manifest_bytes' => $bytes,
                    'signature_bytes' => "sig\n",
                    'package_digest' => \hash('sha256', $bytes),
                    'manifest_digest' => \hash('sha256', $bytes),
                    'signature_digest' => \hash('sha256', "sig\n"),
                    'manifest' => [
                        'release_ready' => true,
                        'package_profile' => 'production',
                    ],
                ];
            },
        );

        $result = $fetcher->fetch('darwin-arm64', false, $this->deadline());
        self::assertTrue($result['ok'], (string)($result['reason'] ?? ''));
        self::assertSame(GatewayProjectReleasePackageFetcher::STATE_OK, $result['state']);
        self::assertSame(3, $calls); // manifest + sig + one component
        $final = $this->root . '/extend/server/wls-gateway/darwin-arm64';
        self::assertDirectoryExists($final);
        self::assertFileExists($final . '/app/controller.php');
        $resolved = (new GatewayProjectReleasePackageResolver($this->root, 'darwin-arm64'))->resolve();
        self::assertTrue($resolved['ok']);
    }

    public function testAssessLocalPackageDetectsIncompleteFinalTree(): void
    {
        $this->writeEnabledKey();
        $package = $this->root . '/extend/server/wls-gateway/darwin-arm64';
        self::assertTrue(\mkdir($package, 0700, true));
        \file_put_contents($package . '/manifest.json', "{}\n");
        $calls = 0;
        $fetcher = new GatewayProjectReleasePackageFetcher(
            $this->enabledConfig(),
            $this->root,
            $this->keysFile,
            $this->root . '/stage',
            static function () use (&$calls): void {
                ++$calls;
            },
            static function (): array {
                throw new \RuntimeException('Gateway package component is missing.');
            },
        );
        $assessment = $fetcher->assessLocalPackage('darwin-arm64');
        self::assertFalse($assessment['ok']);
        self::assertSame(
            GatewayProjectReleasePackageFetcher::STATE_PACKAGE_INCOMPLETE,
            $assessment['state'],
        );
        self::assertSame(0, $calls);
    }

    public function testRejectsNonAllowlistedHost(): void
    {
        $this->writeEnabledKey();
        $fetcher = new GatewayProjectReleasePackageFetcher(
            new GatewayProjectReleasePackageFetchConfig(
                'https://evil.example/wls/gateway',
                true,
                30.0,
                ['cdn.test'],
            ),
            $this->root,
            $this->keysFile,
            $this->root . '/stage',
            static function (): void {
                self::fail('Downloader must not run for non-allowlisted hosts.');
            },
            static function (): array {
                self::fail('Verifier must not run for non-allowlisted hosts.');
            },
        );
        $result = $fetcher->fetch('darwin-arm64', false, $this->deadline());
        self::assertFalse($result['ok']);
        self::assertSame(GatewayProjectReleasePackageFetcher::STATE_FETCH_FAILED, $result['state']);
        self::assertStringContainsString('allowlisted', $result['reason']);
    }

    public function testConfigDefaultsDisableAutoFetch(): void
    {
        $config = GatewayProjectReleasePackageFetchConfig::fromArray([]);
        self::assertFalse($config->isFetchConfigured());
        self::assertFalse($config->isAutoFetchEnabled());
    }

    public function testEnsureDoesNotFetchWhenAutoFetchDisabled(): void
    {
        $ops = new GatewayInitialBootstrapOperations(
            resolver: new GatewayProjectReleasePackageResolver($this->root, 'linux-x86_64'),
            fetchConfig: GatewayProjectReleasePackageFetchConfig::fromArray([
                'package_base_url' => 'https://cdn.test/wls/gateway',
                'package_fetch' => false,
                'package_fetch_hosts' => ['cdn.test'],
            ]),
        );
        $result = $ops->ensureProjectReleasePackage($this->deadline());
        self::assertFalse($result['ok']);
        self::assertSame('PACKAGE_UNAVAILABLE', $result['state']);
    }

    private function writeEnabledKey(): void
    {
        \file_put_contents($this->keysFile, \json_encode([
            'schema_version' => 1,
            'keys' => [[
                'id' => 'test-key',
                'algorithm' => 'ed25519',
                'enabled' => true,
                'public_key_base64' => \base64_encode(\str_repeat('b', 32)),
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function enabledConfig(): GatewayProjectReleasePackageFetchConfig
    {
        return new GatewayProjectReleasePackageFetchConfig(
            'https://cdn.test/wls/gateway',
            true,
            30.0,
            ['cdn.test'],
        );
    }

    private function newFetcher(
        GatewayProjectReleasePackageFetchConfig $config,
        int &$calls,
    ): GatewayProjectReleasePackageFetcher {
        return new GatewayProjectReleasePackageFetcher(
            $config,
            $this->root,
            $this->keysFile,
            $this->root . '/stage',
            static function () use (&$calls): void {
                ++$calls;
                throw new \RuntimeException('unexpected download');
            },
            static function (): array {
                throw new \RuntimeException('unexpected verify');
            },
        );
    }

    private function deadline(): float
    {
        return (\hrtime(true) / 1_000_000_000) + 30.0;
    }

    private function removeTree(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);
            return;
        }
        if (!\is_dir($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
        @\rmdir($path);
    }
}
