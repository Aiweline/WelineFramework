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
        self::assertArrayHasKey('target_scope', $attrs);
        self::assertArrayHasKey('website_code', $attrs);
        self::assertArrayHasKey('store_code', $attrs);
        self::assertArrayHasKey('channel_code', $attrs);
        self::assertArrayHasKey('scope_kind', $attrs);
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
        self::assertStringContainsString('embedFieldSource', $shell);
        self::assertStringContainsString('fetchHtml', $shell);

        $field = (string)file_get_contents($root . '/view/templates/taglib/config-embed-field.phtml');
        self::assertStringContainsString('data-testid="config-embed-field"', $field);
        self::assertStringContainsString('w-config-embed__media-path', $field);
        self::assertStringContainsString('picker_title', $field);
        self::assertStringContainsString('config-embed-undeclared', $field);
        self::assertStringContainsString('config-embed-sensitive-link', $field);
        self::assertStringContainsString('data-w-config-embed-control', $field);
        self::assertStringContainsString('w:theme:search-select', $field);
        self::assertStringContainsString('w:i18n:language:select', $field);
        self::assertStringContainsString('w:ai:model:select', $field);
        self::assertStringContainsString("in_array(\$type, ['locale', 'language'], true)", $field);
        self::assertStringContainsString("in_array(\$type, ['ai_model', 'model', 'ai-model'], true)", $field);
        self::assertStringContainsString("in_array(\$type, ['image', 'file'], true)", $field);
        self::assertStringContainsString('media_path', $field);
        self::assertStringContainsString('Weline\\MediaManager\\Block\\WelineMedia::class', $field);
        self::assertStringContainsString('data-value-type', $field);

        $js = (string)file_get_contents($root . '/view/statics/js/config-embed.js');
        self::assertStringContainsString("resource('system_config').setScopedConfig", $js);
        self::assertStringContainsString('Weline.UI.toast', $js);
        self::assertStringContainsString("locale: root.dataset.locale || 'default'", $js);
        self::assertStringContainsString('payload.value_type = fieldEl.dataset.valueType', $js);
        self::assertStringContainsString('data-w-language-field', $js);
        self::assertStringContainsString('data-ai-model-value', $js);
        self::assertStringContainsString('data-w-config-embed-media', $js);
        self::assertSame(1, preg_match('/var\s+TEXT_DEBOUNCE_MS\s*=\s*(\d+)/', $js, $debounceMatch));
        self::assertGreaterThanOrEqual(1000, (int)($debounceMatch[1] ?? 0), 'text autosave debounce must be >= 1000ms');
        self::assertStringContainsString('}, TEXT_DEBOUNCE_MS)', $js);
        self::assertStringNotContainsString('}, 300)', $js);
        self::assertStringContainsString('lockControl: false', $js);
        self::assertStringContainsString('pendingSave', $js);

        $resolver = (string)file_get_contents($root . '/Service/ConfigEmbedResolver.php');
        self::assertStringContainsString('resolveMediaPickerMeta', $resolver);
        self::assertStringContainsString('media_path', $resolver);

        $css = (string)file_get_contents($root . '/view/statics/css/config-embed.css');
        self::assertStringContainsString('.w-config-embed__field {', $css);
        self::assertStringContainsString('display: grid', $css);
        self::assertStringContainsString('padding: var(--weline-space-5', $css);
        self::assertStringContainsString('overflow: visible', $css);
        self::assertStringContainsString('w-config-embed__media-path', $css);
        self::assertStringContainsString('overflow-wrap: anywhere', $css);
        self::assertStringContainsString('--weline-theme-surface', $css);
        self::assertStringContainsString('--weline-theme-text', $css);
        self::assertStringContainsString('--weline-theme-border', $css);
        self::assertStringContainsString('layout-inline', $css);
        self::assertStringNotContainsString('background: var(--weline-surface-raised, #fff)', $css);
        self::assertStringNotContainsString('background:#fff', $css);

        $renderer = (string)file_get_contents($root . '/Service/ConfigEmbedRenderer.php');
        self::assertStringContainsString('resolveFetchSource', $renderer);
        self::assertStringContainsString('fetchHtml($fetchSource', $renderer);
    }

    public function testModuleVersionIs1355(): void
    {
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        self::assertSame('1.3.55', $module['version'] ?? null);
    }

    public function testCallbackPassesExplicitScopeAttributes(): void
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
            'target_scope' => false,
            'website_code' => false,
            'store_code' => false,
            'channel_code' => false,
            'scope_kind' => false,
        ]);
        self::assertStringContainsString("'target_scope' => (string)(\$Taglib__target_scope ?? '')", $php);
        self::assertStringContainsString("'website_code' => (string)(\$Taglib__website_code ?? '')", $php);
        self::assertStringContainsString("'scope_kind' => (string)(\$Taglib__scope_kind ?? '')", $php);

        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ConfigEmbedResolver.php');
        self::assertStringContainsString('scopeInputFromAttributes', $src);
        self::assertStringContainsString('属性优先于 URL', $src);
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
