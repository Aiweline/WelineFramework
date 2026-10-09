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
    public function testMergeAutoAlwaysOnWhileMinifyFollowsEnvironment(): void
    {
        foreach (['prod', 'dev'] as $environment) {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/resource-config-environment.php')
                . ' ' . escapeshellarg($environment);
            $output = [];
            exec($command, $output, $exitCode);
            self::assertSame(0, $exitCode, implode("\n", $output));
            $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($result['css_minify'], 'Explicit on overrides ' . $environment);
            self::assertFalse($result['js_minify'], 'Explicit off overrides ' . $environment);
            self::assertTrue($result['css_merge'], 'css_merge auto/on always true in ' . $environment);
            self::assertTrue($result['js_merge'], 'js_merge auto/on always true in ' . $environment);
            self::assertTrue($result['theme_css_merge'], 'theme_css_merge auto/on always true in ' . $environment);
            self::assertTrue($result['theme_js_merge'], 'theme_js_merge auto/on always true in ' . $environment);
            self::assertSame(6, $result['css_merge_start_widget']);
            self::assertSame(6, $result['js_merge_start_widget']);
        }
    }

    public function testResolvesModesAndIndependentThresholdsThroughRuntimeScope(): void
    {
        $identity = RequestContext::scopeIdentity() ?? ScopeIdentity::global();
        $config = new class extends SystemConfig {
            public function __construct() {}

            public function resolveTypedConfig(
                string $key,
                string $module,
                string $area,
                ScopeIdentity $scope,
                ?string $locale = null,
                mixed $default = null,
            ): ConfigScopeValue {
                TestCase::assertSame('Weline_Theme', $module);
                TestCase::assertSame('frontend', $area);
                TestCase::assertSame('default', $locale ?? 'default');
                $values = [
                    'resource_files/css_minify' => 'on',
                    'resource_files/js_minify' => 'off',
                    'resource_files/theme_css_merge' => 'on',
                    'resource_files/theme_js_merge' => 'off',
                    'resource_files/js_merge_start' => 9,
                ];

                return new ConfigScopeValue(
                    $values[$key] ?? $default,
                    ConfigScopeSource::fromDefault(),
                    $scope,
                    $locale ?? 'default',
                    [],
                );
            }
        };
        self::assertSame([
            'css_minify' => true, 'js_minify' => false,
            'css_merge' => true, 'js_merge' => true,
            'theme_css_merge' => true, 'theme_js_merge' => false,
            'css_merge_start_widget' => 6, 'js_merge_start_widget' => 9,
        ], (new ThemeResourceConfig($config))->resolve());
        unset($identity);
    }
}
