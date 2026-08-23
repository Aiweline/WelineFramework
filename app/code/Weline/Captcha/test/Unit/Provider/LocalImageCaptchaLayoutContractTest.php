<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Provider\LocalImageCaptcha;

final class LocalImageCaptchaLayoutContractTest extends TestCase
{
    public function testRenderStylesAlignImageWithInput(): void
    {
        $path = dirname(__DIR__, 3) . '/Provider/LocalImageCaptcha.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('weline-captcha-row', $content);
        self::assertStringContainsString('align-items:stretch', $content);
        self::assertStringContainsString('height:var(--weline-control-height', $content);
        self::assertStringContainsString('w-field__label', $content);
        self::assertStringContainsString('for="', $content);
    }
}
