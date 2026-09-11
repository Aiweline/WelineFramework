<?php

declare(strict_types=1);

namespace Weline\CustomerService\Service;

use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Captcha\Service\CaptchaConfig;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryModulePresence;

final class BindCaptchaGuard
{
    public const INTENT = 'customerservice_bind_email';
    public const FORM_ID = 'cs-bind-form';

    private ?CaptchaManagerInterface $captchaManager = null;
    private ?CaptchaConfig $captchaConfig = null;
    private string $lastFailureDetail = '';

    public function isEnabled(): bool
    {
        return RegistryModulePresence::isActivePresent('Weline_Captcha');
    }

    public function lastFailureDetail(): string
    {
        return $this->lastFailureDetail;
    }

    public function allowsLocalDegrade(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            return $this->config()->allowLocalDegrade();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether this verify failure should switch the UI to local_image.
     * Plain BROWSER_ERROR is retriable; empty token props means Google key/domain
     * never bound the challenge (not a bot-score reject) → offer local degrade.
     */
    public function shouldOfferLocalDegrade(string $provider): bool
    {
        $provider = \strtolower(\trim($provider));
        if ($provider === 'local_image' || !$this->allowsLocalDegrade()) {
            return false;
        }
        $detail = \strtolower($this->lastFailureDetail);
        if ($detail !== '' && \str_contains($detail, 'browser_error')) {
            return \str_contains($detail, 'empty_token_props');
        }

        return true;
    }

    /** @param array{prefer?: string} $options */
    public function renderChallenge(array $options = []): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $prefer = \strtolower(\trim((string)($options['prefer'] ?? '')));
        if ($prefer !== 'local_image') {
            $prefer = '';
        }

        try {
            $context = [
                'form_id' => self::FORM_ID,
                'intent' => self::INTENT,
                'required' => true,
            ];
            if ($prefer !== '') {
                $context['prefer'] = $prefer;
            }

            return $this->manager()->renderChallenge($context);
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
        $this->lastFailureDetail = '';
        if (!$this->isEnabled()) {
            return true;
        }

        try {
            $manager = $this->manager();
            $ok = $manager->verifySubmission(
                $submission,
                self::INTENT,
                $this->requestHostname($request),
                $request->clientIP(),
            );
            if (!$ok && $manager instanceof \Weline\Captcha\Service\CaptchaManager) {
                $this->lastFailureDetail = $manager->getLastFailureDetail();
            }

            return $ok;
        } catch (\Throwable $throwable) {
            $this->lastFailureDetail = $throwable->getMessage();
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

    private function config(): CaptchaConfig
    {
        return $this->captchaConfig ??= ObjectManager::getInstance(CaptchaConfig::class);
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
