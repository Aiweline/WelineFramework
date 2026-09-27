<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * CDN OffCanvas 结果页必须走后台 Offcanvas 控制器，避免保存后 iframe 店面 404。
 */
final class CdnOffcanvasResultRouteContractTest extends TestCase
{
    public function testAccountFormAssignsBackendOffcanvasSuccessUrl(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/Cdn/Controller/Backend/Account.php'
        );

        self::assertStringContainsString(
            "getBackendUrl('component/backend/offcanvas/getSuccess')",
            $source
        );
        self::assertStringNotContainsString(
            "getBackendUrl('component/offcanvas/success')",
            $source
        );
    }

    public function testIframeTraitRedirectsToBackendOffcanvasResultActions(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/Cdn/Controller/Backend/CdnBackendIframeTrait.php'
        );

        self::assertStringContainsString('component/backend/offcanvas/getSuccess', $source);
        self::assertStringContainsString('component/backend/offcanvas/getError', $source);
        self::assertStringNotContainsString('/component/offcanvas/success', $source);
        self::assertStringNotContainsString('/component/offcanvas/error', $source);
    }
}
