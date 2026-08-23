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
        $image = LocalChallengeImage::markup($answer, $labelText);
        $label = \htmlspecialchars($labelText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inputId = 'weline-captcha-response-' . \substr($token, 0, 12);

        return '<div class="weline-captcha weline-captcha-local" data-weline-captcha-provider="local_image">'
            . '<input type="hidden" name="captcha_provider" value="local_image">'
            . '<input type="hidden" name="captcha_token" value="' . \htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="w-field">'
            . '<label class="w-field__label" for="' . \htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '">' . $label . '</label>'
            . '<div class="weline-captcha-row">'
            . $image
            . '<span class="weline-captcha-field">'
            . '<input class="w-input" id="' . \htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '" type="text" name="captcha_response" required maxlength="6" autocapitalize="characters" autocomplete="off" spellcheck="false" inputmode="text" aria-label="' . $label . '" placeholder="······">'
            . '</span>'
            . '</div></div></div>'
            . '<style>'
            . '.weline-captcha{margin-block:var(--weline-space-4,1rem);max-width:100%}'
            . '.weline-captcha .w-field{margin:0}'
            . '.weline-captcha-row{display:flex;align-items:stretch;gap:var(--weline-space-3,.75rem);max-width:100%;flex-wrap:nowrap}'
            . '.weline-captcha-image{display:block;flex:0 0 auto;width:168px;height:var(--weline-control-height,2.5rem);object-fit:cover;border:1px solid var(--weline-theme-border,#cbd5e1);border-radius:var(--weline-radius-md,.5rem);background:var(--weline-theme-surface,#f8fafc);box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 28%,transparent)}'
            . '.weline-captcha-field{display:flex;flex:1 1 8rem;min-width:7rem;max-width:10rem;align-items:stretch}'
            . '.weline-captcha-field .w-input{width:100%!important;max-width:100%!important;min-width:0;height:100%;min-height:var(--weline-control-height,2.5rem);box-sizing:border-box;letter-spacing:.28em;text-align:center;text-transform:uppercase;font-weight:650}'
            . '@media (max-width:22rem){.weline-captcha-row{flex-wrap:wrap}.weline-captcha-image{width:100%;max-width:100%;height:2.75rem}.weline-captcha-field{max-width:100%;flex-basis:100%}}'
            . '</style>';
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
