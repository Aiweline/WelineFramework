<?php

declare(strict_types=1);
namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Service\SystemConfigTemplateService;

final class ThemeResourceFilesConfigTest extends TestCase
{
    public function testDeclaredControlsUseThreeStatesAndIndependentPositiveThresholds(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/frontend/resource-files.phtml';
        self::assertFileExists($path);
        $parser = new \ReflectionMethod(SystemConfigTemplateService::class, 'parseConfigTags');
        $parsed = $parser->invoke(new SystemConfigTemplateService(), (string)file_get_contents($path));
        $fields = array_column($parsed['fields'], null, 'key');
        self::assertCount(6, $fields);
        $backend = str_replace('/frontend/', '/backend/', $path);
        $backendParsed = $parser->invoke(new SystemConfigTemplateService(), (string)file_get_contents($backend));
        self::assertSame($parsed, $backendParsed, 'Both areas must share identical field contracts.');
        foreach (['css_minify', 'js_minify', 'css_merge', 'js_merge'] as $name) {
            $field = $fields['resource_files/' . $name];
            self::assertSame('select', $field['type']);
            self::assertSame('auto', $field['default']);
            self::assertSame(['auto', 'on', 'off'], array_map(static fn ($item) => explode(':', $item)[0], explode(',', $field['options'])));
            self::assertSame('global,website,store,channel', $field['scope']);
        }
        foreach (['css_merge_start', 'js_merge_start'] as $name) {
            $field = $fields['resource_files/' . $name];
            self::assertSame('number', $field['type']);
            self::assertSame('int', $field['value-type']);
            self::assertSame('6', $field['default']);
            self::assertSame('min:1', $field['validation']);
        }
    }

    public function testDrawerUsesUnifiedScopedEmbedAndExplainsEnvironmentAndAutosave(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/index.phtml');
        self::assertStringContainsString('id="themeResourceFilesConfig"', $source);
        self::assertStringContainsString('group="theme_resource_files"', $source);
        self::assertStringContainsString('module="Weline_Theme" area="editor_area"', $source);
        self::assertStringContainsString('target_scope="resourceConfigScope"', $source);
        self::assertStringContainsString('locale="default"', $source);
        self::assertStringContainsString('当前生产环境', $source);
        self::assertStringContainsString('当前开发环境', $source);
        self::assertStringContainsString('修改后自动保存', $source);
    }

    public function testDrawerKeepsBrandActionsInDefaultPanelAndScopeOutsideTabs(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/index.phtml');
        $start = strpos($source, '<aside id="themeBrandBasicsDrawer"');
        $drawer = substr($source, $start, strpos($source, '</aside>', $start) - $start + 8);
        $dom = new \DOMDocument();
        @$dom->loadHTML($drawer);
        $xpath = new \DOMXPath($dom);
        self::assertSame(1, $xpath->query('//*[@id="themeBrandBaseTab" and @aria-selected="true"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="themeBrandResourcesPanel" and @hidden]')->length);
        self::assertSame(2, $xpath->query('//*[@id="themeBrandBasePanel"]//*[@data-w-brand-action]')->length);
        self::assertSame(4, $xpath->query('//*[@id="themeBrandBasePanel"]//*[@data-w-brand-field]')->length);
        self::assertSame(0, $xpath->query('//*[@id="themeBrandResourcesPanel"]//*[@data-w-brand-action]')->length);
        self::assertSame(0, $xpath->query('//*[@id="themeBrandBasicsTabs"]//*[@id="themeBrandScopeBadge"]')->length);
    }

    public function testEmbedScopeSegmentsPreserveEachTypedIdentityWithoutDefaultChildren(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/index.phtml');
        $start = strpos($source, '$resourceConfigKind =');
        $code = substr($source, $start, strpos($source, '$scope_identity_json =', $start) - $start);
        $resolver = (new \ReflectionClass(\Weline\SystemConfig\Service\ConfigEmbedResolver::class))->newInstanceWithoutConstructor();
        $identities = [
            \Weline\Framework\Runtime\ScopeIdentity::global(),
            \Weline\Framework\Runtime\ScopeIdentity::website(0, 'default'),
            \Weline\Framework\Runtime\ScopeIdentity::store(0, 'default', 'shop', 'normal'),
            \Weline\Framework\Runtime\ScopeIdentity::channel(0, 'default', 'shop', 'web', 'normal'),
        ];
        foreach ($identities as $identity) {
            $scopeIdentity = $identity->toArray();
            $selected_scope = 'default.default.default';
            eval($code);
            self::assertSame($identity->scopeKind, $resourceConfigKind);
            self::assertSame($identity->websiteCode ?? '', $resourceConfigWebsite);
            self::assertSame($identity->storeCode ?? '', $resourceConfigStore);
            self::assertSame($identity->channelCode ?? '', $resourceConfigChannel);
            $input = $resolver->scopeInputFromAttributes([
                'target_scope' => $resourceConfigScope, 'scope_kind' => $resourceConfigKind,
                'website_code' => $resourceConfigWebsite, 'store_code' => $resourceConfigStore,
                'channel_code' => $resourceConfigChannel,
            ]);
            self::assertSame($identity->scopeKind, $input['scope_kind']);
            self::assertSame($identity->storeCode ?? '', $input['store_code'] ?? '');
            self::assertSame($identity->channelCode ?? '', $input['channel_code'] ?? '');
        }
        self::assertStringContainsString('scope_kind="resourceConfigKind"', $source);
    }
}
