<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

use Weline\Captcha\Api\CaptchaManagerInterface;

final class CaptchaManager implements CaptchaManagerInterface
{
    public function __construct(
        private readonly CaptchaConfig $config,
        private readonly CaptchaProviderRegistry $providers,
        private readonly CaptchaProviderRouter $router,
    ) {
    }

    public function renderChallenge(array $context): string
    {
        $server = \is_array($context['server'] ?? null) ? $context['server'] : ($_SERVER ?? []);
        $options = [];
        $prefer = \strtolower(\trim((string)($context['prefer'] ?? '')));
        if ($prefer !== '') {
            $options['prefer'] = $prefer;
        }
        $code = $this->router->resolve($server, $options);
        return $this->requireProvider($code)->render($context);
    }

    public function verifySubmission(array $submission, string $intent, string $hostname, ?string $ip = null): bool
    {
        $server = \is_array($submission['_server'] ?? null) ? $submission['_server'] : ($_SERVER ?? []);
        $preferred = $this->router->resolve($server);
        $submittedProvider = \strtolower(\trim((string)($submission['captcha_provider'] ?? '')));

        if ($submittedProvider === '') {
            return false;
        }

        $allowed = $submittedProvider === $preferred
            || (
                $this->config->allowLocalDegrade()
                && $submittedProvider === CaptchaProviderRouter::LOCAL
            );

        if (!$allowed) {
            return false;
        }

        try {
            return $this->requireProvider($submittedProvider)->verify($submission, $intent, $hostname, $ip);
        } catch (\Throwable $exception) {
            \w_log_error(
                'Captcha verification rejected: ' . $exception->getMessage(),
                ['provider' => $submittedProvider, 'preferred' => $preferred, 'intent' => $intent],
                'captcha'
            );
            return false;
        }
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
