<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Product\Api\View\ViewWarmupContributionProvider;

final class ViewWarmupContributionProviderTest extends TestCase
{
    public function testPublishesUnprefixedCatalogFirst(): void
    {
        $contribution = (new ViewWarmupContributionProvider())->contribution();

        self::assertInstanceOf(ViewWarmupContribution::class, $contribution);
        self::assertNotSame([], $contribution->fpcPaths);
        self::assertSame('/products', $contribution->fpcPaths[0]);
        self::assertLessThanOrEqual(4, \count($contribution->fpcPaths));

        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Api/View/ViewWarmupContributionProvider.php'
        );
        self::assertStringContainsString('defaultLanguage', $source);
        self::assertStringContainsString('/products', $source);
        self::assertStringContainsString('resolveWebsiteDefaultLanguage', $source);
    }
}
