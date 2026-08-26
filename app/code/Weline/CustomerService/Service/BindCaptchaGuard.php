<?php

declare(strict_types=1);

namespace Weline\CustomerService\Service;

use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryModulePresence;

final class BindCaptchaGuard
{
    public const INTENT = 'customerservice.bind_email';
    public const FORM_ID = 'cs-bind-form';

    private ?CaptchaManagerInterface $captchaManager = null;

    public function isEnabled(): bool
    {
        return RegistryModulePresence::isActivePresent('Weline_Captcha');
    }

    public function renderChallenge(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        try {
            return $this->manager()->renderChallenge([
                'form_id' => self::FORM_ID,
                'intent' => self::INTENT,
                'required' => true,
            ]);
        } catch (\Throwable $throwable) {
            \w_log_error(
                'CustomerService bind captcha render failed: ' . $throwable->getMessage(),
                ['intent' => self::INTENT],
                'captcha'
            );

            return '';
        }
    }

    /** @param array<string, mixed> $submission */
    public function verify(array $submission, Request $request): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        try {
            return $this->manager()->verifySubmission(
                $submission,
                self::INTENT,
                $this->requestHostname($request),
                $request->clientIP(),
            );
        } catch (\Throwable $throwable) {
            \w_log_error(
                'CustomerService bind captcha verification failed: ' . $throwable->getMessage(),
                ['intent' => self::INTENT],
                'captcha'
            );

            return false;
        }
    }

    private function manager(): CaptchaManagerInterface
    {
        return $this->captchaManager ??= ObjectManager::getInstance(CaptchaManagerInterface::class);
    }

    private function requestHostname(Request $request): string
    {
        $host = \trim((string)(
            $request->getServer('HTTP_HOST')
            ?: $request->getServer('SERVER_NAME')
            ?: ''
        ));
        $hostname = $host === '' ? '' : \parse_url('http://' . \ltrim($host, '/'), PHP_URL_HOST);

        return \is_string($hostname) ? \strtolower(\rtrim($hostname, '.')) : '';
    }
}
