<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Sample\Hanfu1688\VerifiedSourceManifest;

final class VerifiedSourceManifestTest extends TestCase
{
    public function testEveryLiveBrandHasExactlyOneTerminalOutcome(): void
    {
        $baseline = $this->baseline();
        $document = (new VerifiedSourceManifest())->seed(
            0,
            $baseline['brands'],
            $baseline['suppliers'],
            $baseline['links'],
        );
        $document['brand_outcomes'] = [
            ['brand_code' => 'zhl', 'outcome' => 'duplicate_alias', 'canonical_brand_code' => 'zuihuanlou'],
            ['brand_code' => 'zuihuanlou', 'outcome' => 'verified'],
        ];
        $document['aliases'] = ['zhl' => 'zuihuanlou'];
        $document['verified_sources'] = [[
            'source_code' => 'zuihuanlou-shop',
            'brand_code' => 'zuihuanlou',
            'supplier_code' => 'zhl-shop',
            'shop_url' => 'https://shop.example.1688.com',
            'factory_url' => 'https://www.1688.com/factory/b2b-123.html',
            'company_name' => '曹县醉欢楼服饰有限公司',
            'evidence_urls' => ['https://www.1688.com/factory/b2b-123.html'],
        ]];

        $validated = (new VerifiedSourceManifest())->validate(0, $document, $baseline);

        self::assertSame('hanfu.1688.sources.v1', $validated['contract']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $validated['source_digest']);
    }

    public function testMissingLiveBrandFailsClosed(): void
    {
        $baseline = $this->baseline();
        $document = (new VerifiedSourceManifest())->seed(
            0,
            $baseline['brands'],
            $baseline['suppliers'],
            $baseline['links'],
        );
        $document['brand_outcomes'] = [
            ['brand_code' => 'zuihuanlou', 'outcome' => 'unmatched'],
        ];

        $this->expectExceptionMessage('hanfu_1688_brand_coverage_incomplete');
        (new VerifiedSourceManifest())->validate(0, $document, $baseline);
    }

    /** @return array{brands:list<array<string,mixed>>,suppliers:list<array<string,mixed>>,links:list<array<string,mixed>>} */
    private function baseline(): array
    {
        return [
            'brands' => [
                ['brand_id' => 10, 'code' => 'zhl', 'name' => '醉欢楼', 'status' => 'active'],
                ['brand_id' => 11, 'code' => 'zuihuanlou', 'name' => '醉欢楼', 'status' => 'active'],
            ],
            'suppliers' => [
                ['supplier_id' => 20, 'code' => 'zhl-shop', 'name' => '醉欢楼店铺', 'status' => 'active'],
            ],
            'links' => [
                ['brand_id' => 10, 'supplier_id' => 20],
                ['brand_id' => 11, 'supplier_id' => 20],
            ],
        ];
    }
}
