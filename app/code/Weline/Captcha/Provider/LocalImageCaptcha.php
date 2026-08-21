<?php

declare(strict_types=1);

namespace Weline\Captcha\Provider;

use Weline\Captcha\Interface\VerificationProviderInterface;
use Weline\Captcha\Model\CaptchaResult;
use Weline\Captcha\Service\LocalChallengeImage;

final class LocalImageCaptcha implements VerificationProviderInterface
{
    public function __construct(private readonly CaptchaResult $results)
    {
    }

    public function code(): string
    {
        return 'local_image';
    }

    public function render(array $context): string
    {
        $answer = $this->randomCode();
        $token = \bin2hex(\random_bytes(24));
        $result = clone $this->results;
        $result->clearData()
            ->setData(CaptchaResult::schema_fields_TOKEN, $token)
            ->setData(CaptchaResult::schema_fields_CODE, \password_hash(\strtoupper($answer), PASSWORD_DEFAULT))
            ->setData(CaptchaResult::schema_fields_TYPE, $this->code())
            ->setData(CaptchaResult::schema_fields_EXPIRES_AT, \date('Y-m-d H:i:s', \time() + 300))
            ->setData(CaptchaResult::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'))
            ->save();

        $labelText = (string)__('请输入图片中的验证码');
        $image = LocalChallengeImage::inlineSvg($answer, $labelText);
        $label = \htmlspecialchars($labelText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="weline-captcha weline-captcha-local" data-weline-captcha-provider="local_image">'
            . '<input type="hidden" name="captcha_provider" value="local_image">'
            . '<input type="hidden" name="captcha_token" value="' . \htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
            . '<label class="w-field__label">' . $label . '</label>'
            . '<div class="weline-captcha-row">'
            . $image
            . '<input class="w-input" type="text" name="captcha_response" required maxlength="6" autocapitalize="characters" aria-label="' . $label . '">'
            . '</div></div>'
            . '<style>.weline-captcha{margin-top:1rem}.weline-captcha-row{display:flex;align-items:center;gap:.75rem}.weline-captcha-image{display:block;flex:0 0 168px;border:1px solid #cbd5e1;border-radius:.5rem;background:#f8fafc}.weline-captcha-row input{min-width:0;flex:1}</style>';
    }

    public function verify(array $submission, string $intent, string $hostname, ?string $ip = null): bool
    {
        $token = \trim((string)($submission['captcha_token'] ?? ''));
        $response = \strtoupper(\trim((string)($submission['captcha_response'] ?? '')));
        if ($token === '' || $response === '' || \preg_match('/\A[a-f0-9]{48}\z/D', $token) !== 1) {
            return false;
        }

        $record = clone $this->results;
        $record->clearData()->clearQuery()
            ->where(CaptchaResult::schema_fields_TOKEN, $token)
            ->where(CaptchaResult::schema_fields_TYPE, $this->code())
            ->find()
            ->fetch();
        if (!$record->getId()) {
            return false;
        }

        try {
            $expiresAt = \strtotime((string)$record->getData(CaptchaResult::schema_fields_EXPIRES_AT));
            return $expiresAt >= \time()
                && \password_verify($response, (string)$record->getData(CaptchaResult::schema_fields_CODE));
        } finally {
            // Success and failure both consume the local proof, preventing
            // brute-force and replay against the same challenge.
            $record->delete();
        }
    }

    private function randomCode(): string
    {
        // Avoid look-alike characters while keeping the challenge mixed-case
        // enough to defeat the old seven-segment digit template matching.
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($index = 0; $index < 6; $index++) {
            $code .= $alphabet[\random_int(0, \strlen($alphabet) - 1)];
        }
        return $code;
    }
}
