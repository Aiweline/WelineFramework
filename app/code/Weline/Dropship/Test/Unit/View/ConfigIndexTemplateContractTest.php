<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 契约：货源配置页 embed fields 必须与 Extends 声明 key 字面一致。
 */
final class ConfigIndexTemplateContractTest extends TestCase
{
    public function testConfigEmbedFieldsMatchDeclaredKeys(): void
    {
        $root = dirname(__DIR__, 3);
        $page = $root . '/view/templates/Backend/Config/index.phtml';
        $declared = $root . '/extends/module/Weline_SystemConfig/Config/frontend/dropship-settings.phtml';
        self::assertFileExists($page);
        self::assertFileExists($declared);

        $pageTpl = (string)file_get_contents($page);
        $declTpl = (string)file_get_contents($declared);

        self::assertStringContainsString('data-dropship-admin="config"', $pageTpl);
        self::assertStringContainsString('w-stack', $pageTpl);
        self::assertStringContainsString('<w:scope', $pageTpl);
        self::assertStringContainsString('selected_scope', $pageTpl);
        self::assertStringContainsString('weline_systemconfig/backend/config', $pageTpl);

        self::assertMatchesRegularExpression(
            '/<w:config:embed[^>]*fields="([^"]+)"/',
            $pageTpl,
            'Config page must embed declared fields'
        );
        preg_match('/<w:config:embed[^>]*fields="([^"]+)"/', $pageTpl, $m);
        $fields = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($m[1] ?? '')))));
        self::assertNotEmpty($fields);

        foreach ($fields as $key) {
            self::assertStringContainsString(
                'key="' . $key . '"',
                $declTpl,
                'embed field must be declared literally: ' . $key
            );
        }

        self::assertStringNotContainsString('fields="platforms_enabled', $pageTpl);
        self::assertStringNotContainsString('price_uplift_percent', $pageTpl);
        self::assertStringNotContainsString('启用的分销平台', $declTpl);

        self::assertMatchesRegularExpression(
            '/key="dropship\\/platforms\\/enabled"[^>]*type="multiselect"/',
            $declTpl,
            'platforms enabled must be multiselect tags'
        );
        self::assertStringContainsString('options="fake:Fake 货源,cj:CJ Dropshipping"', $declTpl);
        self::assertStringNotContainsString('逗号分隔已注册 provider_code', $declTpl);

        self::assertStringContainsString('dropship/ops/follow_enabled', implode(',', $fields));
        self::assertMatchesRegularExpression(
            '/key="dropship\/ops\/follow_enabled"[^>]*label="启用库存\/价格跟随"/',
            $declTpl,
            'follow switch must be labeled for stock/price sync'
        );
        self::assertStringContainsString('同步远程库存与价格', $declTpl);
    }
}
