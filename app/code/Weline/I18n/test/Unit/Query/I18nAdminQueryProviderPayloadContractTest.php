<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class I18nAdminQueryProviderPayloadContractTest extends TestCase
{
    public function testProviderNormalizesBracketPayloadBeforeControllerInvoke(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php',
        );

        self::assertStringContainsString('normalizeActionPayload', $source);
        self::assertStringContainsString('normalizeScalarPayloadKeys', $source);
        self::assertStringContainsString('AdminControllerBridge::invoke', $source);
        self::assertStringNotContainsString('assertBackendSession', $source);
        self::assertStringNotContainsString("'taglib-local-save',", $source);
        self::assertStringContainsString('parse_str', $source);
        self::assertStringContainsString('http_build_query', $source);
    }

    public function testBracketPayloadParsesIntoNestedDescriptionArray(): void
    {
        $payload = [
            'model' => 'Weline\\Eav\\Model\\EavAttribute\\Group\\LocalDescription',
            'description[zh_Hans_CN][local_code]' => 'zh_Hans_CN',
            'description[zh_Hans_CN][group_id]' => '1',
            'description[zh_Hans_CN][name]' => '默认属性组',
        ];

        $normalized = [];
        parse_str(http_build_query($payload, '', '&', PHP_QUERY_RFC3986), $normalized);

        self::assertIsArray($normalized['description'] ?? null);
        self::assertSame('zh_Hans_CN', $normalized['description']['zh_Hans_CN']['local_code'] ?? null);
        self::assertSame('1', $normalized['description']['zh_Hans_CN']['group_id'] ?? null);
        self::assertSame('默认属性组', $normalized['description']['zh_Hans_CN']['name'] ?? null);
    }

    public function testDuplicateScalarPayloadKeysCoerceToLastScalar(): void
    {
        $payload = [
            'model' => [
                'Weline\\Eav\\Model\\EavAttribute\\Set\\LocalDescription',
                'Weline\\Eav\\Model\\EavAttribute\\Set\\LocalDescription',
            ],
            'id' => ['4', '4'],
            'field' => ['name', 'name'],
        ];

        $normalized = $payload;
        foreach (['model', 'field', 'id', 'value', 'isIframe', 'action'] as $key) {
            if (!array_key_exists($key, $normalized) || !is_array($normalized[$key])) {
                continue;
            }
            $resolved = '';
            foreach ($normalized[$key] as $candidate) {
                if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                    $resolved = trim((string)$candidate);
                }
            }
            $normalized[$key] = $resolved;
        }

        self::assertSame('Weline\\Eav\\Model\\EavAttribute\\Set\\LocalDescription', $normalized['model']);
        self::assertSame('4', $normalized['id']);
        self::assertSame('name', $normalized['field']);
    }
}
