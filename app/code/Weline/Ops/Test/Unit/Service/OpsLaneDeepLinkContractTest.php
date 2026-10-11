<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class OpsLaneDeepLinkContractTest extends TestCase
{
    public function test_helper_and_lanes_pass_website_scope(): void
    {
        $helper = dirname(__DIR__, 3) . '/Service/OpsLaneDeepLink.php';
        self::assertFileExists($helper);
        $helperSrc = (string)file_get_contents($helper);
        self::assertStringContainsString("'website_id'", $helperSrc);
        self::assertStringContainsString('scope_key', $helperSrc);
        self::assertStringContainsString('websiteId', $helperSrc);
        self::assertStringContainsString('weline_social/backend/social', $helperSrc);
        self::assertStringNotContainsString("'*/backend/social'", $helperSrc);
        self::assertStringContainsString('visitor/backend/pixel-dashboard', $helperSrc);
        self::assertStringNotContainsString('pixel-dashboard/index', $helperSrc);

        $contentLane = dirname(__DIR__, 3) . '/extends/module/Weline_Ops/Lane/ContentLane.php';
        $contentSrc = (string)file_get_contents($contentLane);
        self::assertStringContainsString('blog/backend/post-admin/index', $contentSrc);
        self::assertStringNotContainsString('blog/backend/post/index', $contentSrc);

        $lanes = [
            dirname(__DIR__, 3) . '/extends/module/Weline_Ops/Lane/SiteLane.php',
            $contentLane,
            dirname(__DIR__, 4) . '/Seo/extends/module/Weline_Ops/Lane/SeoLane.php',
            dirname(__DIR__, 4) . '/Social/extends/module/Weline_Ops/Lane/SocialLane.php',
            dirname(__DIR__, 4) . '/Visitor/extends/module/Weline_Ops/Lane/VisitorLane.php',
            dirname(__DIR__, 4) . '/Marketing/extends/module/Weline_Ops/Lane/GrowthLane.php',
            dirname(__DIR__, 4) . '/Promotion/extends/module/Weline_Ops/Lane/PromotionLane.php',
        ];
        foreach ($lanes as $path) {
            self::assertFileExists($path, $path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('OpsLaneDeepLink', $src, $path);
            self::assertStringNotContainsString(
                "getBackendUrl('seo/backend/account/index')",
                $src,
                $path . ' must not drop website scope',
            );
        }
    }
}
