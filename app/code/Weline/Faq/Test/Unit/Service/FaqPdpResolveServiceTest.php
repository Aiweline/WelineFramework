<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Faq\Model\FaqItem;
use Weline\Faq\Service\FaqPdpResolveService;
use Weline\Faq\Service\FaqTemplatePacks;
use Weline\Faq\Service\TemplateFaqTypeProvider;

final class FaqPdpResolveServiceTest extends TestCase
{
    private FaqPdpResolveService $svc;

    protected function setUp(): void
    {
        $this->svc = new FaqPdpResolveService();
    }

    public function testCascadeStoreOverridesWebsiteAndChannelWins(): void
    {
        $rows = [
            $this->row('shipping', '网站运费', 0, '', '', 10),
            $this->row('shipping', '店铺运费', 1, 'main', '', 10),
            $this->row('shipping', '渠道运费', 1, 'main', 'app', 10),
            $this->row('returns', '退换', 0, '', '', 20),
        ];
        $resolved = $this->svc->cascadeRows($rows, 1, 'main', 'app');
        $byKey = [];
        foreach ($resolved as $item) {
            $byKey[$item['faq_key']] = $item;
        }
        self::assertSame('渠道运费', $byKey['shipping']['question']);
        self::assertSame(FaqPdpResolveService::SOURCE_CHANNEL, $byKey['shipping']['source']);
        self::assertSame('退换', $byKey['returns']['question']);
        self::assertSame(FaqPdpResolveService::SOURCE_WEBSITE, $byKey['returns']['source']);
    }

    public function testDisabledAtStoreSuppressesParentKey(): void
    {
        $rows = [
            $this->row('shipping', '网站运费', 0, '', '', 10, FaqItem::STATUS_ENABLED),
            $this->row('shipping', '隐藏', 1, 'main', '', 10, FaqItem::STATUS_DISABLED),
            $this->row('returns', '退换', 0, '', '', 20),
        ];
        $resolved = $this->svc->cascadeRows($rows, 1, 'main', '');
        $keys = array_column($resolved, 'faq_key');
        self::assertNotContains('shipping', $keys);
        self::assertContains('returns', $keys);
    }

    public function testMergeOnAppendsProductAfterDefaults(): void
    {
        $defaults = [
            ['faq_key' => 'shipping', 'question' => '运费', 'sort_order' => 1, 'source' => 'website'],
        ];
        $products = [
            ['faq_key' => 'material', 'question' => '材质', 'sort_order' => 1, 'source' => 'product'],
        ];
        $merged = $this->svc->mergeSets($defaults, $products, true);
        self::assertCount(2, $merged);
        self::assertSame('shipping', $merged[0]['faq_key']);
        self::assertSame('material', $merged[1]['faq_key']);
    }

    public function testMergeOffUsesProductWhenPresentElseDefaults(): void
    {
        $defaults = [['faq_key' => 'shipping', 'question' => '运费']];
        $products = [['faq_key' => 'material', 'question' => '材质']];
        self::assertSame('material', $this->svc->mergeSets($defaults, $products, false)[0]['faq_key']);
        self::assertSame('shipping', $this->svc->mergeSets($defaults, [], false)[0]['faq_key']);
    }

    public function testTemplatePacksAndProviderContract(): void
    {
        self::assertSame(['retail', 'cross_border', 'virtual', 'b2b'], FaqTemplatePacks::codes());
        self::assertTrue(FaqTemplatePacks::isValid('retail'));
        self::assertSame('retail', FaqTemplatePacks::normalize('nope'));
        $provider = new TemplateFaqTypeProvider();
        self::assertSame('template', $provider->typeCode());
        self::assertSame('retail', $provider->resolveEntity('retail')['entity_uuid'] ?? null);
        self::assertNull($provider->resolveEntity('unknown'));

        $registry = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FaqTypeRegistry.php');
        self::assertStringContainsString('TemplateFaqTypeProvider', $registry);
    }

    public function testCascadePrefersMatchingLocaleOverEmptyFallback(): void
    {
        $rows = [
            $this->row('shipping', '配送多久能到？', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'zh_Hans_CN'),
            $this->row('shipping', 'How long does delivery take?', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'en_US'),
            $this->row('returns', '如何退换货？', 0, '', '', 20, FaqItem::STATUS_ENABLED, 'zh_Hans_CN'),
            $this->row('returns', 'How do I return or exchange an item?', 0, '', '', 20, FaqItem::STATUS_ENABLED, 'en_US'),
        ];
        $en = $this->svc->cascadeRows($rows, 0, '', '', 'en_US');
        $zh = $this->svc->cascadeRows($rows, 0, '', '', 'zh_Hans_CN');
        self::assertSame('How long does delivery take?', $en[0]['question'] ?? null);
        self::assertSame('How do I return or exchange an item?', $en[1]['question'] ?? null);
        self::assertSame('配送多久能到？', $zh[0]['question'] ?? null);
        self::assertSame('如何退换货？', $zh[1]['question'] ?? null);
    }

