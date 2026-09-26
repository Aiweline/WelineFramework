<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Website/global publish must clear descendant Scope is_published markers
 * so storefront ThemePublishedVersionRuntimeResolver does not keep showing
 * a stale store-level name (e.g. 「原始布局」) after publishing 「test」.
 */
final class ThemePublishClearsDescendantPublishedContractTest extends TestCase
{
    public function testMarkPublishedVersionClearsDescendantPublishedFlags(): void
    {
        $service = $this->read('app/code/Weline/Theme/Service/ThemeLayoutVersionService.php');

        self::assertStringContainsString('unsetPublishedDescendantVersions(', $service);
        self::assertStringContainsString('isDescendantStorageScope(', $service);
        self::assertStringContainsString('ThemeLayoutVersion::schema_fields_ID', $service);
        self::assertStringNotContainsString('schema_fields_VERSION_ID', $service);
        self::assertStringContainsString('clone $this->versionModel', $service);

        $markPos = strpos($service, 'private function markPublishedVersion(');
        self::assertNotFalse($markPos);
        $markBody = substr($service, $markPos, 900);
        self::assertStringContainsString('unsetPublishedVersion(', $markBody);
        self::assertStringContainsString('unsetPublishedDescendantVersions(', $markBody);

        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $publishPos = strpos($editor, 'async function publishTheme()');
        self::assertNotFalse($publishPos);

        // 「发布请求带当前版本 id」这条契约仍成立，但机制已经搬家：publishTheme() 现在是薄包装
        // （委托给 publishThemeWithScope()），请求体由共享构造器 buildScopeVersionPayload()
        // 统一组装并把 state.currentVersionId 写进 theme_version_id。
        // 原先钉的是 publishTheme() 内联字面量 `version_id: state.currentVersionId`，
        // 该字面量在 HEAD 上已不存在（本次核对：两个 JS 副本各 0 处），断言因此长期恒红。
        // 改为断言真实链路：publishTheme → requestStandardLayoutPublish → buildScopeVersionPayload。
        $requestPos = strpos($editor, 'async function requestStandardLayoutPublish(');
        self::assertNotFalse($requestPos, 'requestStandardLayoutPublish must exist');
        $requestBody = substr($editor, $requestPos, 900);
        self::assertStringContainsString('buildScopeVersionPayload(', $requestBody);

        $builderPos = strpos($editor, 'function buildScopeVersionPayload(');
        self::assertNotFalse($builderPos, 'buildScopeVersionPayload must exist');
        $builderBody = substr($editor, $builderPos, 900);
        self::assertStringContainsString('state.currentVersionId', $builderBody);
        self::assertStringContainsString('theme_version_id:', $builderBody);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
