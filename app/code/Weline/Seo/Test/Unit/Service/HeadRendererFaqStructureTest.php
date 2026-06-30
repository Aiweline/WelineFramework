<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\HeadRenderer;
use Weline\Seo\Service\Head\PageSeoContextResolver;

class HeadRendererFaqStructureTest extends TestCase
{
    public function testRendersFaqPageGraphFromNormalizedFaqs(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'faq',
            'site_name' => 'Shop',
            'title' => '常见问题',
            'description' => '帮助中心常见问题。',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/faq',
            'url' => 'https://shop.test/faq',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/'],
            'faqs' => [
                ['question' => '如何下单？', 'answer' => '加入购物车后结账。'],
            ],
        ]);

        $html = (new HeadRenderer($resolver))->render(new FaqHeadTemplateStub());

        self::assertStringContainsString('"@type": "FAQPage"', $html);
        self::assertStringContainsString('"@id": "https://shop.test/faq#faq"', $html);
        self::assertStringContainsString('"name": "如何下单？"', $html);
        self::assertStringContainsString('"text": "加入购物车后结账。"', $html);
        self::assertStringContainsString('"mainEntity": {', $html);
    }

    public function testRendersQaPageGraphFromQaList(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'qa_page',
            'site_name' => 'Shop',
            'title' => '商品问答',
            'description' => '商品问答列表。',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/qa/1',
            'url' => 'https://shop.test/qa/1',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/'],
            'qa_list' => [
                ['question' => '是否包邮？', 'answer' => '满额包邮。'],
            ],
            'faqs' => [
                ['question' => '是否包邮？', 'answer' => '满额包邮。'],
            ],
        ]);

        $html = (new HeadRenderer($resolver))->render(new FaqHeadTemplateStub());

        self::assertStringContainsString('"@type": "QAPage"', $html);
        self::assertStringContainsString('"@type": "FAQPage"', $html);
    }
}

final class FaqHeadTemplateStub
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}
