<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Admin\Service\BackendVerificationCodeGate;

final class BackendVerificationCodeGateTest extends TestCase
{
    private BackendVerificationCodeGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new BackendVerificationCodeGate();
    }

    public function testCaptchaImageAccessRequiresCaptchaMode(): void
    {
        self::assertFalse($this->gate->canAccessCaptcha(false));
    }

    public function testCaptchaImageAccessIsAllowedOnceCaptchaModeIsActive(): void
    {
        self::assertTrue($this->gate->canAccessCaptcha(true));
    }

    public function testDoesNotBlockBeforeCaptchaIsMandatory(): void
    {
        $result = $this->gate->evaluate(3, null, '');

        self::assertTrue($result['should_display_captcha']);
        self::assertFalse($result['should_block']);
        self::assertNull($result['error_message']);
    }

    public function testBlocksWithoutErrorMessageWhenCaptchaSessionHasNotBeenGeneratedYet(): void
    {
        $result = $this->gate->evaluate(4, null, '');

        self::assertTrue($result['should_display_captcha']);
        self::assertTrue($result['should_block']);
        self::assertNull($result['error_message']);
    }

    public function testRequiresInputOnceCaptchaSessionExists(): void
    {
        $result = $this->gate->evaluate(4, '123456', '');

        self::assertTrue($result['should_display_captcha']);
        self::assertTrue($result['should_block']);
        self::assertSame('请输入验证码！', $result['error_message']);
    }

    public function testRejectsIncorrectCaptcha(): void
    {
        $result = $this->gate->evaluate(4, '123456', '654321');

        self::assertTrue($result['should_display_captcha']);
        self::assertTrue($result['should_block']);
        self::assertSame('验证码错误！', $result['error_message']);
    }

    public function testAllowsCorrectCaptcha(): void
    {
        $result = $this->gate->evaluate(4, '123456', '123456');

        self::assertTrue($result['should_display_captcha']);
        self::assertFalse($result['should_block']);
        self::assertNull($result['error_message']);
    }
}
