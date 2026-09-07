<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Service\ClientGeoResolver;

/**
 * Source + pure normalize contracts. Full router DI uses final ConfigReader and is
 * covered by runtime probe after server reload.
 */
final class CaptchaProviderRouterContractTest extends TestCase
{
    public function testGeoNormalizeCountryCode(): void
    {
        $resolver = (new \ReflectionClass(ClientGeoResolver::class))->newInstanceWithoutConstructor();

        self::assertSame('CN', $resolver->normalizeCountryCode('cn'));
        self::assertSame('US', $resolver->normalizeCountryCode(' US '));
        self::assertSame(ClientGeoResolver::UNKNOWN, $resolver->normalizeCountryCode('T1'));
        self::assertSame(ClientGeoResolver::UNKNOWN, $resolver->normalizeCountryCode(''));
        self::assertSame(ClientGeoResolver::UNKNOWN, $resolver->normalizeCountryCode('USA'));
    }

    public function testRouterAndConfigSourcesExist(): void
    {
        $root = \dirname(__DIR__, 3);
        self::assertFileExists($root . '/Service/CaptchaProviderRouter.php');
        self::assertFileExists($root . '/Service/ClientGeoResolver.php');
        self::assertFileExists($root . '/Provider/TencentCaptcha.php');
        self::assertFileExists($root . '/extends/module/Weline_SystemConfig/Config/backend/captcha.phtml');

        $router = (string)\file_get_contents($root . '/Service/CaptchaProviderRouter.php');
        $configUi = (string)\file_get_contents($root . '/extends/module/Weline_SystemConfig/Config/backend/captcha.phtml');
        $captchaConfig = (string)\file_get_contents($root . '/Service/CaptchaConfig.php');
        $manager = (string)\file_get_contents($root . '/Service/CaptchaManager.php');
        $lazy = (string)\file_get_contents($root . '/view/statics/js/captcha-lazy.js');

        self::assertStringContainsString('tencent_captcha', $router);
        self::assertStringContainsString('countryOverrides', $captchaConfig);
        self::assertStringContainsString('ConfigReader', $captchaConfig);
        self::assertStringContainsString('private readonly ConfigReader $config', $captchaConfig);
        self::assertStringContainsString('captcha/routing/country_overrides', $configUi);
        self::assertStringContainsString('captcha/tencent/app_id', $configUi);
        self::assertStringContainsString('allowLocalDegrade', $manager);
        self::assertStringContainsString('weline:captcha:degrade', $lazy);
        self::assertStringContainsString('prefer', $lazy);
        self::assertStringContainsString('isInsideClosedDetails', $lazy);
        self::assertStringContainsString('FETCH_TIMEOUT_MS', $lazy);
        self::assertStringContainsString('inflightByUrl', $lazy);
    }
}
