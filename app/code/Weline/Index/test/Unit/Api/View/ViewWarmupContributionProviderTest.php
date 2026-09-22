<?php

declare(strict_types=1);

namespace Weline\Index\Test\Unit\Api\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Index\Api\View\ViewWarmupContributionProvider;

final class ViewWarmupContributionProviderTest extends TestCase
{
    public function testOmitsDefaultLocaleHomepagePrefixesViaCanonicalize(): void
    {
        $contribution = (new ViewWarmupContributionProvider())->contribution();

        self::assertInstanceOf(ViewWarmupContribution::class, $contribution);
        self::assertLessThanOrEqual(4, \count($contribution->fpcPaths));

        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Api/View/ViewWarmupContributionProvider.php'
        );
        self::assertStringContainsString('nonDefaultLocaleHomepages', $source);
        self::assertStringContainsString('defaultLanguage', $source);
        self::assertStringContainsString('resolveWebsiteDefaultLanguage', $source);
    }
}
