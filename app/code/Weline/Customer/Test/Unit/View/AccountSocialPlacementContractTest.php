<?php
declare(strict_types=1);
namespace Weline\Customer\Test\Unit\View;
use PHPUnit\Framework\TestCase;
final class AccountSocialPlacementContractTest extends TestCase
{
    public function testNativeSocialFormHasOnlyLayoutPlacement(): void
    {
        $entries = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $widget = $entries['account-social-login'];
        self::assertSame('layout', $widget['placement']);
        self::assertSame([], $widget['default_injections']);
        self::assertTrue($widget['params']['enable_google']['default']);
        self::assertTrue($widget['params']['enable_facebook']['default']);
        self::assertTrue($widget['params']['enable_instagram']['default']);
    }
}
