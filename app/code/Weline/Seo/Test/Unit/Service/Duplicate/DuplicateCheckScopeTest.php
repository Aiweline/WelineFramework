<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Duplicate;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Duplicate\DuplicateCheckScope;

final class DuplicateCheckScopeTest extends TestCase
{
    public function testFiltersByWebsiteEntityAndPrefix(): void
    {
        $scope = DuplicateCheckScope::fromArray([
            'website_id' => 3,
            'entity_types' => ['blog'],
            'path_prefix' => '/blog/',
            'sample_limit' => 10,
            'mode' => 'sample',
        ]);

        $rows = $scope->filterUrlRows([
            ['website_id' => 3, 'entity_type' => 'blog', 'module' => 'Weline_Blog', 'locale' => 'zh_Hans_CN', 'url' => 'https://x.test/blog/a', 'url_key' => '/blog/a'],
            ['website_id' => 3, 'entity_type' => 'product', 'module' => 'Weline_Product', 'locale' => 'zh_Hans_CN', 'url' => 'https://x.test/p/1', 'url_key' => '/p/1'],
            ['website_id' => 9, 'entity_type' => 'blog', 'module' => 'Weline_Blog', 'locale' => 'zh_Hans_CN', 'url' => 'https://x.test/blog/b', 'url_key' => '/blog/b'],
            ['website_id' => 3, 'entity_type' => 'blog', 'module' => 'Weline_Blog', 'locale' => 'zh_Hans_CN', 'url' => 'https://x.test/help/c', 'url_key' => '/help/c'],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('/blog/a', $rows[0]['url_key']);
        self::assertStringContainsString('blog', $rows[0]['_dup_bucket']);
    }
}
