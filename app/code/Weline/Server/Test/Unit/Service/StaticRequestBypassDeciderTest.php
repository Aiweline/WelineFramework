<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\StaticRequestBypassDecider;

class StaticRequestBypassDeciderTest extends TestCase
{
    public function testThemeModuleRequestIsDeferredToFramework(): void
    {
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'Weline/Theme/view/theme/frontend/variables/_colors.css'
            )
        );
    }

    public function testPublishedThemeStaticRequestIsDeferredToFramework(): void
    {
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'static/__preview/token_pv_6_demo/WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/theme.css'
            )
        );
    }

    public function testRegularStaticRequestStaysOnWlsFastPath(): void
    {
        self::assertFalse(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'Weline/Theme/view/statics/js/theme.js'
            )
        );
    }
}
