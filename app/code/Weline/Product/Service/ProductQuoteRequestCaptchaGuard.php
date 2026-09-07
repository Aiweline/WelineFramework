<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryModulePresence;

/**
 * Optional Captcha gate for Product quote submissions.
 */
final class ProductQuoteRequestCaptchaGuard
{
    public const INTENT = 'product.quote_request.submit';
    public const FORM_ID = 'product-quote-request-form';

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
            if (function_exists('w_log_error')) {
                w_log_error(
                    'Product quote captcha render failed: ' . $throwable->getMessage(),
                    ['intent' => self::INTENT],
                    'captcha',
                );
            }

            return '';
        }
    }

    /** @param array<string, mixed> $submission */
    public function verify(array $submission, ?Request $request = null): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        try {
            $request ??= ObjectManager::getInstance(Request::class);

            return $this->manager()->verifySubmission(
                $submission,
                self::INTENT,
                $this->requestHostname($request),
                $request->clientIP(),
            );
        } catch (\Throwable $throwable) {
            if (function_exists('w_log_error')) {
                w_log_error(
                    'Product quote captcha verification failed: ' . $throwable->getMessage(),
                    ['intent' => self::INTENT],
                    'captcha',
                );
            }

            return false;
        }
    }

    private function manager(): CaptchaManagerInterface
    {
        return $this->captchaManager ??= ObjectManager::getInstance(CaptchaManagerInterface::class);
    }

    private function requestHostname(Request $request): string
    {
        $host = trim((string)(
            $request->getServer('HTTP_HOST')
            ?: $request->getServer('SERVER_NAME')
            ?: ''
        ));
        $hostname = $host === '' ? '' : parse_url('http://' . ltrim($host, '/'), PHP_URL_HOST);

        return is_string($hostname) ? strtolower(rtrim($hostname, '.')) : '';
    }
}
