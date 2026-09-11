<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Captcha\Observer\InjectCaptchaIntoForm;
use Weline\Framework\Event\Event;

final class InjectCaptchaIntoFormTest extends TestCase
{
    public function testMissingCaptchaModeDoesNotInjectChallenge(): void
    {
        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->expects(self::never())->method('renderChallenge');
        $observer = new InjectCaptchaIntoForm($captcha);
        $event = $this->formEvent([
            'id' => 'ordinary-post-form',
            'method' => 'post',
            'intent' => 'generic',
        ]);

        $observer->execute($event);

        self::assertSame('', $event->getData('html'));
    }

    public function testLazyModeInjectsHostWithoutRenderingChallenge(): void
    {
        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->expects(self::never())->method('renderChallenge');
        $observer = new InjectCaptchaIntoForm($captcha);
        $event = $this->formEvent([
            'id' => 'checkout-delivery-quick-add-form',
            'method' => 'post',
            'intent' => 'checkout.save_delivery_address',
            'captcha' => 'lazy',
        ]);

        $observer->execute($event);

        $html = (string)$event->getData('html');
        self::assertStringContainsString('data-weline-captcha-lazy="1"', $html);
        self::assertStringContainsString('data-challenge-route="weline_captcha/frontend/challenge"', $html);
        self::assertStringContainsString('data-intent="checkout.save_delivery_address"', $html);
        self::assertStringContainsString('data-form-id="checkout-delivery-quick-add-form"', $html);
        self::assertStringContainsString('LazyCaptchaClientRuntime', (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/InjectCaptchaIntoForm.php'
        ));
        self::assertFileExists(\dirname(__DIR__, 3) . '/view/statics/js/captcha-lazy.js');
        self::assertFileExists(\dirname(__DIR__, 3) . '/Service/LazyCaptchaClientRuntime.php');
    }

    public function testRequiredModeInjectsChallengeWithFormContext(): void
    {
        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->expects(self::never())->method('renderChallenge');
        $observer = new InjectCaptchaIntoForm($captcha);
        $event = $this->formEvent([
            'id' => 'customer-login',
            'method' => 'post',
            'intent' => 'customer.login',
            'captcha' => 'required',
        ]);

        $observer->execute($event);

        $html = (string)$event->getData('html');
        self::assertStringContainsString('data-weline-captcha-lazy="1"', $html);
        self::assertStringContainsString('data-intent="customer.login"', $html);
        self::assertStringContainsString('data-form-id="customer-login"', $html);
        self::assertStringContainsString('data-captcha-mode="required"', $html);
        self::assertStringNotContainsString('data-test-captcha', $html);
    }

    public function testRequiredModeAttachesCaptchaModuleBeforeRender(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/InjectCaptchaIntoForm.php'
        );

        self::assertStringContainsString('LazyCaptchaClientRuntime::hostMarkup', $source);
        self::assertStringNotContainsString('SharedResponseCachePolicy::forbid', $source);
        self::assertStringNotContainsString('captcha_ssr_challenge', $source);
        self::assertStringNotContainsString('renderChallenge', $source);
        self::assertStringNotContainsString("addModule('Weline_Captcha')", $source);

        $runtime = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LazyCaptchaClientRuntime.php'
        );
        self::assertStringContainsString('data-weline-captcha-lazy', $runtime);
        self::assertStringContainsString('weline_captcha/frontend/challenge', $runtime);
    }

    public function testAsyncActionFormsSkipCaptchaEvenWhenRequired(): void
    {
        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->expects(self::never())->method('renderChallenge');
        $observer = new InjectCaptchaIntoForm($captcha);
        $event = $this->formEvent([
            'id' => 'country-disable',
            'method' => 'post',
            'intent' => 'generic',
            'captcha' => 'required',
            'html_attributes' => [
                'data-async-action' => 'country-disable',
            ],
        ]);

        $observer->execute($event);

        self::assertSame('', $event->getData('html'));
    }

    public function testAutoModeOnPostStillInjectsWhenNotAsync(): void
    {
        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->expects(self::never())->method('renderChallenge');
        $observer = new InjectCaptchaIntoForm($captcha);
        $event = $this->formEvent([
            'id' => 'meta-file-form',
            'method' => 'post',
            'intent' => 'meta.file',
            'captcha' => 'auto',
        ]);

        $observer->execute($event);

        $html = (string)$event->getData('html');
        self::assertStringContainsString('data-weline-captcha-lazy="1"', $html);
        self::assertStringContainsString('data-intent="meta.file"', $html);
        self::assertStringContainsString('data-captcha-mode="auto"', $html);
        self::assertStringNotContainsString('data-test-captcha-auto', $html);
    }

    /** @param array<string, mixed> $attributes */
    private function formEvent(array $attributes): Event
    {
        return new Event([
            'data' => [
                'attributes' => $attributes,
                'html' => '',
            ],
        ]);
    }
}
