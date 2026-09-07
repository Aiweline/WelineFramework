<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Observer\SocialLoginConfigResourceChanged;
use Weline\Customer\Service\SocialLogin\SocialLoginStorefrontCacheInvalidator;
use Weline\Framework\Event\ResourceChange\ResourceChange;

final class SocialLoginConfigResourceChangedContractTest extends TestCase
{
    public function testObserverAndInvalidatorAreWiredForResourceChanged(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/Observer/SocialLoginConfigResourceChanged.php');
        self::assertFileExists($root . '/Service/SocialLogin/SocialLoginStorefrontCacheInvalidator.php');
        self::assertFileExists($root . '/doc/event/social_login_config_resource_changed.md');

        $eventXml = (string) file_get_contents($root . '/etc/event.xml');
        self::assertStringContainsString('Weline_Framework::resource_changed', $eventXml);
        self::assertStringContainsString('SocialLoginConfigResourceChanged', $eventXml);

        // Ownership of resource_changed stays in Framework event.php; Customer only observes via event.xml.
        $eventPhp = (string) file_get_contents($root . '/event.php');
        self::assertStringNotContainsString('Weline_Framework::resource_changed', $eventPhp);
        self::assertFileExists($root . '/doc/event/social_login_config_resource_changed.md');

        $observerSrc = (string) file_get_contents($root . '/Observer/SocialLoginConfigResourceChanged.php');
        self::assertStringContainsString('customer/social_login/', $observerSrc);
        self::assertStringContainsString('Weline_Customer', $observerSrc);
        self::assertStringContainsString('clearForSocialLoginConfig', $observerSrc);

        $invalidatorSrc = (string) file_get_contents(
            $root . '/Service/SocialLogin/SocialLoginStorefrontCacheInvalidator.php'
        );
        self::assertStringContainsString('FullPageCacheCoordinator::clearProcessCache', $invalidatorSrc);
        self::assertStringContainsString('clearStaticHookCaches', $invalidatorSrc);

        self::assertTrue(class_exists(SocialLoginConfigResourceChanged::class));
        self::assertTrue(class_exists(SocialLoginStorefrontCacheInvalidator::class));
        self::assertSame(ResourceChange::EVENT_NAME, 'Weline_Framework::resource_changed');
    }
}
