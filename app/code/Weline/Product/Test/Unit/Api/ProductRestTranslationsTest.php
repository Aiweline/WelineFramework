<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Service\ProductRestInput;
use Weline\Product\Service\ProductRestTranslations;

final class ProductRestTranslationsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // 语言合法性来自真实框架目录，定向运行时也需要应用引导。
        if (!defined('BP')) {
            require dirname(__DIR__, 7) . '/app/bootstrap.php';
        }
    }

    protected function setUp(): void
    {
        self::assertTrue(class_exists(ProductRestTranslations::class), '产品多语言 REST 保存尚未实现');
    }

    public function testCreateSeedsFallbackOnceAndKeepsEachTranslation(): void
    {
        $body = ['website_id' => 0, 'payload' => ['name' => '中文名称', 'sku' => 'I18N-001', 'store_ids' => []],
            'translations' => ['en-US' => ['name' => 'English name']]];
        $original = ProductRestInput::command(ProductAdminCommand::ACTION_CREATE, $body, 7);
        $command = (new ProductRestTranslations())->prepareCommand($original, $body, 'zh_Hans_CN');
        self::assertSame($original->requestHash, $command->requestHash);
        self::assertSame('中文名称', $command->payload['name']);
        self::assertSame('', $command->payload['locale']);
        self::assertSame('中文名称', $command->payload['translations']['zh_Hans_CN']['name']);
        self::assertSame('English name', $command->payload['translations']['en_US']['name']);
        self::assertSame([], $command->payload['store_ids']);
    }

    public function testEditingOneLanguagePreservesSharedFieldsAndDoesNotWriteFallback(): void
    {
        $body = ['website_id' => 0, 'global_product_uuid' => '12345678-1234-4234-8234-123456789abc',
            'payload' => ['local_version' => 2, 'name' => 'New English name', 'inventory' => [['offer_id' => 12, 'qty' => 3]]]];
        $command = (new ProductRestTranslations())->prepareCommand(
            ProductRestInput::command(ProductAdminCommand::ACTION_SAVE, $body, 7), $body, 'en_US',
        );
        self::assertArrayNotHasKey('name', $command->payload);
        self::assertArrayNotHasKey('short_description', $command->payload['translations']['en_US']);
        self::assertSame(['en_US'], array_keys($command->payload['translations']));
        self::assertSame($body['payload']['inventory'], $command->payload['inventory']);
        self::assertSame(2, $command->payload['local_version']);
    }

    public function testAutomaticTranslationUsesSubmittedFieldsAndKeepsManualTranslation(): void
    {
        $calls = [];
        $service = new ProductRestTranslations(function (array $texts, string $target, string $source) use (&$calls): array {
            $calls[] = [$texts, $target, $source];
            return array_map(static fn(string $text): string => 'Translated: ' . $text, $texts);
        });
        $body = ['website_id' => 0, 'payload' => ['name' => '衬衫', 'short_description' => '柔软面料', 'description' => ''],
            'translations' => ['en_US' => ['name' => 'Handwritten shirt name']], 'translate_to' => ['en_US']];
        $command = $service->prepareCommand(ProductRestInput::command(ProductAdminCommand::ACTION_CREATE, $body, 7), $body, 'zh_Hans_CN');
        self::assertSame([[['柔软面料'], 'en_US', 'zh_Hans_CN']], $calls);
        self::assertSame('Handwritten shirt name', $command->payload['translations']['en_US']['name']);
        self::assertSame('Translated: 柔软面料', $command->payload['translations']['en_US']['short_description']);
        self::assertSame('', $command->payload['translations']['en_US']['description']);
    }

    public function testLocalizedReadUsesExactLanguageAndHonorsExplicitEmptyValue(): void
    {
        $attributes = [];
        foreach ([['', 'name', 'Base name'], ['zh_Hans_CN', 'name', '中文名称'], ['en_US', 'name', 'English name'],
            ['', 'description', 'Base description'], ['en_US', 'description', '']] as [$locale, $code, $value]) {
            $attributes[] = ['entity_type' => 'product', 'entity_id' => 1, 'store_id' => 0,
                'locale' => $locale, 'attribute_code' => $code, 'value' => $value, 'scope_state' => 'explicit'];
        }
        $snapshot = ['product' => ['product_id' => 1], 'attributes' => $attributes];
        $result = ProductRestTranslations::localizeSnapshot($snapshot, 'en_US');
        self::assertSame('English name', $result['content']['name']);
        self::assertSame('', $result['content']['description']);
        self::assertSame('中文名称', $result['translations']['zh_Hans_CN']['name']);
        self::assertSame($attributes, $result['attributes']);
    }

    public function testAutomaticTranslationRetainsStoreFromSourceTranslationGroup(): void
    {
        $service = new ProductRestTranslations(static fn(array $texts, string $target, string $source): array => ['Store translation']);
        $body = ['website_id' => 0, 'payload' => ['sku' => 'I18N-STORE-001', 'store_ids' => []],
            'translations' => ['zh_Hans_CN' => ['name' => '门店商品', 'store_id' => 3]],
            'translate_to' => ['en_US']];
        $command = $service->prepareCommand(
            ProductRestInput::command(ProductAdminCommand::ACTION_CREATE, $body, 7), $body, 'zh_Hans_CN',
        );
        self::assertSame(3, $command->payload['translations']['zh_Hans_CN']['store_id']);
        self::assertSame(3, $command->payload['translations']['en_US']['store_id']);
        self::assertSame('Store translation', $command->payload['translations']['en_US']['name']);
    }

    public function testBrowserLocaleAliasPreservesLanguagePreference(): void
    {
        self::assertSame('zh_Hans_CN', ProductRestTranslations::normalizeLocale('zh-CN'));
        self::assertSame('zh_Hans_CN', ProductRestTranslations::normalizeLocale('zh_cn'));
        self::assertSame('en_US', ProductRestTranslations::normalizeLocale('en-US'));
        self::assertSame('zh_Hans_CN', ProductRestTranslations::negotiateLocale('zh-CN,zh;q=0.9', 'en_US'));
        self::assertSame('en_US', ProductRestTranslations::negotiateLocale('zh-CN;q=0,en-US;q=0.8', 'zh_Hans_CN'));
        self::assertSame('en_US', ProductRestTranslations::negotiateLocale('xx-YY,en-US;q=0.8', 'zh_Hans_CN'));
    }

    public function testUnsupportedLocaleNeverFallsBackToAnotherLanguageOrRegion(): void
    {
        foreach (['xx_YY', 'zh_XX', 'zh_Hant_CN', 'en'] as $locale) {
            try {
                ProductRestTranslations::normalizeLocale($locale);
                self::fail('不支持的语言不能回退为另一语言：' . $locale);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('product_api_locale_unsupported', $exception->getMessage());
            }
        }
    }

    public function testDemoBatchEditCarriesCurrentLocale(): void
    {
        $descriptor = \Weline\Product\Service\ProductApiDemoDescriptor::describe();
        $actions = array_column($descriptor['actions'], null, 'id');
        self::assertSame(
            ['$field' => 'locale'],
            $actions['edit_translations']['steps'][0]['request']['locale'] ?? null,
        );
    }
}
