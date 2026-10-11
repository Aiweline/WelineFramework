<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class BlogContentMutationFpcUrlsContractTest extends TestCase
{
    public function testPublishImpactIncludesBlogUrls(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/BlogContentMutation.php'
        );
        self::assertStringContainsString('impactUrls', $src);
        self::assertStringContainsString("'/blog/'", $src);
        self::assertStringContainsString("'previous_urls'", $src);
        self::assertStringContainsString("'urls' => \$urlImpact['urls']", $src);
    }
}
