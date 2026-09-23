<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ConfigScopeSource;
use Weline\SystemConfig\Api\Scope\ConfigScopeValue;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Theme\Service\ThemeResourceConfig;

final class ThemeResourceConfigTest extends TestCase
{
    public function testAutoInIsolatedProductionAndDevelopmentProcesses(): void
    {
        foreach (['prod' => true, 'dev' => false] as $environment => $expected) {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/resource-config-environment.php')
                . ' ' . escapeshellarg($environment);
            $output = [];
            exec($command, $output, $exitCode);
            self::assertSame(0, $exitCode);
            $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($result['css_minify'], 'Explicit on overrides ' . $environment);
            self::assertFalse($result['js_minify'], 'Explicit off overrides ' . $environment);
            self::assertSame($expected, $result['css_merge']);
            self::assertSame($expected, $result['js_merge']);
            self::assertSame(6, $result['css_merge_start_widget']);
            self::assertSame(6, $result['js_merge_start_widget']);
        }
    }

    public function testResolvesModesAndIndependentThresholdsThroughRuntimeScope(): void
    {
        $identity = RequestContext::scopeIdentity() ?? ScopeIdentity::global();
        $config = $this->getMockBuilder(SystemConfig::class)->disableOriginalConstructor()
            ->onlyMethods(['resolveTypedConfig'])->getMock();
        $config->expects(self::exactly(6))->method('resolveTypedConfig')->willReturnCallback(
            function ($key, $module, $area, $scope, $locale, $default) use ($identity): ConfigScopeValue {
                self::assertSame('Weline_Theme', $module);
                self::assertSame('frontend', $area);
                self::assertEquals($identity, $scope);
                self::assertSame('default', $locale);
                $values = ['resource_files/css_minify' => 'on', 'resource_files/js_minify' => 'off',
                    'resource_files/js_merge_start' => 9];
                return new ConfigScopeValue($values[$key] ?? $default, ConfigScopeSource::fromDefault(), $scope, $locale, []);
            }
        );
        self::assertSame([
            'css_minify' => true, 'js_minify' => false,
            'css_merge' => defined('PROD') && PROD, 'js_merge' => defined('PROD') && PROD,
            'css_merge_start_widget' => 6, 'js_merge_start_widget' => 9,
        ], (new ThemeResourceConfig($config))->resolve());
    }
}
