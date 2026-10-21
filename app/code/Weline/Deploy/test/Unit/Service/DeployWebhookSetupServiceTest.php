<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployConfigService;
use Weline\Deploy\Service\DeployWebhookRouteService;
use Weline\Deploy\Service\DeployWebhookSetupService;

/**
 * Webhook 配置契约：
 * - 保存公网路径必须同时把入口切到 enabled，否则 setup 打印的 URL / curl 会 404；
 * - 空路径 / 旧路径不得误启用；
 * - isWebhookIngressEnabled 反映「已启用 + 路径可解析」。
 */
final class DeployWebhookSetupServiceTest extends TestCase
{
    public function testSavingGeneratedPathEnablesWebhookIngress(): void
    {
        $config = $this->configStub(['webhook_path' => '', 'webhook_enabled' => '0']);
        $service = new DeployWebhookSetupService($config, $this->routeStub($config));

        self::assertFalse($config->isWebhookEnabled());
        self::assertTrue($service->saveWebhookPath('~wh~deadbeef'));
        self::assertSame('~wh~deadbeef', $config->getSettings()['webhook_path']);
        self::assertSame('1', $config->getSettings()['webhook_enabled']);
        self::assertTrue($config->isWebhookEnabled());
        self::assertTrue($service->isWebhookIngressEnabled());
    }

    public function testSavingEmptyPathDoesNotEnableWebhookIngress(): void
    {
        $config = $this->configStub(['webhook_path' => '', 'webhook_enabled' => '0']);
        $service = new DeployWebhookSetupService($config, $this->routeStub($config));

        self::assertTrue($service->saveWebhookPath(''));
        self::assertSame('', $config->getSettings()['webhook_path']);
        self::assertSame('0', $config->getSettings()['webhook_enabled']);
        self::assertFalse($config->isWebhookEnabled());
        self::assertFalse($service->isWebhookIngressEnabled());
    }

    public function testLegacyPathDoesNotEnableWebhookIngress(): void
    {
        $config = $this->configStub(['webhook_path' => '', 'webhook_enabled' => '0']);
        $service = new DeployWebhookSetupService($config, $this->routeStub($config));

        self::assertTrue($service->saveWebhookPath('deploy'));
        self::assertSame('deploy', $config->getSettings()['webhook_path']);
        self::assertSame('0', $config->getSettings()['webhook_enabled']);
        self::assertFalse($config->isWebhookEnabled());
    }

    public function testResolvedPathsFollowEnabledFlag(): void
    {
        $config = $this->configStub(['webhook_path' => '~wh~cafe', 'webhook_enabled' => '0']);
        $route = $this->routeStub($config);

        self::assertNull($route->getResolvedPaths());
        $config->saveSettings(['webhook_path' => '~wh~cafe', 'webhook_enabled' => '1']);
        self::assertSame(
            ['webhook' => '~wh~cafe', 'version' => '~wh~cafe/version'],
            $route->getResolvedPaths(),
        );
    }

    /**
     * @param array<string, string> $settings
     */
    private function configStub(array $settings): DeployConfigService
    {
        return new class ($settings) extends DeployConfigService {
            /** @param array<string, string> $settings */
            public function __construct(private array $settings)
            {
            }

            public function getDefaults(): array
            {
                return $this->settings;
            }

            public function getSettings(): array
            {
                return $this->settings;
            }

            public function getStoredSettings(): array
            {
                return $this->settings;
            }

            public function saveSettings(array $settings): bool
            {
                $this->settings = $settings;
                return true;
            }

            public function isWebhookEnabled(): bool
            {
                if (\array_key_exists('webhook_enabled', $this->settings)) {
                    return (string)$this->settings['webhook_enabled'] === '1';
                }

                return trim((string)($this->settings['webhook_path'] ?? '')) !== '';
            }
        };
    }

    private function routeStub(DeployConfigService $config): DeployWebhookRouteService
    {
        return new class ($config) extends DeployWebhookRouteService {
            public function __construct(private DeployConfigService $config)
            {
            }

            public function normalizePath(string $path): string
            {
                return trim(str_replace('\\', '/', $path), '/');
            }

            public function isLegacyOrEmptyPath(string $path): bool
            {
                $normalized = $this->normalizePath($path);
                if ($normalized === '' || $normalized === 'deploy') {
                    return true;
                }

                return !str_starts_with($normalized, '~wh~');
            }

            public function getResolvedPaths(): ?array
            {
                if (!$this->config->isWebhookEnabled()) {
                    return null;
                }
                $webhook = $this->normalizePath((string)($this->config->getSettings()['webhook_path'] ?? ''));
                if ($webhook === '' || $this->isLegacyOrEmptyPath($webhook)) {
                    return null;
                }

                return ['webhook' => $webhook, 'version' => $webhook . '/version'];
            }

            public function clearCache(): void
            {
            }
        };
    }
}
