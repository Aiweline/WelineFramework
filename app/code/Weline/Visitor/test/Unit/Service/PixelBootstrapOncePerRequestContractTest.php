<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Visitor\Service\PixelBootstrapHtmlService;

/**
 * P7：header storefront-pixel-bootstrap 与 body-end 不得各嵌一份 ~40KB visitorTrackingConfig。
 */
final class PixelBootstrapOncePerRequestContractTest extends TestCase
{
    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        RequestContext::remove(PixelBootstrapHtmlService::EMITTED_REQUEST_KEY);
    }

    protected function tearDown(): void
    {
        RequestContext::remove(PixelBootstrapHtmlService::EMITTED_REQUEST_KEY);
        Context::leave();
        parent::tearDown();
    }

    public function testEmittedFlagApiAndBodyEndSkipsDuplicateConfig(): void
    {
        self::assertFalse(PixelBootstrapHtmlService::wasEmittedThisRequest());
        PixelBootstrapHtmlService::markEmittedThisRequest();
        self::assertTrue(PixelBootstrapHtmlService::wasEmittedThisRequest());

        $serviceSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PixelBootstrapHtmlService.php'
        );
        self::assertStringContainsString('EMITTED_REQUEST_KEY', $serviceSrc);
        self::assertStringContainsString('wasEmittedThisRequest', $serviceSrc);
        self::assertStringContainsString('markEmittedThisRequest', $serviceSrc);

        $bodyEnd = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );
        self::assertStringContainsString('wasEmittedThisRequest()', $bodyEnd);
        self::assertStringContainsString('$__header_pixel_emitted', $bodyEnd);
        self::assertStringContainsString('data-weline-pixel-bootstrap=', $bodyEnd);
        self::assertStringContainsString('CTX_LAYOUT_TYPE', $bodyEnd);
        self::assertStringContainsString('homepage', $bodyEnd);
        self::assertStringNotContainsString(
            "if (self::wasEmittedThisRequest()) {\n            return '';",
            (string)file_get_contents(dirname(__DIR__, 3) . '/Service/PixelBootstrapHtmlService.php'),
            'early empty return poisons chrome live include after cached peek mark'
        );

        $chromeSrc = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/Service/LayoutEntity/ThemeLayoutEntityChrome.php'
        );
        self::assertStringContainsString('noteVisitorPixelBootstrapIfPresent', $chromeSrc);
    }
}
