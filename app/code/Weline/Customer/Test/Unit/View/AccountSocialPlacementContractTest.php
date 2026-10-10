<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSocialPlacementContractTest extends TestCase
{
    public function testNativeSocialFormHasOnlyLayoutPlacement(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $tpl = 'Weline_Customer::templates/frontend/widgets/account-social-login.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/account-social-login.phtml');
        self::assertStringContainsString('@widget.code {account-social-login}', $src);
        self::assertStringContainsString('@widget.placement {layout}', $src);
        self::assertStringContainsString('@widget.default_injections {[]}', $src);
        self::assertStringContainsString('@param enable_google {default=true', $src);
        self::assertStringContainsString('@param enable_facebook {default=true', $src);
        self::assertStringContainsString('@param enable_instagram {default=true', $src);
    }
}
