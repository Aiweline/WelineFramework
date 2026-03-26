<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

final class BackendVerificationCodeGate
{
    public function shouldDisplayCaptcha(int $attemptTimes): bool
    {
        return $attemptTimes > 2;
    }

    public function canAccessCaptcha(bool $captchaRequired): bool
    {
        return $captchaRequired;
    }

    /**
     * @return array{should_display_captcha: bool, should_block: bool, error_message: ?string}
     */
    public function evaluate(int $attemptTimes, mixed $expectedCode, mixed $providedCode): array
    {
        $shouldDisplayCaptcha = $this->shouldDisplayCaptcha($attemptTimes);
        if ($attemptTimes <= 3) {
            return [
                'should_display_captcha' => $shouldDisplayCaptcha,
                'should_block' => false,
                'error_message' => null,
            ];
        }

        $expected = $this->normalizeCode($expectedCode);
        $provided = $this->normalizeCode($providedCode);

        if ($expected === '') {
            return [
                'should_display_captcha' => true,
                'should_block' => true,
                'error_message' => null,
            ];
        }

        if ($provided === '') {
            return [
                'should_display_captcha' => true,
                'should_block' => true,
                'error_message' => '请输入验证码！',
            ];
        }

        if (!hash_equals($expected, $provided)) {
            return [
                'should_display_captcha' => true,
                'should_block' => true,
                'error_message' => '验证码错误！',
            ];
        }

        return [
            'should_display_captcha' => true,
            'should_block' => false,
            'error_message' => null,
        ];
    }

    private function normalizeCode(mixed $code): string
    {
        return trim((string)($code ?? ''));
    }
}