    public function testCascadeFallsBackAlongLocaleChainWhenExactLocaleMissing(): void
    {
        $rows = [
            $this->row('shipping', '配送多久能到？', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'zh_Hans_CN'),
            $this->row('shipping', 'How long does delivery take?', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'en_US'),
            $this->row('returns', '如何退换货？', 0, '', '', 20, FaqItem::STATUS_ENABLED, 'zh_Hans_CN'),
            $this->row('returns', 'How do I return or exchange an item?', 0, '', '', 20, FaqItem::STATUS_ENABLED, 'en_US'),
        ];
        // hi_IN → en_US → website default; prefer maintained English over Chinese.
        $hi = $this->svc->cascadeRows($rows, 0, '', '', 'hi_IN');
        self::assertSame('How long does delivery take?', $hi[0]['question'] ?? null);
        self::assertSame('How do I return or exchange an item?', $hi[1]['question'] ?? null);

        // en_US missing → legacy empty-locale last resort (website default may already be en_US).
        $emptyLegacy = [
            $this->row('shipping', '配送多久能到？', 0, '', '', 10, FaqItem::STATUS_ENABLED, ''),
        ];
        $enFallback = $this->svc->cascadeRows($emptyLegacy, 0, '', '', 'en_US');
        self::assertSame('配送多久能到？', $enFallback[0]['question'] ?? null);
    }

    public function testCascadePrefersExactHindiOverEnglishFallback(): void
    {
        $rows = [
            $this->row('shipping', 'डिलीवरी में कितना समय लगता है?', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'hi_IN'),
            $this->row('shipping', 'How long does delivery take?', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'en_US'),
        ];
        $hi = $this->svc->cascadeRows($rows, 0, '', '', 'hi_IN');
        self::assertSame('डिलीवरी में कितना समय लगता है?', $hi[0]['question'] ?? null);
    }

    public function testCascadePrefersEnglishFallbackOverChineseForNonChineseLocale(): void
    {
        $rows = [
            $this->row('shipping', '配送多久能到？', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'zh_Hans_CN'),
            $this->row('shipping', 'How long does delivery take?', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'en_US'),
        ];
        $ar = $this->svc->cascadeRows($rows, 0, '', '', 'ar_SA');
        self::assertSame('How long does delivery take?', $ar[0]['question'] ?? null);
    }

    public function testCascadePrefersExactArabicOverEnglishFallback(): void
    {
        $rows = [
            $this->row('shipping', 'كم يستغرق التوصيل؟', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'ar_SA'),
            $this->row('shipping', 'How long does delivery take?', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'en_US'),
        ];
        $ar = $this->svc->cascadeRows($rows, 0, '', '', 'ar_SA');
        self::assertSame('كم يستغرق التوصيل؟', $ar[0]['question'] ?? null);
    }

    public function testMatchingLocaleBeatsWebsiteEmptyLocaleInheritance(): void
    {
        $rows = [
            $this->row('shipping', '站点中文', 1, '', '', 10, FaqItem::STATUS_ENABLED, ''),
            $this->row('shipping', 'How long does delivery take?', 0, '', '', 10, FaqItem::STATUS_ENABLED, 'en_US'),
        ];
        $en = $this->svc->cascadeRows($rows, 1, '', '', 'en_US');
        self::assertSame('How long does delivery take?', $en[0]['question'] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(
        string $key,
        string $question,
        int $websiteId,
        string $store,
        string $channel,
        int $sort,
        string $status = FaqItem::STATUS_ENABLED,
        string $locale = '',
    ): array {
        return [
            'faq_id' => crc32($key . $websiteId . $store . $channel . $locale) & 0xffff,
            'faq_key' => $key,
            'question' => $question,
            'answer' => $question . '答',
            'website_id' => $websiteId,
            'store_code' => $store,
            'channel_code' => $channel,
            'locale_code' => $locale,
            'sort_order' => $sort,
            'status' => $status,
        ];
    }
}
