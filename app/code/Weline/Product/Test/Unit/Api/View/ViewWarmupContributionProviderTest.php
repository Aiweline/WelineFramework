<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Product\Api\View\ViewWarmupContributionProvider;

final class ViewWarmupContributionProviderTest extends TestCase
{
    public function testPublishesBoundedLocalizedCatalogPaths(): void
    {
        $contribution = (new ViewWarmupContributionProvider())->contribution();

        self::assertInstanceOf(ViewWarmupContribution::class, $contribution);
        self::assertSame(
            [
                '/en_US/products',
                '/zh_Hans_CN/products',
                '/ar_SA/products',
            ],
            $contribution->fpcPaths,
        );
    }
}
