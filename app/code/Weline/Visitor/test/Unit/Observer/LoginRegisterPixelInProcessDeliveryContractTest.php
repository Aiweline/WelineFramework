<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/**
 * Login/Register pixel observers must deliver in-process (no Env baseUrl HTTP self-POST).
 */
final class LoginRegisterPixelInProcessDeliveryContractTest extends TestCase
{
    public function testLoginAndRegisterPixelUsePixelEventServiceTrack(): void
    {
        $login = (string) file_get_contents(dirname(__DIR__, 3) . '/Observer/LoginPixel.php');
        $register = (string) file_get_contents(dirname(__DIR__, 3) . '/Observer/RegisterPixel.php');

        self::assertStringContainsString('PixelEventService', $login);
        self::assertStringContainsString('function deliverPixel', $login);
        self::assertStringContainsString("\$pixelEventService->track([", $login);
        self::assertStringNotContainsString('curl_init', $login);
        self::assertStringNotContainsString('/visitor/rest/v1/pixel', $login);

        self::assertStringContainsString('PixelEventService', $register);
        self::assertStringContainsString('function deliverPixel', $register);
        self::assertStringContainsString("\$pixelEventService->track([", $register);
        self::assertStringNotContainsString('curl_init', $register);
        self::assertStringNotContainsString('/visitor/rest/v1/pixel', $register);
    }
}
