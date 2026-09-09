<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Duplicate;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Duplicate\ContentDuplicateScanner;
use Weline\Seo\Service\Duplicate\DuplicateCheckScope;

final class ContentDuplicateScannerTextScanTest extends TestCase
{
    public function testScanTextsFindsDuplicateWithinBucket(): void
    {
        $text = \str_repeat('站内重复检测正文样例，用于验证 MinHash Jaccard 流水线。', 15);
        $scanner = new ContentDuplicateScanner();
        $scope = DuplicateCheckScope::fromArray([
            'website_id' => 1,
            'notify' => false,
        ]);
        $result = $scanner->scanTexts([
            ['id' => 1, 'text' => $text, 'entity_type' => 'blog', 'locale' => 'zh'],
            ['id' => 2, 'text' => $text, 'entity_type' => 'blog', 'locale' => 'zh'],
            ['id' => 3, 'text' => $text, 'entity_type' => 'product', 'locale' => 'zh'],
        ], $scope);

        self::assertGreaterThanOrEqual(1, $result['stats']['duplicate']);
        self::assertCount(1, $result['pairs']);
        self::assertSame(1, (int)$result['pairs'][0]['id_a']);
        self::assertSame(2, (int)$result['pairs'][0]['id_b']);
    }
}
