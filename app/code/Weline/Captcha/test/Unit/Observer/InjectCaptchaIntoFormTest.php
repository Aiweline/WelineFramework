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

    public function testRequiredModeInjectsChallengeWithFormContext(): void
    {
        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->expects(self::once())
            ->method('renderChallenge')
            ->with([
                'form_id' => 'customer-login',
                'intent' => 'customer.login',
                'required' => true,
            ])
            ->willReturn('<div data-test-captcha></div>');
        $observer = new InjectCaptchaIntoForm($captcha);
        $event = $this->formEvent([
            'id' => 'customer-login',
            'method' => 'post',
            'intent' => 'customer.login',
            'captcha' => 'required',
        ]);

        $observer->execute($event);

        self::assertSame('<div data-test-captcha></div>', $event->getData('html'));
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
        $captcha->expects(self::once())
            ->method('renderChallenge')
            ->willReturn('<div data-test-captcha-auto></div>');
        $observer = new InjectCaptchaIntoForm($captcha);
        $event = $this->formEvent([
            'id' => 'meta-file-form',
            'method' => 'post',
            'intent' => 'meta.file',
            'captcha' => 'auto',
        ]);

        $observer->execute($event);

        self::assertSame('<div data-test-captcha-auto></div>', $event->getData('html'));
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
