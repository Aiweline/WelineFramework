<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

use Weline\Captcha\Api\CaptchaManagerInterface;

final class CaptchaManager implements CaptchaManagerInterface
{
    public function __construct(
        private readonly CaptchaConfig $config,
        private readonly CaptchaProviderRegistry $providers,
    ) {
    }

    public function renderChallenge(array $context): string
    {
        return $this->requireProvider($this->activeProviderCode())->render($context);
    }

    public function verifySubmission(array $submission, string $intent, string $hostname, ?string $ip = null): bool
    {
        $expectedProvider = $this->activeProviderCode();
        $submittedProvider = \strtolower(\trim((string)($submission['captcha_provider'] ?? '')));
        if ($submittedProvider !== $expectedProvider) {
            return false;
        }

        try {
            return $this->requireProvider($expectedProvider)->verify($submission, $intent, $hostname, $ip);
        } catch (\Throwable $exception) {
            \w_log_error(
                'Captcha verification rejected: ' . $exception->getMessage(),
                ['provider' => $expectedProvider, 'intent' => $intent],
                'captcha'
            );
            return false;
        }
    }

    private function activeProviderCode(): string
    {
        return $this->config->googleEnabled() && $this->config->isGoogleReady()
            ? 'google_enterprise'
            : 'local_image';
    }

    private function requireProvider(string $code): \Weline\Captcha\Interface\VerificationProviderInterface
    {
        $provider = $this->providers->get($code);
        if ($provider === null) {
            throw new \RuntimeException((string)__('验证码提供者未注册：%{1}', [$code]));
        }
        return $provider;
    }
}
