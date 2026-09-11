<?php

declare(strict_types=1);

namespace Weline\Captcha\Provider;

use Weline\Captcha\Interface\VerificationProviderInterface;
use Weline\Captcha\Model\CaptchaResult;
use Weline\Captcha\Service\LocalChallengeImage;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

final class LocalImageCaptcha implements VerificationProviderInterface
{
    public function __construct(private readonly CaptchaResult $results)
    {
    }

    public function code(): string
    {
        return 'local_image';
    }

    public function cspDirectives(): array
    {
        return [];
    }

    public function render(array $context): string
    {
        $answer = $this->randomCode();
        $token = \bin2hex(\random_bytes(24));
        $result = clone $this->results;
        $result->clearData()
            ->setData(CaptchaResult::schema_fields_TOKEN, $token)
            // Short-lived 6-char challenges must not use PASSWORD_DEFAULT (often bcrypt cost 10–12,
            // 100–400ms). Cost 5 keeps verify resistant enough for a 5-minute one-shot token.
            ->setData(
                CaptchaResult::schema_fields_CODE,
                \password_hash(\strtoupper($answer), \PASSWORD_BCRYPT, ['cost' => 5])
            )
            ->setData(CaptchaResult::schema_fields_TYPE, $this->code())
            ->setData(CaptchaResult::schema_fields_EXPIRES_AT, \date('Y-m-d H:i:s', \time() + 300))
            ->setData(CaptchaResult::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'))
            ->save();

        $labelText = (string)__('请输入图片中的验证码');
        $image = LocalChallengeImage::markup($answer, $labelText);
        $inputId = 'weline-captcha-response-' . \substr($token, 0, 12);

        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        return (string)$template->fetch('Weline_Captcha::templates/frontend/local-image-challenge.phtml', [
            'token' => $token,
            'image_html' => $image,
            'input_id' => $inputId,
        ]);
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
