<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\SystemConfigResourceChangePublisher;

final class SystemConfigResourceChangePublisherTest extends TestCase
{
    public function testAggregateStorefrontDimensionsFollowModuleAndWebsiteScope(): void
    {
        $publisher = ObjectManager::getInstance(SystemConfigResourceChangePublisher::class);
        $method = new \ReflectionMethod($publisher, 'impactNamespaces');

        $theme = $method->invoke(
            $publisher,
            'Weline_Theme',
            'shop_a.default.default',
            'theme|frontend|shop_a.default.default|default',
        );
        self::assertContains('global/storefront/config', $theme);
        self::assertContains('global/storefront/theme', $theme);
        self::assertContains('website/shop_a/config', $theme);
        self::assertContains('website/shop_a/theme', $theme);

        $currency = $method->invoke(
            $publisher,
            'Weline_Currency',
            'default.default.default',
            'currency|frontend|default.default.default|default',
        );
        self::assertContains('global/storefront/config', $currency);
        self::assertContains('global/storefront/price', $currency);
        self::assertNotContains('website/default/price', $currency);
    }
}
