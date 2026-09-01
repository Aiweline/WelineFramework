<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Taglib\ConfigEmbed;

final class ConfigEmbedContractTest extends TestCase
{
    public function testTaglibNameAndRequiredModuleAttr(): void
    {
        self::assertSame('config:embed', ConfigEmbed::name());
        $attrs = ConfigEmbed::attr();
        self::assertTrue($attrs['module'] ?? false);
        self::assertArrayHasKey('field', $attrs);
        self::assertArrayHasKey('fields', $attrs);
        self::assertArrayHasKey('group', $attrs);
        self::assertArrayHasKey('layout', $attrs);
        self::assertArrayHasKey('template', $attrs);
    }

    public function testCallbackDelegatesToConfigEmbedRenderer(): void
    {
        $callback = ConfigEmbed::callback();
        $php = $callback('config:embed', [], [], [
            'module' => true,
            'area' => false,
            'field' => false,
            'fields' => false,
            'group' => false,
            'layout' => false,
            'template' => false,
            'locale' => false,
            'class' => false,
        ]);
        self::assertStringContainsString('ConfigEmbedRenderer', $php);
        self::assertStringContainsString('renderFromAttributes', $php);
        self::assertStringContainsString('$Taglib__module', $php);
        self::assertTrue(method_exists(ConfigEmbed::class, 'runtimeCallback'));
        $runtime = ConfigEmbed::runtimeCallback();
        self::assertIsCallable($runtime);
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Taglib/ConfigEmbed.php');
        self::assertStringContainsString('runtimeCallback', $src);
        self::assertStringContainsString('renderRuntimeTag', $src);
        self::assertStringContainsString('必须直接返回 HTML', $src);
        self::assertStringContainsString('config-embed标签使用指南.md', ConfigEmbed::document());
        self::assertStringContainsString('module', ConfigEmbed::document());
        self::assertStringContainsString('layout', ConfigEmbed::document());
    }

    public function testDefaultTemplatesAndAssetsExist(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/view/templates/taglib/config-embed.phtml');
        self::assertFileExists($root . '/view/templates/taglib/config-embed-field.phtml');
        self::assertFileExists($root . '/view/statics/js/config-embed.js');
        self::assertFileExists($root . '/view/statics/css/config-embed.css');

        $shell = (string)file_get_contents($root . '/view/templates/taglib/config-embed.phtml');
        self::assertStringContainsString('data-w-config-embed', $shell);
        self::assertStringContainsString('data-grant-version', $shell);
        self::assertStringContainsString('data-locale', $shell);

        $field = (string)file_get_contents($root . '/view/templates/taglib/config-embed-field.phtml');
        self::assertStringContainsString('data-testid="config-embed-field"', $field);
        self::assertStringContainsString('config-embed-undeclared', $field);
        self::assertStringContainsString('config-embed-sensitive-link', $field);
        self::assertStringContainsString('data-w-config-embed-control', $field);
        self::assertStringContainsString('data-value-type', $field);

        $js = (string)file_get_contents($root . '/view/statics/js/config-embed.js');
        self::assertStringContainsString("resource('system_config').setScopedConfig", $js);
        self::assertStringContainsString('Weline.UI.toast', $js);
        self::assertStringContainsString("locale: root.dataset.locale || 'default'", $js);
        self::assertStringContainsString('payload.value_type = fieldEl.dataset.valueType', $js);

        $css = (string)file_get_contents($root . '/view/statics/css/config-embed.css');
        self::assertStringContainsString('--weline-theme-surface', $css);
        self::assertStringContainsString('--weline-theme-text', $css);
        self::assertStringContainsString('--weline-theme-border', $css);
        self::assertStringContainsString('layout-inline', $css);
        self::assertStringNotContainsString('background: var(--weline-surface-raised, #fff)', $css);
        self::assertStringNotContainsString('background:#fff', $css);
    }

    public function testModuleVersionIs133(): void
    {
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        self::assertSame('1.3.3', $module['version'] ?? null);
    }

    public function testSetScopedConfigDescriptorDeclaresValueType(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/SystemConfigQueryProvider.php'
        );
        self::assertMatchesRegularExpression(
            "/function commonWriteParams[\\s\\S]*?'name'\\s*=>\\s*'value_type'/",
            $src,
        );
    }
}
